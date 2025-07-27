<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\ServiceProvider;

use HighPerApp\HighPer\Foundation\ServiceProvider;
use HighPerApp\HighPer\Compression\CompressionServiceProvider as CompressionLibServiceProvider;
use HighPerApp\HighPer\Compression\Contracts\CompressionManagerInterface;
use HighPerApp\HighPer\Compression\Contracts\ConfigurationInterface;
use HighPerApp\HighPer\Compression\Middleware\CompressionMiddleware;
use Psr\Log\LoggerInterface;

/**
 * Compression Integration Service Provider
 * 
 * Integrates the compression standalone library into the HighPer framework:
 * - Registers compression manager and middleware
 * - Configures auto-discovery and performance optimization
 * - Provides health checks and metrics collection
 * - Enables Rust FFI acceleration and fallback engines
 */
class CompressionServiceProvider extends ServiceProvider
{
    private ?CompressionLibServiceProvider $compressionProvider = null;

    public function register(): void
    {
        $this->registerCompressionProvider();
        $this->registerCompressionManager();
        $this->registerCompressionMiddleware();
        $this->registerCompressionConfiguration();
    }

    public function boot(): void
    {
        $this->bootCompressionProvider();
        $this->registerHealthChecks();
        $this->registerMetricsCollectors();
        $this->initializeComponents();
    }

    private function registerCompressionProvider(): void
    {
        $this->singleton(CompressionLibServiceProvider::class, function() {
            $config = $this->getCompressionConfig();
            $logger = $this->container->has(LoggerInterface::class) 
                ? $this->container->get(LoggerInterface::class) 
                : null;

            return new CompressionLibServiceProvider(
                $this->container,
                $config,
                $logger
            );
        });

        $this->alias('compression.provider', CompressionLibServiceProvider::class);
    }

    private function registerCompressionManager(): void
    {
        $this->singleton(CompressionManagerInterface::class, function() {
            $provider = $this->container->get(CompressionLibServiceProvider::class);
            return $provider->bootstrap();
        });

        $this->alias('compression', CompressionManagerInterface::class);
        $this->alias('compression.manager', CompressionManagerInterface::class);
    }

    private function registerCompressionMiddleware(): void
    {
        $this->singleton(CompressionMiddleware::class, function() {
            $provider = $this->container->get(CompressionLibServiceProvider::class);
            return $provider->getMiddleware();
        });

        $this->alias('compression.middleware', CompressionMiddleware::class);
    }

    private function registerCompressionConfiguration(): void
    {
        $this->singleton(ConfigurationInterface::class, function() {
            $provider = $this->container->get(CompressionLibServiceProvider::class);
            return $provider->getConfiguration();
        });

        $this->alias('compression.config', ConfigurationInterface::class);
    }

    private function bootCompressionProvider(): void
    {
        $this->compressionProvider = $this->container->get(CompressionLibServiceProvider::class);
        
        // Register services with the container
        $this->compressionProvider->registerFrameworkServices($this->container);
        
        // Warm up compression engines if configured
        if ($this->env('COMPRESSION_WARMUP', true)) {
            $this->compressionProvider->warmUp();
        }
    }

    private function registerHealthChecks(): void
    {
        // Compression manager health check
        $this->registerHealthCheck('compression_manager', function() {
            $manager = $this->container->get(CompressionManagerInterface::class);
            $engines = $manager->getAvailableEngines();
            
            return [
                'status' => !empty($engines) ? 'healthy' : 'unavailable',
                'data' => [
                    'available_engines' => array_map(fn($e) => $e->getName(), $engines),
                    'preferred_engine' => $manager->getPreferredEngine(),
                    'rust_ffi_available' => in_array('RustFFI', array_map(fn($e) => $e->getName(), $engines)),
                    'total_engines' => count($engines)
                ]
            ];
        });

        // Compression middleware health check
        $this->registerHealthCheck('compression_middleware', function() {
            $middleware = $this->container->get(CompressionMiddleware::class);
            
            return [
                'status' => $middleware !== null ? 'healthy' : 'unavailable',
                'data' => [
                    'available' => $middleware !== null,
                    'class' => get_class($middleware)
                ]
            ];
        });
    }

    private function registerMetricsCollectors(): void
    {
        // Compression performance metrics
        $this->registerMetricsCollector('compression_performance', function() {
            $manager = $this->container->get(CompressionManagerInterface::class);
            $stats = $manager->getCompressionStats();
            
            return [
                'total_operations' => $stats['total_operations'] ?? 0,
                'compression_ratio_avg' => $stats['average_compression_ratio'] ?? 0,
                'operations_by_engine' => $stats['operations_by_engine'] ?? [],
                'bytes_processed' => $stats['total_bytes_processed'] ?? 0,
                'time_saved_ms' => $stats['total_time_saved'] ?? 0
            ];
        });

        // Engine availability metrics
        $this->registerMetricsCollector('compression_engines', function() {
            $manager = $this->container->get(CompressionManagerInterface::class);
            $engines = $manager->getAvailableEngines();
            
            $engineMetrics = [];
            foreach ($engines as $engine) {
                $engineMetrics[$engine->getName()] = [
                    'available' => $engine->isAvailable(),
                    'algorithms' => $engine->getSupportedAlgorithms(),
                    'performance_score' => $engine->getPerformanceScore() ?? 0
                ];
            }
            
            return [
                'total_engines' => count($engines),
                'rust_ffi_available' => in_array('RustFFI', array_map(fn($e) => $e->getName(), $engines)) ? 1 : 0,
                'engines' => $engineMetrics
            ];
        });
    }

    private function initializeComponents(): void
    {
        // Benchmark engines if configured
        if ($this->env('COMPRESSION_BENCHMARK', false)) {
            $manager = $this->container->get(CompressionManagerInterface::class);
            $benchmarks = $manager->benchmarkEngines();
            
            $this->log('info', 'Compression engines benchmarked', [
                'results' => array_map(fn($b) => [
                    'engine' => $b['engine'],
                    'score' => $b['score'],
                    'available' => $b['available']
                ], $benchmarks)
            ]);
        }

        // Log initialization
        $manager = $this->container->get(CompressionManagerInterface::class);
        $engines = $manager->getAvailableEngines();
        
        $this->log('info', 'Compression service initialized', [
            'engines' => array_map(fn($e) => $e->getName(), $engines),
            'preferred_engine' => $manager->getPreferredEngine(),
            'rust_ffi_available' => in_array('RustFFI', array_map(fn($e) => $e->getName(), $engines))
        ]);
    }

    private function getCompressionConfig(): array
    {
        return [
            'engines' => [
                'rust_ffi' => [
                    'enabled' => $this->env('COMPRESSION_RUST_FFI_ENABLED', true),
                    'library_path' => $this->env('COMPRESSION_RUST_FFI_PATH', __DIR__ . '/../../../compression/rust/'),
                    'fallback_enabled' => true
                ],
                'amphp' => [
                    'enabled' => $this->env('COMPRESSION_AMPHP_ENABLED', true),
                    'workers' => (int) $this->env('COMPRESSION_AMPHP_WORKERS', 4)
                ],
                'pure_php' => [
                    'enabled' => true // Always enabled as fallback
                ]
            ],
            'algorithms' => [
                'brotli' => [
                    'enabled' => $this->env('COMPRESSION_BROTLI_ENABLED', true),
                    'quality' => (int) $this->env('COMPRESSION_BROTLI_QUALITY', 6),
                    'window_size' => (int) $this->env('COMPRESSION_BROTLI_WINDOW_SIZE', 22)
                ],
                'gzip' => [
                    'enabled' => $this->env('COMPRESSION_GZIP_ENABLED', true),
                    'level' => (int) $this->env('COMPRESSION_GZIP_LEVEL', 6)
                ],
                'deflate' => [
                    'enabled' => $this->env('COMPRESSION_DEFLATE_ENABLED', true),
                    'level' => (int) $this->env('COMPRESSION_DEFLATE_LEVEL', 6)
                ]
            ],
            'performance' => [
                'async_threshold' => (int) $this->env('COMPRESSION_ASYNC_THRESHOLD', 8192),
                'parallel_threshold' => (int) $this->env('COMPRESSION_PARALLEL_THRESHOLD', 65536),
                'benchmark_on_startup' => $this->env('COMPRESSION_BENCHMARK_ON_STARTUP', true)
            ],
            'security' => [
                'max_input_size' => (int) $this->env('COMPRESSION_MAX_INPUT_SIZE', 50 * 1024 * 1024), // 50MB
                'compression_bomb_detection' => $this->env('COMPRESSION_BOMB_DETECTION', true),
                'max_compression_ratio' => (float) $this->env('COMPRESSION_MAX_RATIO', 0.01) // 1%
            ],
            'debug' => $this->env('COMPRESSION_DEBUG', false)
        ];
    }

    // Helper methods for health checks and metrics (would be added to base ServiceProvider)
    private function registerHealthCheck(string $name, callable $callback): void
    {
        // This would integrate with the existing health check system
        // For now, just log the registration
        $this->log('debug', "Registered compression health check: {$name}");
    }

    private function registerMetricsCollector(string $name, callable $callback): void
    {
        // This would integrate with the existing metrics system
        // For now, just log the registration
        $this->log('debug', "Registered compression metrics collector: {$name}");
    }
}