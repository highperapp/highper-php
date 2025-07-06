<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Contracts;

/**
 * Connection Pool Interface
 * 
 * Defines the contract for async connection pool management
 * with optimized connection reuse and health monitoring.
 */
interface ConnectionPoolInterface
{
    /**
     * Get connection from pool
     */
    public function getConnection(): mixed;

    /**
     * Return connection to pool
     */
    public function returnConnection(mixed $connection): void;

    /**
     * Check pool health
     */
    public function isHealthy(): bool;

    /**
     * Get pool statistics
     */
    public function getStats(): array;

    /**
     * Resize pool
     */
    public function resize(int $size): bool;

    /**
     * Close all connections
     */
    public function close(): void;

    /**
     * Get pool configuration
     */
    public function getConfig(): array;

    /**
     * Validate connection health
     */
    public function validateConnection(mixed $connection): bool;
}