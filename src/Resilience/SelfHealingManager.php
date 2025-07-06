<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Resilience;

use HighPerApp\HighPer\Contracts\SelfHealingInterface;
use Revolt\EventLoop;

/**
 * Self Healing Manager - Automatic Recovery
 * 
 * Implements automatic recovery and self-healing capabilities
 * with configurable strategies and monitoring intervals.
 * 
 */
class SelfHealingManager implements SelfHealingInterface
{
    private array $strategies = [];
    private array $stats = ['healing_attempts' => 0, 'successful_healings' => 0, 'failed_healings' => 0];
    private bool $active = false;
    private float $interval = 5.0; // 5 seconds default
    private ?string $timerId = null;

    public function start(): void
    {
        if ($this->active) {
            return;
        }
        
        $this->active = true;
        
        // Start periodic healing checks
        $this->timerId = EventLoop::repeat($this->interval, function() {
            $this->performHealingCycle();
        });
    }

    public function stop(): void
    {
        $this->active = false;
        
        if ($this->timerId) {
            EventLoop::cancel($this->timerId);
            $this->timerId = null;
        }
    }

    public function registerStrategy(string $context, callable $strategy): void
    {
        if (!isset($this->strategies[$context])) {
            $this->strategies[$context] = [];
        }
        
        $this->strategies[$context][] = [
            'strategy' => $strategy,
            'registered_at' => microtime(true),
            'success_count' => 0,
            'failure_count' => 0,
            'last_executed' => null
        ];
    }

    public function heal(string $context): bool
    {
        $this->stats['healing_attempts']++;
        
        if (!isset($this->strategies[$context])) {
            return false;
        }
        
        foreach ($this->strategies[$context] as &$strategyInfo) {
            try {
                $strategy = $strategyInfo['strategy'];
                $result = $strategy();
                
                $strategyInfo['success_count']++;
                $strategyInfo['last_executed'] = microtime(true);
                
                if ($result === true) {
                    $this->stats['successful_healings']++;
                    return true;
                }
                
            } catch (\Exception $e) {
                $strategyInfo['failure_count']++;
                $strategyInfo['last_executed'] = microtime(true);
                // Continue to next strategy
            }
        }
        
        $this->stats['failed_healings']++;
        return false;
    }

    private function performHealingCycle(): void
    {
        if (!$this->active) {
            return;
        }
        
        // Check all registered contexts for healing opportunities
        foreach (array_keys($this->strategies) as $context) {
            // Only attempt healing if context hasn't been healed recently
            if ($this->shouldAttemptHealing($context)) {
                $this->heal($context);
            }
        }
    }

    private function shouldAttemptHealing(string $context): bool
    {
        $strategies = $this->strategies[$context] ?? [];
        
        foreach ($strategies as $strategyInfo) {
            $lastExecuted = $strategyInfo['last_executed'];
            
            // If never executed or last execution was more than interval ago
            if (!$lastExecuted || (microtime(true) - $lastExecuted) > $this->interval) {
                return true;
            }
        }
        
        return false;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getStats(): array
    {
        $contextStats = [];
        
        foreach ($this->strategies as $context => $strategies) {
            $contextStats[$context] = [
                'strategy_count' => count($strategies),
                'total_successes' => array_sum(array_column($strategies, 'success_count')),
                'total_failures' => array_sum(array_column($strategies, 'failure_count')),
                'last_healing_attempt' => max(array_column($strategies, 'last_executed') ?: [0])
            ];
        }
        
        return array_merge($this->stats, [
            'active' => $this->active,
            'interval' => $this->interval,
            'registered_contexts' => count($this->strategies),
            'context_stats' => $contextStats,
            'success_rate' => $this->stats['healing_attempts'] > 0 
                ? ($this->stats['successful_healings'] / $this->stats['healing_attempts']) * 100 
                : 0
        ]);
    }

    public function getStrategies(): array
    {
        return array_keys($this->strategies);
    }

    public function setInterval(float $seconds): void
    {
        $this->interval = $seconds;
        
        // Restart timer with new interval if active
        if ($this->active && $this->timerId) {
            EventLoop::cancel($this->timerId);
            $this->timerId = EventLoop::repeat($this->interval, function() {
                $this->performHealingCycle();
            });
        }
    }
}