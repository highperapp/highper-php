<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Contracts;

/**
 * Configuration Manager Interface
 * 
 * Defines the contract for high-performance configuration management.
 * Optimized for C10M scenarios with minimal overhead.
 */
interface ConfigManagerInterface
{
    /**
     * Get a configuration value
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Set a configuration value
     */
    public function set(string $key, mixed $value): void;

    /**
     * Check if a configuration key exists
     */
    public function has(string $key): bool;

    /**
     * Load configuration from array
     */
    public function load(array $config): void;

    /**
     * Load configuration from file
     */
    public function loadFromFile(string $path): void;

    /**
     * Load environment variables
     */
    public function loadEnvironment(): void;

    /**
     * Get all configuration as array
     */
    public function all(): array;

    /**
     * Get configuration for a specific namespace
     */
    public function getNamespace(string $namespace): array;

    /**
     * Remove a configuration key
     */
    public function remove(string $key): void;

    /**
     * Clear all configuration
     */
    public function clear(): void;

    /**
     * Get environment name (dev, prod, test)
     */
    public function getEnvironment(): string;

    /**
     * Check if running in debug mode
     */
    public function isDebug(): bool;
}