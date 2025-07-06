<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Contracts;

/**
 * Reliability Interface
 * 
 * Defines the contract for five nines reliability orchestration
 * with coordinated circuit breakers, bulkheads, and recovery.
 */
interface ReliabilityInterface
{
    /**
     * Execute operation with reliability patterns
     */
    public function execute(string $context, callable $operation): mixed;

    /**
     * Get reliability status
     */
    public function getStatus(): array;

    /**
     * Get reliability metrics
     */
    public function getMetrics(): array;

    /**
     * Check if context is healthy
     */
    public function isHealthy(string $context): bool;

    /**
     * Isolate context (bulkhead)
     */
    public function isolate(string $context): void;

    /**
     * Recover context
     */
    public function recover(string $context): bool;

    /**
     * Enable degraded mode
     */
    public function enableDegradedMode(string $context): void;

    /**
     * Get uptime percentage
     */
    public function getUptime(): float;
}