<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Contracts;

/**
 * Compiler Interface
 * 
 * Defines the contract for build-time compilation optimizations
 * including DI container compilation and pattern compilation.
 */
interface CompilerInterface
{
    /**
     * Compile container definitions for runtime optimization
     */
    public function compileContainer(array $definitions): string;

    /**
     * Check if compilation is available
     */
    public function isAvailable(): bool;

    /**
     * Get compilation statistics
     */
    public function getStats(): array;

    /**
     * Clear compiled cache
     */
    public function clearCache(): bool;

    /**
     * Warm up compiled cache
     */
    public function warmCache(): bool;

    /**
     * Get compiled cache path
     */
    public function getCachePath(): string;

    /**
     * Validate compiled code
     */
    public function validateCompiled(string $code): bool;
}