<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Resilience;

use HighPerApp\HighPer\Contracts\ReliabilityInterface;
use HighPerApp\HighPer\Contracts\CircuitBreakerInterface;
use HighPerApp\HighPer\Contracts\BulkheadInterface;
use HighPerApp\HighPer\Contracts\SelfHealingInterface;

/**
 * Five Nines Reliability - Orchestrated Reliability Stack
 * 
 * Coordinates circuit breakers, bulkheads, and self-healing for 99.999% uptime.
 * Orchestrates all reliability patterns for maximum availability.
 * 
 */
class FiveNinesReliability implements ReliabilityInterface
{
    private array $contexts = [];
    private array $metrics = ['total_requests' => 0, 'failures' => 0, 'recoveries' => 0];
    private float $startTime;
    private CircuitBreakerInterface $circuitBreaker;
    private BulkheadInterface $bulkhead;
    private SelfHealingInterface $selfHealing;

    public function __construct(
        CircuitBreakerInterface $circuitBreaker,
        BulkheadInterface $bulkhead,
        SelfHealingInterface $selfHealing
    ) {
        $this->circuitBreaker = $circuitBreaker;
        $this->bulkhead = $bulkhead;
        $this->selfHealing = $selfHealing;
        $this->startTime = microtime(true);
    }

    public function execute(string $context, callable $operation): mixed
    {
        $this->metrics['total_requests']++;
        
        try {
            // Initialize context if not exists
            if (!isset($this->contexts[$context])) {
                $this->initializeContext($context);
            }

            // Check if context is healthy
            if (!$this->isHealthy($context)) {
                $this->enableDegradedMode($context);
                throw new \RuntimeException("Context {$context} is unhealthy");
            }

            // Execute with circuit breaker and bulkhead protection
            return $this->bulkhead->execute($context, function() use ($operation) {
                return $this->circuitBreaker->execute($operation);
            });

        } catch (\Exception $e) {
            $this->metrics['failures']++;
            $this->handleFailure($context, $e);
            throw $e;
        }
    }

    private function initializeContext(string $context): void
    {
        $this->contexts[$context] = [
            'healthy' => true,
            'degraded' => false,
            'failures' => 0,
            'last_failure' => null,
            'created_at' => microtime(true)
        ];
        
        $this->bulkhead->createCompartment($context, ['max_concurrent' => 100]);
    }

    private function handleFailure(string $context, \Exception $e): void
    {
        $this->contexts[$context]['failures']++;
        $this->contexts[$context]['last_failure'] = microtime(true);
        
        // Auto-isolate if too many failures
        if ($this->contexts[$context]['failures'] > 5) {
            $this->isolate($context);
        }
        
        // Trigger self-healing
        $this->selfHealing->heal($context);
    }

    public function isHealthy(string $context): bool
    {
        if (!isset($this->contexts[$context])) {
            return true; // Unknown contexts are considered healthy initially
        }
        
        $ctx = $this->contexts[$context];
        
        // Check if recently failed
        if ($ctx['last_failure'] && (microtime(true) - $ctx['last_failure']) < 1.0) {
            return false;
        }
        
        // Check failure rate
        if ($ctx['failures'] > 10) {
            return false;
        }
        
        return $ctx['healthy'] && $this->bulkhead->isCompartmentHealthy($context);
    }

    public function isolate(string $context): void
    {
        if (isset($this->contexts[$context])) {
            $this->contexts[$context]['healthy'] = false;
            $this->bulkhead->isolateCompartment($context);
        }
    }

    public function recover(string $context): bool
    {
        if (!isset($this->contexts[$context])) {
            return false;
        }
        
        $recovered = $this->bulkhead->recoverCompartment($context);
        if ($recovered) {
            $this->contexts[$context]['healthy'] = true;
            $this->contexts[$context]['failures'] = 0;
            $this->contexts[$context]['degraded'] = false;
            $this->metrics['recoveries']++;
        }
        
        return $recovered;
    }

    public function enableDegradedMode(string $context): void
    {
        if (isset($this->contexts[$context])) {
            $this->contexts[$context]['degraded'] = true;
        }
    }

    public function getUptime(): float
    {
        $totalTime = microtime(true) - $this->startTime;
        $failureTime = $this->estimateFailureTime();
        return max(0, min(100, (1 - ($failureTime / $totalTime)) * 100));
    }

    private function estimateFailureTime(): float
    {
        // Estimate failure time based on failure count and recovery time
        return $this->metrics['failures'] * 0.01; // Assume 10ms per failure
    }

    public function getStatus(): array { return $this->contexts; }
    public function getMetrics(): array { return array_merge($this->metrics, ['uptime' => $this->getUptime(), 'contexts' => count($this->contexts)]); }
}