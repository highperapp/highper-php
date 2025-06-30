<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Contracts;

/**
 * Serializer Interface
 * 
 * Defines the contract for high-performance serialization.
 * Supports adaptive format selection and Rust FFI integration.
 */
interface SerializerInterface
{
    /**
     * Check if serializer is available
     */
    public function isAvailable(): bool;

    /**
     * Get serializer capabilities
     */
    public function getCapabilities(): array;

    /**
     * Serialize data using optimal format
     */
    public function serialize(mixed $data): string;

    /**
     * Deserialize data with auto-format detection
     */
    public function deserialize(string $data, string $hint = 'auto'): mixed;

    /**
     * Get available serialization formats
     */
    public function getAvailableFormats(): array;

    /**
     * Set performance mode
     */
    public function setPerformanceMode(string $mode): void;

    /**
     * Get serializer statistics
     */
    public function getStats(): array;
}