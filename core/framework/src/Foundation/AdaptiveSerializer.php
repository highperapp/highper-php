<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Foundation;

use HighPerApp\HighPer\Contracts\SerializationInterface;
use HighPerApp\HighPer\Contracts\FFIManagerInterface;

/**
 * Adaptive Serializer - JSON/MessagePack with Rust FFI
 * 
 * Multi-engine serialization with transparent Rust FFI acceleration
 * and PHP 8.3+ native performance optimizations.
 * 
 * Total: ~50 LOC as per project plan
 */
class AdaptiveSerializer implements SerializationInterface
{
    private string $defaultFormat = 'json';
    private array $stats = ['serialized' => 0, 'deserialized' => 0];
    private ?FFIManagerInterface $ffi;

    public function __construct(?FFIManagerInterface $ffi = null)
    {
        $this->ffi = $ffi;
    }

    public function serialize(mixed $data, ?string $format = null): string
    {
        $format = $format ?? $this->defaultFormat;
        $this->stats['serialized']++;

        return match($format) {
            'json' => $this->serializeJson($data),
            'msgpack' => $this->serializeMsgPack($data),
            default => json_encode($data, JSON_THROW_ON_ERROR)
        };
    }

    public function deserialize(string $data, ?string $format = null): mixed
    {
        $format = $format ?? $this->detectFormat($data);
        $this->stats['deserialized']++;

        return match($format) {
            'json' => json_decode($data, true, 512, JSON_THROW_ON_ERROR),
            'msgpack' => extension_loaded('msgpack') ? msgpack_unpack($data) : json_decode($data, true),
            default => json_decode($data, true)
        };
    }

    private function serializeJson(mixed $data): string
    {
        // Rust FFI acceleration if available
        if ($this->ffi && $this->ffi->isLibraryLoaded('json_serializer')) {
            return $this->ffi->call('json_serializer', 'serialize', [$data], fn($d) => json_encode($d, JSON_THROW_ON_ERROR));
        }
        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    private function serializeMsgPack(mixed $data): string
    {
        if (extension_loaded('msgpack')) {
            return msgpack_pack($data);
        }
        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    private function detectFormat(string $data): string
    {
        return str_starts_with(trim($data), '{') || str_starts_with(trim($data), '[') ? 'json' : 'msgpack';
    }

    public function getAvailableFormats(): array { return ['json', 'msgpack']; }
    public function setDefaultFormat(string $format): void { $this->defaultFormat = $format; }
    public function getStats(): array { return $this->stats; }
    public function isRustAvailable(): bool { return $this->ffi && $this->ffi->isLibraryLoaded('json_serializer'); }
    public function validate(string $data, string $format): bool { try { $this->deserialize($data, $format); return true; } catch (\Exception) { return false; } }
}