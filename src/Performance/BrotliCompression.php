<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Performance;

use HighPerApp\HighPer\Contracts\FFIManagerInterface;
use HighPerApp\HighPer\Contracts\LoggerInterface;

/**
 * Brotli Compression with Rust FFI Enhancement
 * 
 * High-performance Brotli compression with transparent fallback strategy:
 * - Rust FFI: Primary path for maximum performance (3-5x faster than PHP)
 * - PHP Extension: Secondary fallback if brotli extension available
 * - gzip Fallback: Final fallback using native PHP gzip for compatibility
 * 
 * Performance targets:
 * - Compression: 20-30% better than gzip
 * - Speed: 3-5x faster than PHP brotli extension
 * - Memory: 50% less memory usage than pure PHP
 */
class BrotliCompression
{
    private FFIManagerInterface $ffi;
    private LoggerInterface $logger;
    private array $stats = [
        'compressions' => 0,
        'decompressions' => 0,
        'rust_accelerated' => 0,
        'extension_fallback' => 0,
        'gzip_fallback' => 0,
        'bytes_in' => 0,
        'bytes_out' => 0
    ];
    private array $config = [
        'rust_enabled' => true,
        'rust_threshold' => 1024, // Use Rust for data > 1KB
        'quality' => 6, // Brotli quality (0-11)
        'window_size' => 22, // Window size (10-24)
        'enable_fallback' => true,
        'benchmark_mode' => false
    ];
    private bool $rustAvailable = false;
    private bool $extensionAvailable = false;

    public function __construct(FFIManagerInterface $ffi, ?LoggerInterface $logger = null)
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

        $this->loadConfiguration();
        $this->detectCapabilities();
        $this->initializeBrotliFFI();

        $this->logger->info('BrotliCompression initialized', [
            'rust_available' => $this->rustAvailable,
            'extension_available' => $this->extensionAvailable,
            'fallback_strategy' => $this->getFallbackStrategy()
        ]);
    }

    /**
     * Compress data using Brotli with transparent fallback
     */
    public function compress(string $data): string
    {
        $startTime = microtime(true);
        $inputSize = strlen($data);
        $this->stats['compressions']++;
        $this->stats['bytes_in'] += $inputSize;

        try {
            $result = null;

            // Strategy 1: Rust FFI (highest performance)
            if ($this->shouldUseRust($data)) {
                $result = $this->compressWithRust($data);
                if ($result !== null) {
                    $this->stats['rust_accelerated']++;
                    $method = 'rust_ffi';
                }
            }

            // Strategy 2: PHP Brotli Extension
            if ($result === null && $this->extensionAvailable) {
                $result = $this->compressWithExtension($data);
                if ($result !== null) {
                    $this->stats['extension_fallback']++;
                    $method = 'php_extension';
                }
            }

            // Strategy 3: Gzip fallback (maximum compatibility)
            if ($result === null && $this->config['enable_fallback']) {
                $result = $this->compressWithGzip($data);
                $this->stats['gzip_fallback']++;
                $method = 'gzip_fallback';
            }

            if ($result === null) {
                throw new \RuntimeException('All compression methods failed');
            }

            $outputSize = strlen($result);
            $this->stats['bytes_out'] += $outputSize;
            $duration = microtime(true) - $startTime;

            $this->logger->debug('Compression completed', [
                'method' => $method,
                'input_size' => $inputSize,
                'output_size' => $outputSize,
                'compression_ratio' => round(($inputSize - $outputSize) / $inputSize * 100, 2),
                'duration_ms' => round($duration * 1000, 2)
            ]);

            return $result;

        } catch (\Throwable $e) {
            $this->logger->error('Compression failed', [
                'input_size' => $inputSize,
                'error' => $e->getMessage(),
                'fallback_attempted' => $this->config['enable_fallback']
            ]);
            throw $e;
        }
    }

    /**
     * Decompress Brotli-compressed data with transparent fallback
     */
    public function decompress(string $compressedData): string
    {
        $startTime = microtime(true);
        $inputSize = strlen($compressedData);
        $this->stats['decompressions']++;

        try {
            $result = null;
            $method = 'unknown';

            // Detect compression format
            $format = $this->detectCompressionFormat($compressedData);

            if ($format === 'brotli') {
                // Strategy 1: Rust FFI
                if ($this->shouldUseRust($compressedData)) {
                    $result = $this->decompressWithRust($compressedData);
                    if ($result !== null) {
                        $this->stats['rust_accelerated']++;
                        $method = 'rust_ffi';
                    }
                }

                // Strategy 2: PHP Extension
                if ($result === null && $this->extensionAvailable) {
                    $result = $this->decompressWithExtension($compressedData);
                    if ($result !== null) {
                        $this->stats['extension_fallback']++;
                        $method = 'php_extension';
                    }
                }
            }

            // Strategy 3: Try gzip decompression
            if ($result === null && $format === 'gzip') {
                $result = $this->decompressWithGzip($compressedData);
                $this->stats['gzip_fallback']++;
                $method = 'gzip_fallback';
            }

            if ($result === null) {
                throw new \RuntimeException('All decompression methods failed');
            }

            $duration = microtime(true) - $startTime;

            $this->logger->debug('Decompression completed', [
                'method' => $method,
                'format' => $format,
                'input_size' => $inputSize,
                'output_size' => strlen($result),
                'duration_ms' => round($duration * 1000, 2)
            ]);

            return $result;

        } catch (\Throwable $e) {
            $this->logger->error('Decompression failed', [
                'input_size' => $inputSize,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function compressWithRust(string $data): ?string
    {
        try {
            return $this->ffi->call(
                'brotli_compressor',
                'compress',
                [$data, $this->config['quality'], $this->config['window_size']],
                null
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Rust Brotli compression failed', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function decompressWithRust(string $data): ?string
    {
        try {
            return $this->ffi->call(
                'brotli_compressor',
                'decompress',
                [$data],
                null
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Rust Brotli decompression failed', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function compressWithExtension(string $data): ?string
    {
        if (!$this->extensionAvailable) {
            return null;
        }

        try {
            $compressed = brotli_compress($data, $this->config['quality']);
            return $compressed !== false ? $compressed : null;
        } catch (\Throwable $e) {
            $this->logger->warning('PHP Brotli extension compression failed', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function decompressWithExtension(string $data): ?string
    {
        if (!$this->extensionAvailable) {
            return null;
        }

        try {
            $decompressed = brotli_uncompress($data);
            return $decompressed !== false ? $decompressed : null;
        } catch (\Throwable $e) {
            $this->logger->warning('PHP Brotli extension decompression failed', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function compressWithGzip(string $data): string
    {
        $compressed = gzcompress($data, 6); // Level 6 for balance
        if ($compressed === false) {
            throw new \RuntimeException('Gzip compression failed');
        }
        return $compressed;
    }

    private function decompressWithGzip(string $data): string
    {
        $decompressed = gzuncompress($data);
        if ($decompressed === false) {
            throw new \RuntimeException('Gzip decompression failed');
        }
        return $decompressed;
    }

    private function shouldUseRust(string $data): bool
    {
        return $this->rustAvailable &&
               $this->config['rust_enabled'] &&
               $this->ffi->isLibraryLoaded('brotli_compressor') &&
               strlen($data) >= $this->config['rust_threshold'];
    }

    private function detectCompressionFormat(string $data): string
    {
        // Brotli magic bytes
        if (strlen($data) >= 2) {
            $header = unpack('C*', substr($data, 0, 2));
            
            // Brotli streams typically don't have fixed magic bytes,
            // but we can check for common patterns
            if (isset($header[1]) && ($header[1] & 0x0F) <= 11) {
                return 'brotli';
            }
        }

        // Gzip magic bytes: 1f 8b
        if (strlen($data) >= 2 && substr($data, 0, 2) === "\x1f\x8b") {
            return 'gzip';
        }

        // Default assumption for Brotli if uncertain
        return 'brotli';
    }

    private function detectCapabilities(): void
    {
        $this->rustAvailable = $this->ffi->isAvailable();
        $this->extensionAvailable = extension_loaded('brotli');

        $this->logger->info('Brotli capabilities detected', [
            'rust_ffi' => $this->rustAvailable,
            'php_extension' => $this->extensionAvailable,
            'gzip_available' => function_exists('gzcompress')
        ]);
    }

    private function initializeBrotliFFI(): void
    {
        if (!$this->rustAvailable) {
            return;
        }

        // Register Brotli compressor library
        $this->ffi->registerLibrary('brotli_compressor', [
            'header' => __DIR__ . '/../../rust/brotli/brotli.h',
            'lib' => __DIR__ . '/../../rust/brotli/target/release/libbrotli_compressor.so'
        ]);
    }

    private function loadConfiguration(): void
    {
        $this->config = array_merge($this->config, [
            'rust_enabled' => (bool) ($_ENV['BROTLI_RUST_ENABLED'] ?? true),
            'rust_threshold' => (int) ($_ENV['BROTLI_RUST_THRESHOLD'] ?? 1024),
            'quality' => (int) ($_ENV['BROTLI_QUALITY'] ?? 6),
            'window_size' => (int) ($_ENV['BROTLI_WINDOW_SIZE'] ?? 22),
            'enable_fallback' => (bool) ($_ENV['BROTLI_ENABLE_FALLBACK'] ?? true),
            'benchmark_mode' => (bool) ($_ENV['BROTLI_BENCHMARK_MODE'] ?? false)
        ]);
    }

    private function getFallbackStrategy(): array
    {
        $strategy = [];
        
        if ($this->rustAvailable && $this->ffi->isLibraryLoaded('brotli_compressor')) {
            $strategy[] = 'rust_ffi';
        }
        
        if ($this->extensionAvailable) {
            $strategy[] = 'php_extension';
        }
        
        if ($this->config['enable_fallback']) {
            $strategy[] = 'gzip_fallback';
        }
        
        return $strategy;
    }

    public function getStats(): array
    {
        $totalOps = $this->stats['compressions'] + $this->stats['decompressions'];
        
        return array_merge($this->stats, [
            'rust_available' => $this->rustAvailable,
            'extension_available' => $this->extensionAvailable,
            'fallback_strategy' => $this->getFallbackStrategy(),
            'total_operations' => $totalOps,
            'rust_usage_rate' => $totalOps > 0 
                ? round($this->stats['rust_accelerated'] / $totalOps * 100, 2) 
                : 0,
            'compression_ratio' => $this->stats['bytes_in'] > 0 
                ? round((1 - $this->stats['bytes_out'] / $this->stats['bytes_in']) * 100, 2) 
                : 0,
            'configuration' => $this->config
        ]);
    }

    public function getConfiguration(): array
    {
        return $this->config;
    }

    public function setConfiguration(array $config): void
    {
        $this->config = array_merge($this->config, $config);
        $this->logger->info('Brotli configuration updated', $config);
    }

    public function isRustAvailable(): bool
    {
        return $this->rustAvailable && $this->ffi->isLibraryLoaded('brotli_compressor');
    }

    public function isExtensionAvailable(): bool
    {
        return $this->extensionAvailable;
    }

    public function benchmark(string $testData, int $iterations = 100): array
    {
        $this->logger->info('Starting Brotli benchmark', [
            'data_size' => strlen($testData),
            'iterations' => $iterations
        ]);

        $methods = ['rust', 'extension', 'gzip'];
        $results = [];

        foreach ($methods as $method) {
            if (!$this->isMethodAvailable($method)) {
                continue;
            }

            $times = [];
            $sizes = [];

            for ($i = 0; $i < $iterations; $i++) {
                $start = microtime(true);
                
                $compressed = match ($method) {
                    'rust' => $this->compressWithRust($testData),
                    'extension' => $this->compressWithExtension($testData),
                    'gzip' => $this->compressWithGzip($testData),
                };

                $end = microtime(true);
                
                if ($compressed !== null) {
                    $times[] = ($end - $start) * 1000; // Convert to milliseconds
                    $sizes[] = strlen($compressed);
                }
            }

            if (!empty($times)) {
                $results[$method] = [
                    'avg_time_ms' => round(array_sum($times) / count($times), 3),
                    'min_time_ms' => round(min($times), 3),
                    'max_time_ms' => round(max($times), 3),
                    'avg_size' => round(array_sum($sizes) / count($sizes)),
                    'compression_ratio' => round((1 - (array_sum($sizes) / count($sizes)) / strlen($testData)) * 100, 2)
                ];
            }
        }

        return $results;
    }

    private function isMethodAvailable(string $method): bool
    {
        return match ($method) {
            'rust' => $this->isRustAvailable(),
            'extension' => $this->extensionAvailable,
            'gzip' => function_exists('gzcompress'),
            default => false
        };
    }
}