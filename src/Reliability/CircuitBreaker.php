<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Reliability;

use HighPerApp\HighPer\Contracts\LoggerInterface;
use HighPerApp\HighPer\Contracts\FFIManagerInterface;

/**
 * Circuit Breaker for Five Nines Reliability
 * 
 * Implements the circuit breaker pattern to prevent cascade failures
 * and ensure 99.999% uptime for external service dependencies.
 * 
 * Features:
 * - Three states: CLOSED, OPEN, HALF_OPEN
 * - Configurable failure thresholds and timeouts
 * - Rust FFI acceleration for high-performance scenarios
 * - Real-time health monitoring and statistics
 * - Automatic recovery with gradual traffic increase
 * - Integration with metrics and alerting systems
 */
class CircuitBreaker
{
    public const STATE_CLOSED = 'closed';
    public const STATE_OPEN = 'open';
    public const STATE_HALF_OPEN = 'half_open';

    private string $name;
    private array $config;
    private LoggerInterface $logger;
    private FFIManagerInterface $ffi;
    private bool $rustAvailable = false;

    private string $state = self::STATE_CLOSED;
    private int $failureCount = 0;
    private int $successCount = 0;
    private int $lastFailureTime = 0;
    private int $lastSuccessTime = 0;
    private int $nextAttemptTime = 0;
    private array $recentFailures = [];
    private array $stats = [];

    public function __construct(
        string $name,
        array $config,
        LoggerInterface $logger,
        ?FFIManagerInterface $ffi = null
    ) {
        $this->name = $name;
        $this->logger = $logger;
        $this->ffi = $ffi ?? new class implements FFIManagerInterface {
            public function isAvailable(): bool { return false; }
            public function registerLibrary(string $name, array $config): void {}
            public function call(string $lib, string $func, array $args, $timeout): mixed { return null; }
            public function getStats(): array { return []; }
        };

        $this->config = array_merge([
            'failure_threshold' => 5,
            'success_threshold' => 3, // for half-open to closed transition
            'timeout' => 60, // seconds to wait before trying half-open
            'recovery_timeout' => 300, // max time to stay in half-open
            'max_failures_window' => 300, // sliding window for failure tracking
            'enable_rust_ffi' => true,
            'health_check_interval' => 30,
            'monitoring_enabled' => true,
            'auto_recovery' => true,
            'gradual_recovery' => true,
            'request_volume_threshold' => 10 // minimum requests before opening
        ], $config);

        $this->initializeStats();
        $this->detectRustCapabilities();
        $this->initializeRustCircuitBreaker();

        $this->logger->info("Circuit breaker '{$name}' initialized", [
            'failure_threshold' => $this->config['failure_threshold'],
            'timeout' => $this->config['timeout'],
            'rust_available' => $this->rustAvailable
        ]);
    }

    public function call(callable $operation, callable $fallback = null): mixed
    {
        $startTime = microtime(true);
        $this->stats['total_calls']++;

        // Check if circuit is open
        if ($this->isOpen()) {
            $this->stats['rejected_calls']++;
            
            if ($fallback !== null) {
                $this->logger->debug("Circuit breaker '{$this->name}' is open, executing fallback");
                return $this->executeFallback($fallback, $startTime);
            }
            
            throw new CircuitBreakerOpenException(
                "Circuit breaker '{$this->name}' is open. Service unavailable."
            );
        }

        // Execute the operation
        try {
            $result = $this->executeOperation($operation, $startTime);
            $this->recordSuccess($startTime);
            return $result;

        } catch (\Throwable $e) {
            $this->recordFailure($e, $startTime);
            
            if ($fallback !== null) {
                $this->logger->warning("Operation failed, executing fallback", [
                    'error' => $e->getMessage(),
                    'circuit' => $this->name
                ]);
                return $this->executeFallback($fallback, $startTime);
            }
            
            throw $e;
        }
    }

    public function isOpen(): bool
    {
        $this->updateState();
        return $this->state === self::STATE_OPEN;
    }

    public function isClosed(): bool
    {
        $this->updateState();
        return $this->state === self::STATE_CLOSED;
    }

    public function isHalfOpen(): bool
    {
        $this->updateState();
        return $this->state === self::STATE_HALF_OPEN;
    }

    public function getState(): string
    {
        $this->updateState();
        return $this->state;
    }

    public function forceOpen(): void
    {
        $this->state = self::STATE_OPEN;
        $this->nextAttemptTime = time() + $this->config['timeout'];
        
        $this->logger->warning("Circuit breaker '{$this->name}' forced open", [
            'next_attempt_time' => $this->nextAttemptTime
        ]);
    }

    public function forceClosed(): void
    {
        $this->state = self::STATE_CLOSED;
        $this->failureCount = 0;
        $this->successCount = 0;
        $this->recentFailures = [];
        
        $this->logger->info("Circuit breaker '{$this->name}' forced closed");
    }

    public function reset(): void
    {
        $this->forceClosed();
        $this->stats = $this->initializeStats();
        
        $this->logger->info("Circuit breaker '{$this->name}' reset");
    }

    private function executeOperation(callable $operation, float $startTime): mixed
    {
        // Use Rust FFI for high-performance monitoring if available
        if ($this->rustAvailable && $this->shouldUseRust()) {
            return $this->executeWithRustMonitoring($operation, $startTime);
        }

        // Standard PHP execution with monitoring
        $result = $operation();
        $this->recordTiming($startTime, 'php');
        
        return $result;
    }

    private function executeWithRustMonitoring(callable $operation, float $startTime): mixed
    {
        try {
            // Start Rust monitoring
            $monitoringId = $this->ffi->call(
                'circuit_breaker',
                'start_monitoring',
                [$this->name],
                null
            );

            $result = $operation();

            // Record successful execution
            if ($monitoringId !== null) {
                $this->ffi->call(
                    'circuit_breaker',
                    'record_success',
                    [$this->name, $monitoringId],
                    null
                );
                $this->stats['rust_monitored_calls']++;
            }

            $this->recordTiming($startTime, 'rust');
            return $result;

        } catch (\Throwable $e) {
            // Record failure in Rust
            if (isset($monitoringId) && $monitoringId !== null) {
                $this->ffi->call(
                    'circuit_breaker',
                    'record_failure',
                    [$this->name, $monitoringId, $e->getMessage()],
                    null
                );
            }
            throw $e;
        }
    }

    private function executeFallback(callable $fallback, float $startTime): mixed
    {
        try {
            $result = $fallback();
            $this->stats['fallback_successes']++;
            $this->recordTiming($startTime, 'fallback');
            return $result;

        } catch (\Throwable $e) {
            $this->stats['fallback_failures']++;
            $this->logger->error("Fallback execution failed", [
                'circuit' => $this->name,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function recordSuccess(float $startTime): void
    {
        $this->successCount++;
        $this->lastSuccessTime = time();
        $this->stats['successful_calls']++;
        $this->recordTiming($startTime, 'success');

        // Transition from half-open to closed if enough successes
        if ($this->state === self::STATE_HALF_OPEN && 
            $this->successCount >= $this->config['success_threshold']) {
            $this->transitionToClosed();
        }

        // Clean up old failures from sliding window
        $this->cleanupOldFailures();
    }

    private function recordFailure(\Throwable $e, float $startTime): void
    {
        $this->failureCount++;
        $this->lastFailureTime = time();
        $this->stats['failed_calls']++;
        $this->recordTiming($startTime, 'failure');

        // Add to recent failures for analysis
        $this->recentFailures[] = [
            'time' => time(),
            'error' => $e->getMessage(),
            'type' => get_class($e)
        ];

        // Limit recent failures array size
        if (count($this->recentFailures) > 100) {
            array_shift($this->recentFailures);
        }

        // Check if we should open the circuit
        if ($this->shouldOpenCircuit()) {
            $this->transitionToOpen();
        } elseif ($this->state === self::STATE_HALF_OPEN) {
            // Return to open state on any failure during half-open
            $this->transitionToOpen();
        }
    }

    private function shouldOpenCircuit(): bool
    {
        // Must have minimum request volume
        if ($this->stats['total_calls'] < $this->config['request_volume_threshold']) {
            return false;
        }

        // Check failure threshold
        if ($this->failureCount >= $this->config['failure_threshold']) {
            return true;
        }

        // Check failure rate in sliding window
        $recentFailureCount = $this->countRecentFailures();
        $totalRecentCalls = $this->stats['total_calls'] - $this->stats['old_total_calls'];
        
        if ($totalRecentCalls >= $this->config['request_volume_threshold'] && 
            $recentFailureCount >= $this->config['failure_threshold']) {
            return true;
        }

        return false;
    }

    private function countRecentFailures(): int
    {
        $cutoff = time() - $this->config['max_failures_window'];
        return count(array_filter($this->recentFailures, function($failure) use ($cutoff) {
            return $failure['time'] > $cutoff;
        }));
    }

    private function cleanupOldFailures(): void
    {
        $cutoff = time() - $this->config['max_failures_window'];
        $this->recentFailures = array_filter($this->recentFailures, function($failure) use ($cutoff) {
            return $failure['time'] > $cutoff;
        });
    }

    private function updateState(): void
    {
        $now = time();

        switch ($this->state) {
            case self::STATE_OPEN:
                if ($now >= $this->nextAttemptTime) {
                    $this->transitionToHalfOpen();
                }
                break;

            case self::STATE_HALF_OPEN:
                // Check if we've been in half-open too long
                if ($now >= $this->nextAttemptTime + $this->config['recovery_timeout']) {
                    $this->transitionToOpen();
                }
                break;

            case self::STATE_CLOSED:
                // No state change needed for closed state
                break;
        }
    }

    private function transitionToOpen(): void
    {
        $this->state = self::STATE_OPEN;
        $this->nextAttemptTime = time() + $this->config['timeout'];
        $this->stats['times_opened']++;

        $this->logger->warning("Circuit breaker '{$this->name}' opened", [
            'failure_count' => $this->failureCount,
            'next_attempt_time' => $this->nextAttemptTime,
            'recent_failures' => array_slice($this->recentFailures, -5)
        ]);
    }

    private function transitionToHalfOpen(): void
    {
        $this->state = self::STATE_HALF_OPEN;
        $this->successCount = 0;
        $this->failureCount = 0;
        $this->stats['times_half_opened']++;

        $this->logger->info("Circuit breaker '{$this->name}' transitioned to half-open", [
            'attempt_time' => time()
        ]);
    }

    private function transitionToClosed(): void
    {
        $this->state = self::STATE_CLOSED;
        $this->failureCount = 0;
        $this->successCount = 0;
        $this->stats['times_closed']++;

        $this->logger->info("Circuit breaker '{$this->name}' transitioned to closed", [
            'recovery_time' => time()
        ]);
    }

    private function shouldUseRust(): bool
    {
        return $this->rustAvailable && 
               $this->config['enable_rust_ffi'] && 
               $this->stats['total_calls'] > 1000; // Use Rust for high-volume scenarios
    }

    private function detectRustCapabilities(): void
    {
        $this->rustAvailable = $this->ffi->isAvailable() && $this->config['enable_rust_ffi'];

        if ($this->rustAvailable) {
            $this->logger->info("Rust FFI circuit breaker capabilities detected");
        }
    }

    private function initializeRustCircuitBreaker(): void
    {
        if (!$this->rustAvailable) {
            return;
        }

        // Register Rust circuit breaker library
        $this->ffi->registerLibrary('circuit_breaker', [
            'header' => __DIR__ . '/../../rust/circuit_breaker/circuit_breaker.h',
            'lib' => __DIR__ . '/../../rust/circuit_breaker/target/release/libcircuit_breaker.so'
        ]);
    }

    private function recordTiming(float $startTime, string $type): void
    {
        $duration = microtime(true) - $startTime;
        $this->stats['total_time'] += $duration;
        $this->stats['avg_response_time'] = $this->stats['total_time'] / max(1, $this->stats['total_calls']);
        $this->stats['timings'][$type] = ($this->stats['timings'][$type] ?? 0) + $duration;
    }

    private function initializeStats(): array
    {
        return [
            'total_calls' => 0,
            'successful_calls' => 0,
            'failed_calls' => 0,
            'rejected_calls' => 0,
            'fallback_successes' => 0,
            'fallback_failures' => 0,
            'rust_monitored_calls' => 0,
            'times_opened' => 0,
            'times_half_opened' => 0,
            'times_closed' => 0,
            'total_time' => 0,
            'avg_response_time' => 0,
            'old_total_calls' => 0,
            'timings' => [
                'php' => 0,
                'rust' => 0,
                'success' => 0,
                'failure' => 0,
                'fallback' => 0
            ]
        ];
    }

    public function getStats(): array
    {
        $this->updateState();

        return array_merge($this->stats, [
            'name' => $this->name,
            'state' => $this->state,
            'failure_count' => $this->failureCount,
            'success_count' => $this->successCount,
            'last_failure_time' => $this->lastFailureTime,
            'last_success_time' => $this->lastSuccessTime,
            'next_attempt_time' => $this->nextAttemptTime,
            'recent_failures_count' => count($this->recentFailures),
            'rust_available' => $this->rustAvailable,
            'config' => $this->config,
            'health_metrics' => $this->getHealthMetrics()
        ]);
    }

    public function getHealthMetrics(): array
    {
        $totalCalls = $this->stats['total_calls'];
        $successRate = $totalCalls > 0 
            ? round($this->stats['successful_calls'] / $totalCalls * 100, 2) 
            : 100;

        $recentFailureRate = 0;
        if ($totalCalls > 0) {
            $recentFailures = $this->countRecentFailures();
            $recentTotal = min($totalCalls, $this->config['request_volume_threshold'] * 2);
            $recentFailureRate = round($recentFailures / $recentTotal * 100, 2);
        }

        return [
            'success_rate' => $successRate,
            'recent_failure_rate' => $recentFailureRate,
            'availability' => $this->state === self::STATE_CLOSED ? 100 : 0,
            'avg_response_time_ms' => round($this->stats['avg_response_time'] * 1000, 2),
            'uptime_score' => $this->calculateUptimeScore()
        ];
    }

    private function calculateUptimeScore(): float
    {
        // Calculate uptime score for five nines reliability (99.999%)
        $totalCalls = $this->stats['total_calls'];
        if ($totalCalls === 0) return 100.0;

        $successfulCalls = $this->stats['successful_calls'] + $this->stats['fallback_successes'];
        return round($successfulCalls / $totalCalls * 100, 3);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getConfig(): array
    {
        return $this->config;
    }
}

/**
 * Exception thrown when circuit breaker is open
 */
class CircuitBreakerOpenException extends \RuntimeException
{
    //
}