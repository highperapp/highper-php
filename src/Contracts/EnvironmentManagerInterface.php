<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Contracts;

/**
 * Environment Manager Interface
 * 
 * Defines the contract for high-performance environment variable management.
 * Template-agnostic design allowing each template to define its own strategy.
 */
interface EnvironmentManagerInterface
{
    /**
     * Get environment variable with optional default value
     */
    public function get(string $key, mixed $default = null): mixed;
    
    /**
     * Set environment variable
     */
    public function set(string $key, mixed $value): void;
    
    /**
     * Check if environment variable exists
     */
    public function has(string $key): bool;
    
    /**
     * Load environment variables from source
     */
    public function load(): void;
    
    /**
     * Get configuration mapping for environment to config keys
     */
    public function getConfigMapping(): array;
    
    /**
     * Set configuration mapping
     */
    public function setConfigMapping(array $mapping): void;
    
    /**
     * Validate required environment variables
     */
    public function validateEnvironment(): array;
    
    /**
     * Load environment from file
     */
    public function loadFromFile(string $path): void;
    
    /**
     * Reset environment state
     */
    public function reset(): void;
}