<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Contracts;

use Amp\Future;

/**
 * Async Manager Interface
 * 
 * Defines the contract for transparent auto-yield async patterns.
 * Enhanced async/await with zero-config auto-yield.
 */
interface AsyncManagerInterface
{
    /**
     * Auto-yield operation with transparent async handling
     */
    public function autoYield(callable $operation): Future;

    /**
     * Execute multiple operations concurrently
     */
    public function concurrent(array $operations): Future;

    /**
     * Execute operation with timeout
     */
    public function withTimeout(callable $operation, float $timeout): Future;

    /**
     * Schedule operation for next tick
     */
    public function nextTick(callable $operation): void;

    /**
     * Create repeating operation
     */
    public function repeat(float $interval, callable $operation): string;

    /**
     * Check if running in async context
     */
    public function isAsync(): bool;

    /**
     * Get async manager statistics
     */
    public function getStats(): array;

    /**
     * Cleanup pending operations
     */
    public function cleanup(): void;
}