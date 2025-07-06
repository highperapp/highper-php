<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Foundation;

use HighPerApp\HighPer\Contracts\SerializationInterface;
use HighPerApp\HighPer\Contracts\FFIManagerInterface;
use HighPerApp\HighPer\Contracts\LoggerInterface;

/**
 * Adaptive Serializer with Rust FFI Enhancement
 * 
 * Strategic JSON/MessagePack serialization with intelligent format selection,
 * Rust FFI acceleration for high-performance scenarios, and transparent
 * fallback to native PHP implementations for maximum compatibility.
 */
class AdaptiveSerializer implements SerializationInterface
{
    private string $defaultFormat = 'json';
    private array $stats = [
        'serialized' => 0,
        'deserialized' => 0,
        'rust_accelerated' => 0,
        'php_fallback' => 0,
        'format_switches' => 0
    ];
    private ?FFIManagerInterface $ffi;
    private LoggerInterface $logger;
    private array $configuration;
    private bool $rustAvailable = false;

    public function __construct(?FFIManagerInterface $ffi = null, ?LoggerInterface $logger = null)
    {
        $this->ffi = $ffi;
        $this->logger = $logger ?? new class implements LoggerInterface {
            public function emergency(string $message, array $context = []): void {}
            public function alert(string $message, array $context = []): void {}
            public function critical(string $message, array $context = []): void {}
            public function error(string $message, array $context = []): void {}
            public function warning(string $message, array $context = []): void {}
            public function notice(string $message, array $context = []): void {}
            public function info(string $message, array $context = []): void {}
            public function debug(string $message, array $context = []): void {}
            public function log(mixed $level, string $message, array $context = []): void {}
            public function flush(): void {}
            public function getStats(): array { return []; }
        };
        
        $this->configuration = $this->getDefaultConfiguration();
        $this->detectRustCapabilities();
        
        $this->logger->info('AdaptiveSerializer initialized', [
            'rust_available' => $this->rustAvailable,
            'default_format' => $this->defaultFormat,
            'available_formats' => $this->getAvailableFormats()
        ]);
    }

    public function serialize(mixed $data, ?string $format = null): string
    {
        $originalFormat = $format;
        $format = $format ?? $this->selectOptimalFormat($data);
        $this->stats['serialized']++;

        if ($originalFormat && $originalFormat !== $format) {
            $this->stats['format_switches']++;
            $this->logger->debug('Format switched for optimization', [
                'requested' => $originalFormat,
                'selected' => $format,
                'data_size' => $this->estimateDataSize($data)
            ]);
        }

        $startTime = microtime(true);
        
        try {
            $result = match($format) {
                'json' => $this->serializeJson($data),
                'msgpack' => $this->serializeMsgPack($data),
                default => $this->serializeJson($data) // Safe fallback
            };
            
            $duration = microtime(true) - $startTime;
            
            $this->logger->debug('Serialization completed', [
                'format' => $format,
                'data_size' => strlen($result),
                'duration_ms' => round($duration * 1000, 2),
                'rust_used' => $this->rustAvailable
            ]);
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->logger->error('Serialization failed', [
                'format' => $format,
                'error' => $e->getMessage(),
                'fallback_to_json' => true
            ]);
            
            // Safe fallback to JSON
            $this->stats['php_fallback']++;
            return json_encode($data, JSON_THROW_ON_ERROR);
        }
    }

    public function deserialize(string $data, ?string $format = null): mixed
    {
        $format = $format ?? $this->detectFormat($data);
        $this->stats['deserialized']++;

        $startTime = microtime(true);
        
        try {
            $result = match($format) {
                'json' => $this->deserializeJson($data),
                'msgpack' => $this->deserializeMsgPack($data),
                default => $this->deserializeJson($data) // Safe fallback
            };
            
            $duration = microtime(true) - $startTime;
            
            $this->logger->debug('Deserialization completed', [
                'format' => $format,
                'data_size' => strlen($data),
                'duration_ms' => round($duration * 1000, 2),
                'rust_used' => $this->rustAvailable
            ]);
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->logger->error('Deserialization failed', [
                'format' => $format,
                'data_size' => strlen($data),
                'error' => $e->getMessage(),
                'fallback_to_json' => true
            ]);
            
            // Safe fallback to JSON
            $this->stats['php_fallback']++;
            return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        }
    }

    private function serializeJson(mixed $data): string
    {
        // Try Rust FFI acceleration first
        if ($this->shouldUseRust($data, 'json')) {
            try {
                $result = $this->ffi->call('json_serializer', 'serialize', [$data]);
                $this->stats['rust_accelerated']++;
                return $result;
            } catch (\Throwable $e) {
                $this->logger->warning('Rust JSON serialization failed, falling back to PHP', [
                    'error' => $e->getMessage()
                ]);
                $this->stats['php_fallback']++;
            }
        }
        
        // PHP fallback with optimized flags
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function deserializeJson(string $data): mixed
    {
        // Try Rust FFI acceleration first
        if ($this->shouldUseRust($data, 'json')) {
            try {
                $result = $this->ffi->call('json_serializer', 'deserialize', [$data]);
                $this->stats['rust_accelerated']++;
                return $result;
            } catch (\Throwable $e) {
                $this->logger->warning('Rust JSON deserialization failed, falling back to PHP', [
                    'error' => $e->getMessage()
                ]);
                $this->stats['php_fallback']++;
            }
        }
        
        // PHP fallback
        return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
    }

    private function serializeMsgPack(mixed $data): string
    {
        // Try Rust FFI acceleration first
        if ($this->shouldUseRust($data, 'msgpack')) {
            try {
                $result = $this->ffi->call('msgpack_serializer', 'serialize', [$data]);
                $this->stats['rust_accelerated']++;
                return $result;
            } catch (\Throwable $e) {
                $this->logger->warning('Rust MessagePack serialization failed, falling back to PHP', [
                    'error' => $e->getMessage()
                ]);
                $this->stats['php_fallback']++;
            }
        }
        
        // PHP extension fallback
        if (extension_loaded('msgpack')) {
            return msgpack_pack($data);
        }
        
        // Final fallback to JSON
        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    private function deserializeMsgPack(string $data): mixed
    {
        // Try Rust FFI acceleration first
        if ($this->shouldUseRust($data, 'msgpack')) {
            try {
                $result = $this->ffi->call('msgpack_serializer', 'deserialize', [$data]);
                $this->stats['rust_accelerated']++;
                return $result;
            } catch (\Throwable $e) {
                $this->logger->warning('Rust MessagePack deserialization failed, falling back to PHP', [
                    'error' => $e->getMessage()
                ]);
                $this->stats['php_fallback']++;
            }
        }
        
        // PHP extension fallback
        if (extension_loaded('msgpack')) {
            return msgpack_unpack($data);
        }
        
        // Final fallback to JSON parsing
        return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
    }

    private function selectOptimalFormat(mixed $data): string
    {
        // Adaptive format selection based on data characteristics
        $estimatedSize = $this->estimateDataSize($data);
        
        // For large data, prefer MessagePack if available
        if ($estimatedSize > $this->configuration['msgpack_threshold']) {
            if (extension_loaded('msgpack') || $this->isRustLibraryAvailable('msgpack_serializer')) {
                return 'msgpack';
            }
        }
        
        // Default to JSON for compatibility
        return 'json';
    }

    private function detectFormat(string $data): string
    {
        // More sophisticated format detection
        $trimmed = trim($data);
        
        // JSON detection
        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[') || 
            str_starts_with($trimmed, '"') || ctype_digit($trimmed[0] ?? '') || 
            $trimmed === 'true' || $trimmed === 'false' || $trimmed === 'null') {
            return 'json';
        }
        
        // MessagePack is binary, so anything else
        return 'msgpack';
    }

    private function shouldUseRust(mixed $data, string $format): bool
    {
        if (!$this->rustAvailable || !$this->configuration['rust_enabled']) {
            return false;
        }
        
        $library = $format === 'json' ? 'json_serializer' : 'msgpack_serializer';
        
        if (!$this->isRustLibraryAvailable($library)) {
            return false;
        }
        
        // Use Rust for large data or when explicitly enabled
        $dataSize = is_string($data) ? strlen($data) : $this->estimateDataSize($data);
        return $dataSize >= $this->configuration['rust_threshold'];
    }

    private function estimateDataSize(mixed $data): int
    {
        // Quick estimation without full serialization
        return match (gettype($data)) {
            'string' => strlen($data),
            'array' => count($data) * 50, // Rough estimate
            'object' => count(get_object_vars($data)) * 50,
            'integer', 'double' => 8,
            'boolean' => 1,
            'NULL' => 0,
            default => 100
        };
    }

    private function detectRustCapabilities(): void
    {
        $this->rustAvailable = $this->ffi && extension_loaded('ffi');
        
        if ($this->rustAvailable) {
            $availableLibraries = [];
            
            foreach (['json_serializer', 'msgpack_serializer'] as $library) {
                if ($this->isRustLibraryAvailable($library)) {
                    $availableLibraries[] = $library;
                }
            }
            
            $this->logger->info('Rust FFI capabilities detected', [
                'ffi_extension' => extension_loaded('ffi'),
                'available_libraries' => $availableLibraries
            ]);
        }
    }

    private function isRustLibraryAvailable(string $library): bool
    {
        return $this->ffi && $this->ffi->isLibraryLoaded($library);
    }

    private function getDefaultConfiguration(): array
    {
        return [
            'rust_enabled' => (bool) ($_ENV['SERIALIZER_RUST_ENABLED'] ?? true),
            'rust_threshold' => (int) ($_ENV['SERIALIZER_RUST_THRESHOLD'] ?? 1024), // bytes
            'msgpack_threshold' => (int) ($_ENV['SERIALIZER_MSGPACK_THRESHOLD'] ?? 2048), // bytes
            'default_format' => $_ENV['SERIALIZER_DEFAULT_FORMAT'] ?? 'json',
            'compression_enabled' => (bool) ($_ENV['SERIALIZER_COMPRESSION_ENABLED'] ?? false),
            'validation_enabled' => (bool) ($_ENV['SERIALIZER_VALIDATION_ENABLED'] ?? true)
        ];
    }

    public function getAvailableFormats(): array
    {
        $formats = ['json'];
        
        if (extension_loaded('msgpack') || $this->isRustLibraryAvailable('msgpack_serializer')) {
            $formats[] = 'msgpack';
        }
        
        return $formats;
    }

    public function setDefaultFormat(string $format): void
    {
        if (!in_array($format, $this->getAvailableFormats())) {
            throw new \InvalidArgumentException("Format '{$format}' is not available");
        }
        
        $this->defaultFormat = $format;
        $this->logger->info('Default serialization format changed', ['format' => $format]);
    }

    public function getStats(): array
    {
        return array_merge($this->stats, [
            'rust_available' => $this->rustAvailable,
            'available_formats' => $this->getAvailableFormats(),
            'default_format' => $this->defaultFormat,
            'rust_acceleration_ratio' => $this->stats['serialized'] > 0 
                ? round($this->stats['rust_accelerated'] / $this->stats['serialized'] * 100, 2) 
                : 0
        ]);
    }

    public function isRustAvailable(): bool
    {
        return $this->rustAvailable;
    }

    public function validate(string $data, string $format): bool
    {
        if (!$this->configuration['validation_enabled']) {
            return true;
        }
        
        try {
            $this->deserialize($data, $format);
            return true;
        } catch (\Throwable $e) {
            $this->logger->debug('Validation failed', [
                'format' => $format,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function getConfiguration(): array
    {
        return $this->configuration;
    }

    public function setConfiguration(array $config): void
    {
        $this->configuration = array_merge($this->configuration, $config);
        $this->logger->info('Serializer configuration updated', $config);
    }
}