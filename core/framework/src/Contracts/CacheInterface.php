<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Contracts;

/**
 * Cache Interface
 * 
 * Defines the contract for high-performance caching systems
 * with O(1) operations and optimized eviction policies.
 */
interface CacheInterface
{
    /**
     * Get value from cache
     */
    public function get(string $key): mixed;

    /**
     * Set value in cache
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool;

    /**
     * Check if key exists
     */
    public function has(string $key): bool;

    /**
     * Delete key from cache
     */
    public function delete(string $key): bool;

    /**
     * Clear all cache entries
     */
    public function clear(): bool;

    /**
     * Get cache statistics
     */
    public function getStats(): array;

    /**
     * Get multiple values at once
     */
    public function getMultiple(array $keys): array;

    /**
     * Set multiple values at once
     */
    public function setMultiple(array $values, ?int $ttl = null): bool;
}