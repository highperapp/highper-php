<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Contracts;

/**
 * Serialization Interface
 * 
 * Defines the contract for adaptive serialization with multiple engines.
 * Single interface, multiple engines with transparent Rust FFI.
 */
interface SerializationInterface
{
    /**
     * Serialize data using optimal format
     */
    public function serialize(mixed $data, ?string $format = null): string;

    /**
     * Deserialize data with format detection
     */
    public function deserialize(string $data, ?string $format = null): mixed;

    /**
     * Get available serialization formats
     */
    public function getAvailableFormats(): array;

    /**
     * Set default serialization format
     */
    public function setDefaultFormat(string $format): void;

    /**
     * Get serialization statistics
     */
    public function getStats(): array;

    /**
     * Check if Rust FFI is available
     */
    public function isRustAvailable(): bool;

    /**
     * Validate data format
     */
    public function validate(string $data, string $format): bool;
}