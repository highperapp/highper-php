<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\ServiceProvider;

use HighPerApp\HighPer\Foundation\ServiceProvider;
use HighPerApp\HighPer\Contracts\ServiceProviderInterface;
use HighPerApp\HighPer\GRPC\ServiceProvider\GrpcServiceProvider as BaseGrpcServiceProvider;
use HighPerApp\HighPer\GRPC\GrpcServerFactory;
use HighPerApp\HighPer\GRPC\GrpcServer;
use HighPerApp\HighPer\GRPC\Engines\HybridEngine;
use HighPerApp\HighPer\GRPC\Protocol\GrpcProtocolHandler;
use HighPerApp\HighPer\GRPC\Reliability\GrpcCircuitBreaker;
use HighPerApp\HighPer\GRPC\Reliability\GrpcRetryHandler;
use HighPerApp\HighPer\GRPC\Serialization\ProtobufSerializer;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * HighPer Framework gRPC Service Provider
 * 
 * Integrates the gRPC standalone library into the HighPer framework
 * with framework-specific configuration and optimizations.
 */
class GrpcServiceProvider extends ServiceProvider implements ServiceProviderInterface
{
    protected array $provides = [
        'grpc.factory',
        'grpc.server',
        'grpc.engine',
        'grpc.protocol_handler',
        'grpc.circuit_breaker',
        'grpc.retry_handler',
        'grpc.serializer',
        GrpcServerFactory::class,
        GrpcServer::class,
        HybridEngine::class,
        GrpcProtocolHandler::class,
        GrpcCircuitBreaker::class,
        GrpcRetryHandler::class,
        ProtobufSerializer::class,
    ];

    protected array $singletons = [
        'grpc.factory',
        'grpc.server',
        'grpc.engine',
        'grpc.protocol_handler',
        'grpc.serializer',
    ];

    public function register(): void
    {
        $this->registerGrpcConfiguration();
        $this->registerGrpcComponents();
        $this->registerGrpcAliases();
    }

    public function boot(): void
    {
        $this->configureGrpcServer();
        $this->registerGrpcServices();
    }

    public function provides(): array
    {
        return $this->provides;
    }

    protected function registerGrpcConfiguration(): void
    {
        $this->app->singleton('grpc.config', function (ContainerInterface $container) {
            $config = $container->get('config');
            
            return array_merge([
                'host' => $config->get('grpc.host', '0.0.0.0'),
                'port' => $config->get('grpc.port', 9090),
                'tls_port' => $config->get('grpc.tls_port', 9091),
                'worker_processes' => $config->get('grpc.worker_processes', 4),
                'parallel_workers' => $config->get('grpc.parallel_workers', 2),
                'max_message_size' => $config->get('grpc.max_message_size', 16 * 1024 * 1024),
                'compression_enabled' => $config->get('grpc.compression_enabled', true),
                'streaming_enabled' => $config->get('grpc.streaming_enabled', true),
                'timeout_seconds' => $config->get('grpc.timeout_seconds', 30),
                'engine' => [
                    'rust_acceleration' => $config->get('grpc.engine.rust_acceleration', true),
                    'fallback_to_php' => $config->get('grpc.engine.fallback_to_php', true),
                ],
                'circuit_breaker' => [
                    'enabled' => $config->get('grpc.circuit_breaker.enabled', true),
                    'failure_threshold' => $config->get('grpc.circuit_breaker.failure_threshold', 5),
                    'timeout_seconds' => $config->get('grpc.circuit_breaker.timeout_seconds', 60),
                    'minimum_requests' => $config->get('grpc.circuit_breaker.minimum_requests', 3),
                ],
                'retry' => [
                    'enabled' => $config->get('grpc.retry.enabled', true),
                    'max_attempts' => $config->get('grpc.retry.max_attempts', 3),
                    'base_delay_ms' => $config->get('grpc.retry.base_delay_ms', 100),
                    'max_delay_ms' => $config->get('grpc.retry.max_delay_ms', 30000),
                    'jitter' => $config->get('grpc.retry.jitter', true),
                ],
                'security' => [
                    'tls_enabled' => $config->get('grpc.security.tls_enabled', false),
                    'cert_file' => $config->get('grpc.security.cert_file'),
                    'key_file' => $config->get('grpc.security.key_file'),
                    'ca_file' => $config->get('grpc.security.ca_file'),
                ],
                'monitoring' => [
                    'enabled' => $config->get('grpc.monitoring.enabled', true),
                    'metrics_port' => $config->get('grpc.monitoring.metrics_port', 9092),
                ],
            ], $config->get('grpc', []));
        });
    }

    protected function registerGrpcComponents(): void
    {
        // Register gRPC Server Factory
        $this->app->singleton('grpc.factory', function (ContainerInterface $container) {
            return new GrpcServerFactory(
                $container->get('grpc.config'),
                $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null
            );
        });

        // Register gRPC Server
        $this->app->singleton('grpc.server', function (ContainerInterface $container) {
            return $container->get('grpc.factory')->createHighPerformanceServer();
        });

        // Register Engine
        $this->app->singleton('grpc.engine', function (ContainerInterface $container) {
            return $container->get('grpc.factory')->createEngine();
        });

        // Register Protocol Handler
        $this->app->singleton('grpc.protocol_handler', function (ContainerInterface $container) {
            return $container->get('grpc.factory')->createProtocolHandler(
                $container->get('grpc.engine')
            );
        });

        // Register Circuit Breaker (not singleton - new instance per request)
        $this->app->bind('grpc.circuit_breaker', function (ContainerInterface $container) {
            return $container->get('grpc.factory')->createCircuitBreaker();
        });

        // Register Retry Handler (not singleton - new instance per request)
        $this->app->bind('grpc.retry_handler', function (ContainerInterface $container) {
            return $container->get('grpc.factory')->createRetryHandler();
        });

        // Register Serializer
        $this->app->singleton('grpc.serializer', function (ContainerInterface $container) {
            $config = $container->get('grpc.config');
            return new ProtobufSerializer(
                $config['serialization'] ?? [],
                $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null
            );
        });
    }

    protected function registerGrpcAliases(): void
    {
        $this->app->alias('grpc.factory', GrpcServerFactory::class);
        $this->app->alias('grpc.server', GrpcServer::class);
        $this->app->alias('grpc.engine', HybridEngine::class);
        $this->app->alias('grpc.protocol_handler', GrpcProtocolHandler::class);
        $this->app->alias('grpc.circuit_breaker', GrpcCircuitBreaker::class);
        $this->app->alias('grpc.retry_handler', GrpcRetryHandler::class);
        $this->app->alias('grpc.serializer', ProtobufSerializer::class);
    }

    protected function configureGrpcServer(): void
    {
        if (!$this->app->has('grpc.server')) {
            return;
        }

        $server = $this->app->get('grpc.server');
        $config = $this->app->get('grpc.config');

        // Configure server settings
        if (isset($config['max_message_size'])) {
            $server->setMaxMessageSize($config['max_message_size']);
        }

        if (isset($config['compression_enabled'])) {
            $server->setCompressionEnabled($config['compression_enabled']);
        }

        if (isset($config['streaming_enabled'])) {
            $server->setStreamingEnabled($config['streaming_enabled']);
        }
    }

    protected function registerGrpcServices(): void
    {
        $config = $this->app->get('grpc.config');
        
        // Auto-discover services from configured directories
        if (isset($config['service_directories'])) {
            $serviceProvider = new BaseGrpcServiceProvider($this->app, $config);
            
            foreach ($config['service_directories'] as $directory) {
                if (is_dir($directory)) {
                    $serviceProvider->discoverServices($directory);
                }
            }
        }
    }

    /**
     * Register a gRPC service with the server
     */
    public function registerService(string $serviceClass): void
    {
        $server = $this->app->get('grpc.server');
        $service = $this->app->has($serviceClass) 
            ? $this->app->get($serviceClass)
            : new $serviceClass();
            
        $server->registerService($service);
    }

    /**
     * Get the gRPC server instance
     */
    public function getServer(): GrpcServer
    {
        return $this->app->get('grpc.server');
    }

    /**
     * Get the gRPC server factory
     */
    public function getFactory(): GrpcServerFactory
    {
        return $this->app->get('grpc.factory');
    }

    /**
     * Check if gRPC is enabled
     */
    public function isEnabled(): bool
    {
        $config = $this->app->get('grpc.config');
        return $config['enabled'] ?? true;
    }

    /**
     * Get gRPC configuration
     */
    public function getConfig(): array
    {
        return $this->app->get('grpc.config');
    }
}