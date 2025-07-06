<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Resilience;

/**
 * Graceful Degradation - Fallback Strategies
 * 
 * Implements graceful service degradation with configurable
 * fallback strategies to maintain partial functionality.
 * 
 */
class GracefulDegradation
{
    private array $fallbacks = [];
    private array $stats = ['degradations' => 0, 'fallback_executions' => 0, 'fallback_successes' => 0];
    private array $degradationLevels = [];

    public function registerFallback(string $service, callable $fallback, int $priority = 1): void
    {
        if (!isset($this->fallbacks[$service])) {
            $this->fallbacks[$service] = [];
        }
        
        $this->fallbacks[$service][] = [
            'callback' => $fallback,
            'priority' => $priority,
            'executions' => 0,
            'successes' => 0,
            'failures' => 0,
            'registered_at' => microtime(true)
        ];
        
        // Sort by priority (higher priority first)
        usort($this->fallbacks[$service], fn($a, $b) => $b['priority'] <=> $a['priority']);
    }

    public function executeWithDegradation(string $service, callable $primary, array $context = []): mixed
    {
        try {
            // Try primary service first
            return $primary();
            
        } catch (\Exception $e) {
            return $this->executeFallback($service, $context, $e);
        }
    }

    private function executeFallback(string $service, array $context, \Exception $originalException): mixed
    {
        $this->stats['degradations']++;
        
        if (!isset($this->fallbacks[$service])) {
            throw $originalException;
        }
        
        // Update degradation level
        $this->updateDegradationLevel($service);
        
        foreach ($this->fallbacks[$service] as &$fallbackInfo) {
            $this->stats['fallback_executions']++;
            $fallbackInfo['executions']++;
            
            try {
                $result = ($fallbackInfo['callback'])($context, $originalException);
                
                $fallbackInfo['successes']++;
                $this->stats['fallback_successes']++;
                
                return $result;
                
            } catch (\Exception $fallbackException) {
                $fallbackInfo['failures']++;
                // Continue to next fallback
            }
        }
        
        // All fallbacks failed, throw original exception
        throw $originalException;
    }

    private function updateDegradationLevel(string $service): void
    {
        if (!isset($this->degradationLevels[$service])) {
            $this->degradationLevels[$service] = ['level' => 0, 'last_degradation' => microtime(true)];
        }
        
        $this->degradationLevels[$service]['level']++;
        $this->degradationLevels[$service]['last_degradation'] = microtime(true);
    }

    public function getDegradationLevel(string $service): int
    {
        return $this->degradationLevels[$service]['level'] ?? 0;
    }

    public function isServiceDegraded(string $service): bool
    {
        return $this->getDegradationLevel($service) > 0;
    }

    public function resetDegradation(string $service): void
    {
        if (isset($this->degradationLevels[$service])) {
            $this->degradationLevels[$service]['level'] = 0;
        }
    }

    public function getStats(): array
    {
        $serviceStats = [];
        
        foreach ($this->fallbacks as $service => $fallbacks) {
            $serviceStats[$service] = [
                'fallback_count' => count($fallbacks),
                'degradation_level' => $this->getDegradationLevel($service),
                'total_executions' => array_sum(array_column($fallbacks, 'executions')),
                'total_successes' => array_sum(array_column($fallbacks, 'successes')),
                'total_failures' => array_sum(array_column($fallbacks, 'failures'))
            ];
        }
        
        return array_merge($this->stats, [
            'registered_services' => count($this->fallbacks),
            'degraded_services' => count(array_filter($this->degradationLevels, fn($d) => $d['level'] > 0)),
            'service_stats' => $serviceStats,
            'success_rate' => $this->stats['fallback_executions'] > 0 
                ? ($this->stats['fallback_successes'] / $this->stats['fallback_executions']) * 100 
                : 0
        ]);
    }

    public function getRegisteredServices(): array
    {
        return array_keys($this->fallbacks);
    }

    public function getDegradedServices(): array
    {
        return array_keys(array_filter($this->degradationLevels, fn($d) => $d['level'] > 0));
    }
}