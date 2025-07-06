<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\ServiceProvider;

use HighPerApp\HighPer\Contracts\ServiceProviderInterface;
use HighPerApp\HighPer\Contracts\ContainerInterface;
use HighPerApp\HighPer\Contracts\LoggerInterface;
use HighPerApp\HighPer\Observability\ObservabilityManager;

/**
 * Observability Service Provider for HighPer Framework
 * 
 * Provides unified integration of three observability layers:
 * 1. Tracing Library (standalone) - Request flow tracking, OpenTelemetry
 * 2. Monitoring Library (standalone) - Metrics, dashboards, performance analytics  
 * 3. Framework HealthMonitor - Component health, five nines reliability
 * 
 * Features:
 * - Auto-detection of available observability libraries
 * - Unified configuration management
 * - Cross-cutting observability concerns
 * - Automatic middleware registration
 * - Integration with framework components
 */
class ObservabilityServiceProvider implements ServiceProviderInterface
{
    private array $config = [];
    private array $registeredServices = [];
    private bool $observabilityAvailable = false;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'auto_detect' => true,
            'enable_middleware' => true,
            'enable_auto_instrumentation' => true,
            'lazy_loading' => true,
            'tracing' => [
                'enabled' => true,
                'auto_detect' => true,
                'service_name' => $_ENV['APP_NAME'] ?? 'highper-app',
                'environment' => $_ENV['APP_ENV'] ?? 'production',
                'sampling_ratio' => (float) ($_ENV['TRACING_SAMPLING_RATIO'] ?? 1.0),
                'export_backend' => $_ENV['TRACING_BACKEND'] ?? 'jaeger',
                'export_endpoint' => $_ENV['TRACING_ENDPOINT'] ?? 'http://localhost:14268/api/traces',
                'auto_instrumentation' => [
                    'http' => (bool) ($_ENV['TRACING_HTTP'] ?? true),
                    'database' => (bool) ($_ENV['TRACING_DATABASE'] ?? true),
                    'cache' => (bool) ($_ENV['TRACING_CACHE'] ?? true),
                    'queue' => (bool) ($_ENV['TRACING_QUEUE'] ?? true),
                    'reliability' => (bool) ($_ENV['TRACING_RELIABILITY'] ?? true)
                ]
            ],
            'monitoring' => [
                'enabled' => (bool) ($_ENV['MONITORING_ENABLED'] ?? true),
                'auto_detect' => true,
                'dashboard_enabled' => (bool) ($_ENV['MONITORING_DASHBOARD'] ?? false),
                'dashboard_port' => (int) ($_ENV['MONITORING_DASHBOARD_PORT'] ?? 8080),
                'metrics_export' => (bool) ($_ENV['MONITORING_METRICS_EXPORT'] ?? true),
                'performance_tracking' => (bool) ($_ENV['MONITORING_PERFORMANCE'] ?? true),
                'prometheus_enabled' => (bool) ($_ENV['MONITORING_PROMETHEUS'] ?? false),
                'prometheus_endpoint' => $_ENV['MONITORING_PROMETHEUS_ENDPOINT'] ?? '/metrics'
            ],
            'health_monitoring' => [
                'enabled' => (bool) ($_ENV['HEALTH_MONITORING_ENABLED'] ?? true),
                'check_interval' => (int) ($_ENV['HEALTH_CHECK_INTERVAL'] ?? 30),
                'health_threshold' => (float) ($_ENV['HEALTH_THRESHOLD'] ?? 99.999),
                'integration_hooks' => (bool) ($_ENV['HEALTH_INTEGRATION_HOOKS'] ?? true),
                'export_to_monitoring' => (bool) ($_ENV['HEALTH_EXPORT_MONITORING'] ?? true),
                'export_to_tracing' => (bool) ($_ENV['HEALTH_EXPORT_TRACING'] ?? true),
                'endpoint_enabled' => (bool) ($_ENV['HEALTH_ENDPOINT_ENABLED'] ?? true),
                'endpoint_path' => $_ENV['HEALTH_ENDPOINT_PATH'] ?? '/health'
            ],
            'integration' => [
                'cross_correlation' => (bool) ($_ENV['OBSERVABILITY_CORRELATION'] ?? true),
                'unified_context' => (bool) ($_ENV['OBSERVABILITY_UNIFIED_CONTEXT'] ?? true),
                'shared_metadata' => (bool) ($_ENV['OBSERVABILITY_SHARED_METADATA'] ?? true),
                'automatic_alerting' => (bool) ($_ENV['OBSERVABILITY_AUTO_ALERTING'] ?? true)
            ]
        ], $config);

        $this->detectObservabilityCapabilities();
    }

    private ?ContainerInterface $container = null;

    public function register(): void
    {
        // This method should be called after setContainer()
        if ($this->container === null) {
            throw new \RuntimeException('Container must be set before calling register()');
        }

        // Register main ObservabilityManager
        $this->container->singleton('observability.manager', function () {
            $logger = $this->getLogger($this->container);
            return new ObservabilityManager($this->container, $logger, $this->config);
        });

        // Register individual observability aliases for convenience
        $this->container->alias('observability.manager', 'observability');
        $this->container->alias('observability.manager', 'obs');

        if ($this->config['enable_middleware']) {
            $this->registerMiddleware($this->container);
        }

        $this->registeredServices[] = 'observability_core';
    }

    public function boot(): void
    {
        if ($this->container === null) {
            throw new \RuntimeException('Container must be set before calling boot()');
        }

        if (!$this->observabilityAvailable) {
            $logger = $this->getLogger($this->container);
            $logger?->info('Observability services available with basic capabilities', [
                'health_monitoring' => true,
                'tracing_library' => class_exists('\\HighPerApp\\HighPer\\Tracing\\TracingManager'),
                'monitoring_library' => class_exists('\\HighPerApp\\HighPer\\Monitoring\\MonitoringManager')
            ]);
        }

        $this->initializeObservabilityManager($this->container);
        $this->registerReliabilityComponents($this->container);
        $this->setupIntegrationHooks($this->container);
        
        if ($this->config['health_monitoring']['endpoint_enabled']) {
            $this->registerHealthEndpoint($this->container);
        }
    }

    public function setContainer(ContainerInterface $container): void
    {
        $this->container = $container;
    }

    private function detectObservabilityCapabilities(): void
    {
        // At minimum, we always have health monitoring available
        $this->observabilityAvailable = true;
        
        // Check for tracing library
        $tracingAvailable = class_exists('\\HighPerApp\\HighPer\\Tracing\\TracingManager') ||
                           class_exists('\\HighPerApp\\HighPer\\Tracing\\Core\\EnhancedTracingManager');
        
        // Check for monitoring library  
        $monitoringAvailable = class_exists('\\HighPerApp\\HighPer\\Monitoring\\MonitoringManager') ||
                              class_exists('\\HighPerApp\\HighPer\\Monitoring\\Core\\PerformanceMonitor');

        if ($tracingAvailable) {
            $this->registeredServices[] = 'tracing_integration';
        }

        if ($monitoringAvailable) {
            $this->registeredServices[] = 'monitoring_integration';
        }

        $this->registeredServices[] = 'health_monitoring';
    }

    private function registerMiddleware(ContainerInterface $container): void
    {
        // Observability Middleware for automatic instrumentation
        $container->singleton('middleware.observability', function () use ($container) {
            return new class($container) {
                private ContainerInterface $container;

                public function __construct(ContainerInterface $container)
                {
                    $this->container = $container;
                }

                public function __invoke($request, $handler)
                {
                    $observability = $this->container->get('observability.manager');
                    
                    $uri = $request->getUri();
                    $method = $request->getMethod();
                    $operationName = "http.{$method} {$uri->getPath()}";

                    return $observability->traceOperation($operationName, function ($correlationId) use ($request, $handler) {
                        // Add correlation ID to request attributes
                        $request = $request->withAttribute('correlation_id', $correlationId);
                        
                        return $handler($request);
                    }, [
                        'http.method' => $method,
                        'http.url' => (string) $uri,
                        'http.scheme' => $uri->getScheme(),
                        'http.host' => $uri->getHost(),
                        'http.target' => $uri->getPath()
                    ]);
                }
            };
        });

        // Health Check Middleware for health endpoint
        if ($this->config['health_monitoring']['endpoint_enabled']) {
            $container->singleton('middleware.health_check', function () use ($container) {
                return new class($container) {
                    private ContainerInterface $container;

                    public function __construct(ContainerInterface $container)
                    {
                        $this->container = $container;
                    }

                    public function __invoke($request, $handler)
                    {
                        $uri = $request->getUri();
                        $healthPath = $this->container->get('config')['health_monitoring']['endpoint_path'] ?? '/health';
                        
                        if ($uri->getPath() === $healthPath) {
                            $observability = $this->container->get('observability.manager');
                            $healthData = $observability->performComprehensiveHealthCheck();
                            
                            $statusCode = $healthData['overall']['status'] === 'healthy' ? 200 : 503;
                            
                            return new \Amp\Http\Server\Response(
                                $statusCode,
                                ['Content-Type' => 'application/json'],
                                json_encode($healthData, JSON_PRETTY_PRINT)
                            );
                        }

                        return $handler($request);
                    }
                };
            });
        }

        $this->registeredServices[] = 'observability_middleware';
    }

    private function initializeObservabilityManager(ContainerInterface $container): void
    {
        try {
            $observabilityManager = $container->get('observability.manager');
            
            if ($this->config['enable_auto_instrumentation']) {
                $observabilityManager->startObservability();
            }

            // Integrate with external monitoring if available
            $observabilityManager->integrateWithExternalMonitoring();

            $logger = $this->getLogger($container);
            $logger?->info('ObservabilityManager initialized and started', [
                'auto_instrumentation' => $this->config['enable_auto_instrumentation'],
                'registered_services' => $this->registeredServices
            ]);

        } catch (\Throwable $e) {
            $logger = $this->getLogger($container);
            $logger?->error('Failed to initialize ObservabilityManager', [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function registerReliabilityComponents(ContainerInterface $container): void
    {
        // Auto-register reliability components if they exist
        $reliabilityComponents = [
            'circuit_breaker' => 'CircuitBreaker',
            'bulkhead' => 'BulkheadIsolation', 
            'self_healing' => 'SelfHealingManager'
        ];

        $observabilityManager = $container->get('observability.manager');

        foreach ($reliabilityComponents as $serviceName => $className) {
            if ($container->has($serviceName)) {
                try {
                    $component = $container->get($serviceName);
                    $observabilityManager->registerReliabilityComponent($serviceName, $component);
                } catch (\Throwable $e) {
                    $logger = $this->getLogger($container);
                    $logger?->warning("Failed to register reliability component: {$serviceName}", [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }

    private function setupIntegrationHooks(ContainerInterface $container): void
    {
        if (!$this->config['integration']['cross_correlation']) {
            return;
        }

        // Setup integration hooks between observability layers
        $observabilityManager = $container->get('observability.manager');
        
        // Additional integration setup can be added here
        // For example, setting up prometheus endpoints, health check automation, etc.
    }

    private function registerHealthEndpoint(ContainerInterface $container): void
    {
        // Health endpoint registration
        $container->singleton('health.endpoint', function () use ($container) {
            return new class($container) {
                private ContainerInterface $container;

                public function __construct(ContainerInterface $container)
                {
                    $this->container = $container;
                }

                public function getHealthData(): array
                {
                    $observability = $this->container->get('observability.manager');
                    return $observability->performComprehensiveHealthCheck();
                }

                public function getHealthStatus(): array
                {
                    $observability = $this->container->get('observability.manager');
                    $healthData = $observability->performComprehensiveHealthCheck();
                    
                    return [
                        'status' => $healthData['overall']['status'],
                        'timestamp' => $healthData['timestamp'],
                        'version' => '1.0.0' // Framework version
                    ];
                }

                public function getMetrics(): array
                {
                    $observability = $this->container->get('observability.manager');
                    return $observability->getUnifiedMetrics();
                }
            };
        });

        $this->registeredServices[] = 'health_endpoint';
    }

    private function getLogger(ContainerInterface $container): ?LoggerInterface
    {
        return $container->has(LoggerInterface::class) 
            ? $container->get(LoggerInterface::class) 
            : null;
    }

    public function provides(): array
    {
        $services = [
            'observability.manager',
            'observability',
            'obs'
        ];

        if ($this->config['enable_middleware']) {
            $services = array_merge($services, [
                'middleware.observability'
            ]);

            if ($this->config['health_monitoring']['endpoint_enabled']) {
                $services = array_merge($services, [
                    'middleware.health_check',
                    'health.endpoint'
                ]);
            }
        }

        return $services;
    }

    public function isRegistered(): bool
    {
        return !empty($this->registeredServices);
    }

    public function getRegisteredServices(): array
    {
        return $this->registeredServices;
    }

    public function isObservabilityAvailable(): bool
    {
        return $this->observabilityAvailable;
    }

    public function getConfiguration(): array
    {
        return [
            'observability_available' => $this->observabilityAvailable,
            'registered_services' => $this->registeredServices,
            'configuration' => $this->config,
            'capabilities' => [
                'tracing' => class_exists('\\HighPerApp\\HighPer\\Tracing\\TracingManager'),
                'monitoring' => class_exists('\\HighPerApp\\HighPer\\Monitoring\\MonitoringManager'),
                'health_monitoring' => true // Always available
            ]
        ];
    }
}