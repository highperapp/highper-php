<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Contracts;

/**
 * Self Healing Interface
 * 
 * Defines the contract for automatic recovery and self-healing
 * capabilities for maintaining five nines availability.
 */
interface SelfHealingInterface
{
    /**
     * Start self-healing monitoring
     */
    public function start(): void;

    /**
     * Stop self-healing monitoring
     */
    public function stop(): void;

    /**
     * Register recovery strategy
     */
    public function registerStrategy(string $context, callable $strategy): void;

    /**
     * Trigger healing for context
     */
    public function heal(string $context): bool;

    /**
     * Check if healing is active
     */
    public function isActive(): bool;

    /**
     * Get healing statistics
     */
    public function getStats(): array;

    /**
     * Get recovery strategies
     */
    public function getStrategies(): array;

    /**
     * Set healing interval
     */
    public function setInterval(float $seconds): void;
}