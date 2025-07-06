<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Resilience;

use HighPerApp\HighPer\Contracts\BulkheadInterface;

/**
 * Bulkhead Isolator - Prevent Cascade Failures
 * 
 * Implements bulkhead pattern to isolate failures and prevent
 * cascade effects across service boundaries.
 * 
 */
class BulkheadIsolator implements BulkheadInterface
{
    private array $compartments = [];
    private array $stats = ['total_executions' => 0, 'isolated_failures' => 0];

    public function execute(string $compartment, callable $operation): mixed
    {
        $this->stats['total_executions']++;
        
        if (!isset($this->compartments[$compartment])) {
            $this->createCompartment($compartment, []);
        }
        
        $comp = &$this->compartments[$compartment];
        
        // Check if compartment is isolated
        if ($comp['isolated']) {
            $this->stats['isolated_failures']++;
            throw new \RuntimeException("Compartment {$compartment} is isolated");
        }
        
        // Check capacity limits
        if ($comp['active_count'] >= $comp['max_concurrent']) {
            throw new \RuntimeException("Compartment {$compartment} at capacity");
        }
        
        $comp['active_count']++;
        $comp['total_requests']++;
        
        try {
            $startTime = microtime(true);
            $result = $operation();
            $executionTime = microtime(true) - $startTime;
            
            $comp['successful_requests']++;
            $comp['avg_response_time'] = ($comp['avg_response_time'] + $executionTime) / 2;
            
            return $result;
            
        } catch (\Exception $e) {
            $comp['failed_requests']++;
            $comp['last_failure'] = microtime(true);
            
            // Auto-isolate if failure rate too high
            if ($this->calculateFailureRate($compartment) > 50) {
                $this->isolateCompartment($compartment);
            }
            
            throw $e;
            
        } finally {
            $comp['active_count']--;
        }
    }

    public function createCompartment(string $name, array $config): void
    {
        $this->compartments[$name] = array_merge([
            'max_concurrent' => 100,
            'timeout' => 30.0,
            'isolated' => false,
            'created_at' => microtime(true)
        ], $config, [
            'active_count' => 0,
            'total_requests' => 0,
            'successful_requests' => 0,
            'failed_requests' => 0,
            'last_failure' => null,
            'avg_response_time' => 0.0
        ]);
    }

    public function isCompartmentHealthy(string $compartment): bool
    {
        if (!isset($this->compartments[$compartment])) {
            return false;
        }
        
        $comp = $this->compartments[$compartment];
        
        // Check if isolated
        if ($comp['isolated']) {
            return false;
        }
        
        // Check failure rate
        if ($this->calculateFailureRate($compartment) > 25) {
            return false;
        }
        
        // Check if recently failed
        if ($comp['last_failure'] && (microtime(true) - $comp['last_failure']) < 5.0) {
            return false;
        }
        
        return true;
    }

    private function calculateFailureRate(string $compartment): float
    {
        $comp = $this->compartments[$compartment];
        
        if ($comp['total_requests'] === 0) {
            return 0.0;
        }
        
        return ($comp['failed_requests'] / $comp['total_requests']) * 100;
    }

    public function isolateCompartment(string $compartment): void
    {
        if (isset($this->compartments[$compartment])) {
            $this->compartments[$compartment]['isolated'] = true;
            $this->compartments[$compartment]['isolated_at'] = microtime(true);
        }
    }

    public function recoverCompartment(string $compartment): bool
    {
        if (!isset($this->compartments[$compartment])) {
            return false;
        }
        
        $this->compartments[$compartment]['isolated'] = false;
        $this->compartments[$compartment]['failed_requests'] = 0;
        $this->compartments[$compartment]['last_failure'] = null;
        
        return true;
    }

    public function getCompartmentStats(string $compartment): array
    {
        return $this->compartments[$compartment] ?? [];
    }

    public function getCompartments(): array
    {
        return array_keys($this->compartments);
    }

    public function getStatus(): array
    {
        return [
            'compartments' => $this->compartments,
            'stats' => $this->stats,
            'total_compartments' => count($this->compartments),
            'isolated_compartments' => count(array_filter($this->compartments, fn($c) => $c['isolated']))
        ];
    }
}