<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\ServiceProvider;

use HighPerApp\HighPer\Foundation\ServiceProvider;
use HighPerApp\HighPer\Contracts\ProcessManagerInterface;
use HighPerApp\HighPer\Contracts\AsyncManagerInterface;
use HighPerApp\HighPer\Contracts\SerializerInterface;
use HighPerApp\HighPer\Foundation\ProcessManager;
use HighPerApp\HighPer\Foundation\AsyncManager;
use HighPerApp\HighPer\Foundation\AdaptiveSerializer;
use HighPerApp\HighPer\Foundation\RustFFIManager;
use HighPerApp\HighPer\Foundation\AMPHTTPServerManager;
use HighPerApp\HighPer\Foundation\ZeroDowntimeManager;

/**
 * Performance Service Provider
 * 
 * Registers Phase 1 performance enhancement components:
 * - ProcessManager (multi-process workers)
 * - AsyncManager (transparent async patterns)
 * - AdaptiveSerializer (JSON/MessagePack/Rust FFI)
 * - RustFFIManager (performance optimization layer)
 * - AMPHTTPServerManager (AMPHP direct/proxy modes)
 * - ZeroDowntimeManager (graceful deployments)
 */
class PerformanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerProcessManager();
        $this->registerAsyncManager();
        $this->registerSerializer();
        $this->registerRustFFIManager();
        $this->registerAMPHTTPServerManager();
        $this->registerZeroDowntimeManager();
    }

    public function boot(): void
    {
        $this->registerHealthChecks();
        $this->registerMetricsCollectors();
        $this->initializeComponents();
    }

    private function registerProcessManager(): void
    {
        $this->singleton(ProcessManagerInterface::class, function() {
            $config = $this->getProcessManagerConfig();
            return new ProcessManager($this->app, $config);
        });

        $this->alias('process.manager', ProcessManagerInterface::class);
    }

    private function registerAsyncManager(): void
    {
        $this->singleton(AsyncManagerInterface::class, function() {
            return new AsyncManager();
        });

        $this->alias('async.manager', AsyncManagerInterface::class);
    }

    private function registerSerializer(): void
    {
        $this->singleton(SerializerInterface::class, function() {
            return new AdaptiveSerializer();
        });

        $this->alias('serializer', SerializerInterface::class);
        $this->alias('serializer.adaptive', SerializerInterface::class);
    }

    private function registerRustFFIManager(): void
    {
        $this->singleton('rust.ffi', function() {
            $config = $this->getRustFFIConfig();
            return new RustFFIManager($config);
        });
    }

    private function registerAMPHTTPServerManager(): void
    {
        $this->singleton('amphp.server', function() {
            $config = $this->getAMPHTTPConfig();
            $logger = $this->app->getLogger();
            return new AMPHTTPServerManager($config, $logger);
        });
    }

    private function registerZeroDowntimeManager(): void
    {
        $this->singleton('zero.downtime', function() {
            $config = $this->getZeroDowntimeConfig();
            return new ZeroDowntimeManager($config);
        });
    }

    private function registerHealthChecks(): void
    {
        // ProcessManager health check
        $this->registerHealthCheck('process_manager', function() {
            $manager = $this->container->get(ProcessManagerInterface::class);
            return [
                'status' => $manager->isAvailable() ? 'healthy' : 'unavailable',
                'data' => [
                    'available' => $manager->isAvailable(),
                    'running' => $manager->isRunning(),
                    'workers' => $manager->getWorkersCount(),
                    'capabilities' => $manager->getCapabilities()
                ]
            ];
        });

        // AsyncManager health check
        $this->registerHealthCheck('async_manager', function() {
            $manager = $this->container->get(AsyncManagerInterface::class);
            return [
                'status' => $manager->isAvailable() ? 'healthy' : 'unavailable',
                'data' => [
                    'available' => $manager->isAvailable(),
                    'async_context' => $manager->isAsync(),
                    'capabilities' => $manager->getCapabilities(),
                    'stats' => $manager->getStats()
                ]
            ];
        });

        // Serializer health check
        $this->registerHealthCheck('serializer', function() {
            $serializer = $this->container->get(SerializerInterface::class);
            return [
                'status' => $serializer->isAvailable() ? 'healthy' : 'unavailable',
                'data' => [
                    'available' => $serializer->isAvailable(),
                    'formats' => $serializer->getAvailableFormats(),
                    'capabilities' => $serializer->getCapabilities(),
                    'stats' => $serializer->getStats()
                ]
            ];
        });
    }

    private function registerMetricsCollectors(): void
    {
        // Process manager metrics
        $this->registerMetricsCollector('process_manager', function() {
            $manager = $this->container->get(ProcessManagerInterface::class);
            $stats = $manager->getStats();
            
            return [
                'workers_total' => $stats['total_workers'] ?? 0,
                'workers_active' => $stats['active_workers'] ?? 0,
                'memory_usage_bytes' => $stats['memory_usage'] ?? 0,
                'uptime_seconds' => $stats['uptime'] ?? 0,
                'restarts_total' => $stats['restarts'] ?? 0
            ];
        });

        // Async manager metrics
        $this->registerMetricsCollector('async_manager', function() {
            $manager = $this->container->get(AsyncManagerInterface::class);
            $stats = $manager->getStats();
            
            return [
                'operations_total' => $stats['operations'] ?? 0,
                'concurrent_operations' => $stats['pending_operations'] ?? 0,
                'async_context_active' => $manager->isAsync() ? 1 : 0
            ];
        });

        // Serializer metrics
        $this->registerMetricsCollector('serializer', function() {
            $serializer = $this->container->get(SerializerInterface::class);
            $stats = $serializer->getStats();
            
            return [
                'serializations_total' => $stats['serializations'] ?? 0,
                'deserializations_total' => $stats['deserializations'] ?? 0,
                'json_operations' => $stats['json_count'] ?? 0,
                'msgpack_operations' => $stats['msgpack_count'] ?? 0,
                'rust_operations' => $stats['rust_count'] ?? 0
            ];
        });
    }

    private function initializeComponents(): void
    {
        // Initialize AsyncManager
        $asyncManager = $this->container->get(AsyncManagerInterface::class);
        $asyncManager->init();

        // Initialize AdaptiveSerializer
        $serializer = $this->container->get(SerializerInterface::class);
        // AdaptiveSerializer initializes automatically on first use

        $this->log('info', 'Performance components initialized', [
            'process_manager_available' => $this->container->get(ProcessManagerInterface::class)->isAvailable(),
            'async_manager_available' => $asyncManager->isAvailable(),
            'serializer_available' => $serializer->isAvailable()
        ]);
    }

    private function getProcessManagerConfig(): array
    {
        return [
            'workers' => (int) $this->env('PROCESS_WORKERS', (int) shell_exec('nproc') ?: 4),
            'memory_limit' => $this->env('PROCESS_MEMORY_LIMIT', '256M'),
            'restart_threshold' => (int) $this->env('PROCESS_RESTART_THRESHOLD', 10000),
            'graceful_timeout' => (int) $this->env('PROCESS_GRACEFUL_TIMEOUT', 30)
        ];
    }

    private function getRustFFIConfig(): array
    {
        return [
            'enabled' => $this->env('RUST_FFI_ENABLED', true),
            'library_paths' => [
                $this->env('RUST_FFI_PATH', __DIR__ . '/../../../rust/'),
                '/usr/local/lib/',
                '/usr/lib/'
            ],
            'fallback_enabled' => true,
            'benchmark_on_startup' => $this->env('RUST_FFI_BENCHMARK', false)
        ];
    }

    private function getAMPHTTPConfig(): array
    {
        return [
            'mode' => $this->env('AMPHP_MODE', 'auto'),
            'host' => $this->env('AMPHP_HOST', '0.0.0.0'),
            'port' => (int) $this->env('AMPHP_PORT', 8080),
            'direct_access' => [
                'connection_limit' => (int) $this->env('AMPHP_CONNECTION_LIMIT', 10000),
                'connection_limit_per_ip' => (int) $this->env('AMPHP_CONNECTION_LIMIT_PER_IP', 100),
                'concurrency_limit' => (int) $this->env('AMPHP_CONCURRENCY_LIMIT', 1000)
            ]
        ];
    }

    private function getZeroDowntimeConfig(): array
    {
        return [
            'graceful_timeout' => (int) $this->env('ZERO_DOWNTIME_TIMEOUT', 30),
            'health_check_interval' => (int) $this->env('ZERO_DOWNTIME_HEALTH_INTERVAL', 5),
            'preserve_connections' => $this->env('ZERO_DOWNTIME_PRESERVE_CONNECTIONS', true),
            'backup_before_deploy' => $this->env('ZERO_DOWNTIME_BACKUP', true),
            'rollback_on_failure' => $this->env('ZERO_DOWNTIME_ROLLBACK', true)
        ];
    }

    // Helper methods for health checks and metrics (would be added to base ServiceProvider)
    private function registerHealthCheck(string $name, callable $callback): void
    {
        // This would integrate with the existing health check system
        // For now, just log the registration
        $this->log('debug', "Registered health check: {$name}");
    }

    private function registerMetricsCollector(string $name, callable $callback): void
    {
        // This would integrate with the existing metrics system
        // For now, just log the registration
        $this->log('debug', "Registered metrics collector: {$name}");
    }
}