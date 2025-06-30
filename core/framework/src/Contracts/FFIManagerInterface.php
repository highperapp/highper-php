<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Contracts;

/**
 * FFI Manager Interface
 * 
 * Defines the contract for unified Rust FFI management.
 * Provides transparent access to Rust-based optimizations.
 */
interface FFIManagerInterface
{
    /**
     * Load FFI library
     */
    public function load(string $library): ?\FFI;

    /**
     * Check if FFI is available
     */
    public function isAvailable(): bool;

    /**
     * Get loaded libraries
     */
    public function getLoadedLibraries(): array;

    /**
     * Get FFI statistics
     */
    public function getStats(): array;

    /**
     * Call Rust function with fallback
     */
    public function call(string $library, string $function, array $args = [], ?callable $fallback = null): mixed;

    /**
     * Register library configuration
     */
    public function registerLibrary(string $name, array $config): void;

    /**
     * Check if library is loaded
     */
    public function isLibraryLoaded(string $library): bool;
}