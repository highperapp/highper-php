<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Foundation;

use HighPerApp\HighPer\Contracts\FFIManagerInterface;

/**
 * Rust FFI Manager - Unified FFI Management
 * 
 * Provides transparent access to Rust-based optimizations with
 * PHP fallbacks and unified library management.
 * 
 */
class RustFFIManager implements FFIManagerInterface
{
    private array $libraries = [];
    private array $config = [];
    private array $stats = ['calls' => 0, 'fallbacks' => 0];
    private bool $ffiAvailable;

    public function __construct()
    {
        $this->ffiAvailable = extension_loaded('ffi');
    }

    public function load(string $library): ?\FFI
    {
        if (!$this->ffiAvailable) {
            return null;
        }

        if (!isset($this->libraries[$library])) {
            try {
                $config = $this->config[$library] ?? [];
                $headerFile = $config['header'] ?? "/tmp/{$library}.h";
                $libFile = $config['lib'] ?? "/tmp/lib{$library}.so";
                
                if (file_exists($headerFile) && file_exists($libFile)) {
                    $this->libraries[$library] = \FFI::cdef(
                        file_get_contents($headerFile),
                        $libFile
                    );
                }
            } catch (\Exception $e) {
                error_log("FFI load failed for {$library}: " . $e->getMessage());
                $this->libraries[$library] = null;
            }
        }

        return $this->libraries[$library] ?? null;
    }

    public function call(string $library, string $function, array $args = [], ?callable $fallback = null): mixed
    {
        $this->stats['calls']++;
        
        $ffi = $this->load($library);
        if ($ffi && method_exists($ffi, $function)) {
            try {
                return $ffi->$function(...$args);
            } catch (\Exception $e) {
                error_log("FFI call failed: {$library}::{$function} - " . $e->getMessage());
            }
        }

        // Fallback to PHP implementation
        $this->stats['fallbacks']++;
        return $fallback ? $fallback(...$args) : null;
    }

    public function registerLibrary(string $name, array $config): void
    {
        $this->config[$name] = $config;
    }

    public function isAvailable(): bool
    {
        return $this->ffiAvailable;
    }

    public function getLoadedLibraries(): array
    {
        return array_keys(array_filter($this->libraries, fn($lib) => $lib !== null));
    }

    public function getStats(): array
    {
        return array_merge($this->stats, [
            'ffi_available' => $this->ffiAvailable,
            'libraries_loaded' => count($this->getLoadedLibraries()),
            'libraries_registered' => count($this->config)
        ]);
    }

    public function isLibraryLoaded(string $library): bool
    {
        return isset($this->libraries[$library]) && $this->libraries[$library] !== null;
    }
}