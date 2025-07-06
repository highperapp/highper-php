<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Resilience;

use HighPerApp\HighPer\Contracts\CircuitBreakerInterface;

/**
 * Circuit Breaker - <10ms Recovery, Fast Fail
 * 
 * Ultra-fast circuit breaker with microsecond state transitions
 * and sub-10ms recovery attempts for five nines availability.
 * 
 */
class CircuitBreaker implements CircuitBreakerInterface
{
    private const STATE_CLOSED = 'closed';
    private const STATE_OPEN = 'open';
    private const STATE_HALF_OPEN = 'half_open';
    
    private const FAILURE_THRESHOLD = 5;
    private const RECOVERY_TIMEOUT = 0.01; // 10ms maximum
    private const SUCCESS_THRESHOLD = 3;
    
    private string $state = self::STATE_CLOSED;
    private int $failureCount = 0;
    private int $successCount = 0;
    private float $lastFailureTime = 0;
    private array $stats = ['calls' => 0, 'failures' => 0, 'successes' => 0, 'state_changes' => 0];

    public function execute(callable $operation): mixed
    {
        $this->stats['calls']++;
        
        // Fast failure if circuit is open and not ready for retry
        if ($this->isOpen() && !$this->canAttemptReset()) {
            $this->stats['failures']++;
            throw new \RuntimeException('Circuit breaker is OPEN');
        }
        
        // Transition to half-open if recovery timeout passed
        if ($this->isOpen() && $this->canAttemptReset()) {
            $this->transitionToHalfOpen();
        }
        
        try {
            $startTime = microtime(true);
            $result = $operation();
            $executionTime = microtime(true) - $startTime;
            
            $this->onSuccess($executionTime);
            return $result;
            
        } catch (\Exception $e) {
            $this->onFailure();
            throw $e;
        }
    }

    private function onSuccess(float $executionTime): void
    {
        $this->stats['successes']++;
        $this->failureCount = 0;
        
        if ($this->isHalfOpen()) {
            $this->successCount++;
            if ($this->successCount >= self::SUCCESS_THRESHOLD) {
                $this->transitionToClosed();
            }
        } elseif ($this->isOpen()) {
            $this->transitionToHalfOpen();
        }
    }

    private function onFailure(): void
    {
        $this->stats['failures']++;
        $this->failureCount++;
        $this->lastFailureTime = microtime(true);
        $this->successCount = 0;
        
        if ($this->failureCount >= self::FAILURE_THRESHOLD) {
            $this->transitionToOpen();
        }
    }

    private function canAttemptReset(): bool
    {
        return (microtime(true) - $this->lastFailureTime) >= self::RECOVERY_TIMEOUT;
    }

    private function transitionToOpen(): void
    {
        if ($this->state !== self::STATE_OPEN) {
            $this->state = self::STATE_OPEN;
            $this->stats['state_changes']++;
            $this->lastFailureTime = microtime(true);
        }
    }

    private function transitionToHalfOpen(): void
    {
        if ($this->state !== self::STATE_HALF_OPEN) {
            $this->state = self::STATE_HALF_OPEN;
            $this->stats['state_changes']++;
            $this->successCount = 0;
        }
    }

    private function transitionToClosed(): void
    {
        if ($this->state !== self::STATE_CLOSED) {
            $this->state = self::STATE_CLOSED;
            $this->stats['state_changes']++;
            $this->failureCount = 0;
            $this->successCount = 0;
        }
    }

    public function getState(): string { return $this->state; }
    public function isOpen(): bool { return $this->state === self::STATE_OPEN; }
    public function isClosed(): bool { return $this->state === self::STATE_CLOSED; }
    public function isHalfOpen(): bool { return $this->state === self::STATE_HALF_OPEN; }

    public function forceOpen(): void
    {
        $this->transitionToOpen();
        $this->failureCount = self::FAILURE_THRESHOLD;
    }

    public function forceClosed(): void
    {
        $this->transitionToClosed();
    }

    public function reset(): void
    {
        $this->state = self::STATE_CLOSED;
        $this->failureCount = 0;
        $this->successCount = 0;
        $this->lastFailureTime = 0;
        $this->stats = ['calls' => 0, 'failures' => 0, 'successes' => 0, 'state_changes' => 0];
    }

    public function getStats(): array
    {
        return array_merge($this->stats, [
            'state' => $this->state,
            'failure_count' => $this->failureCount,
            'success_count' => $this->successCount,
            'last_failure_time' => $this->lastFailureTime,
            'failure_rate' => $this->stats['calls'] > 0 ? ($this->stats['failures'] / $this->stats['calls']) * 100 : 0
        ]);
    }
}