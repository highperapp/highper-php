<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Tests\Observability;

use PHPUnit\Framework\TestCase;
use HighPerApp\HighPer\Observability\ObservabilityManager;
use HighPerApp\HighPer\ServiceProvider\ObservabilityServiceProvider;
use HighPerApp\HighPer\Reliability\CircuitBreaker;
use HighPerApp\HighPer\Reliability\BulkheadIsolation;
use HighPerApp\HighPer\Reliability\SelfHealingManager;
use HighPerApp\HighPer\Foundation\RustFFIManager;
use HighPerApp\HighPer\Foundation\AsyncLogger;
use HighPerApp\HighPer\Foundation\Container;

/**
 * Observability Integration Tests
 * 
 * Comprehensive testing of the unified observability layer:
 * - Integration between tracing, monitoring, and health monitoring
 * - Auto-detection of standalone libraries
 * - Cross-cutting observability concerns
 * - Middleware integration and automatic instrumentation
 * - Service provider registration and configuration
 * - Performance impact assessment
 */
class ObservabilityIntegrationTest extends TestCase
{
    private Container $container;
    private AsyncLogger $logger;
    private RustFFIManager $ffiManager;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->logger = new AsyncLogger();
        $this->ffiManager = new RustFFIManager();

        // Register basic services
        $this->container->singleton('logger', fn() => $this->logger);
        $this->container->singleton('ffi.manager', fn() => $this->ffiManager);
        $this->container->alias('logger', \HighPerApp\HighPer\Contracts\LoggerInterface::class);
    }

    /**
     * @group observability
     * @group integration
     */
    public function testObservabilityManagerInitialization(): void
    {
        $config = [
            'tracing' => [
                'enabled' => true,
                'auto_detect' => true,
                'service_name' => 'test-service'
            ],
            'monitoring' => [
                'enabled' => true,
                'auto_detect' => true,
                'dashboard_enabled' => false
            ],
            'health_monitoring' => [
                'enabled' => true,
                'check_interval' => 10
            ]
        ];

        $observabilityManager = new ObservabilityManager(
            $this->container,
            $this->logger,
            $config
        );

        // Test initialization
        $this->assertInstanceOf(ObservabilityManager::class, $observabilityManager);
        $this->assertTrue($observabilityManager->isObservabilityActive());

        // Test starting observability
        $observabilityManager->startObservability();
        
        $stats = $observabilityManager->getStats();
        $this->assertArrayHasKey('observability_start_time', $stats);
        $this->assertGreaterThan(0, $stats['observability_start_time']);

        $observabilityManager->stopObservability();
    }

    /**
     * @group observability
     * @group service_provider
     */
    public function testObservabilityServiceProvider(): void
    {
        $config = [
            'auto_detect' => true,
            'enable_middleware' => true,
            'enable_auto_instrumentation' => true,
            'health_monitoring' => [
                'endpoint_enabled' => true,
                'endpoint_path' => '/health'
            ]
        ];

        $serviceProvider = new ObservabilityServiceProvider($config);

        // Test service registration
        $serviceProvider->register($this->container);
        
        $this->assertTrue($this->container->has('observability.manager'));
        $this->assertTrue($this->container->has('observability'));
        $this->assertTrue($this->container->has('obs'));
        $this->assertTrue($this->container->has('middleware.observability'));

        // Test service provider boot
        $serviceProvider->boot($this->container);

        // Verify observability manager is working
        $observabilityManager = $this->container->get('observability.manager');
        $this->assertInstanceOf(ObservabilityManager::class, $observabilityManager);

        // Test service provider configuration
        $configuration = $serviceProvider->getConfiguration();
        $this->assertArrayHasKey('observability_available', $configuration);
        $this->assertArrayHasKey('capabilities', $configuration);
        $this->assertTrue($configuration['capabilities']['health_monitoring']);
    }

    /**
     * @group observability
     * @group trace_operation
     */
    public function testTraceOperationIntegration(): void
    {
        $observabilityManager = new ObservabilityManager(
            $this->container,
            $this->logger
        );

        $observabilityManager->startObservability();

        // Test successful operation tracing
        $result = $observabilityManager->traceOperation(
            'test_operation',
            function ($correlationId) {
                $this->assertIsString($correlationId);
                $this->assertStringStartsWith('obs_', $correlationId);
                return 'operation_success';
            },
            ['test.attribute' => 'test_value']
        );

        $this->assertEquals('operation_success', $result);

        // Test failed operation tracing
        $exception = null;
        try {
            $observabilityManager->traceOperation(
                'failing_operation',
                function ($correlationId) {
                    throw new \RuntimeException('Operation failed');
                }
            );
        } catch (\RuntimeException $e) {
            $exception = $e;
        }

        $this->assertNotNull($exception);
        $this->assertEquals('Operation failed', $exception->getMessage());

        // Verify stats were recorded
        $stats = $observabilityManager->getStats();
        $this->assertGreaterThan(0, $stats['successful_operations']);
        $this->assertGreaterThan(0, $stats['failed_operations']);

        $observabilityManager->stopObservability();
    }

    /**
     * @group observability
     * @group health_check
     */
    public function testComprehensiveHealthCheck(): void
    {
        $observabilityManager = new ObservabilityManager(
            $this->container,
            $this->logger,
            [
                'health_monitoring' => [
                    'enabled' => true,
                    'health_threshold' => 99.999
                ]
            ]
        );

        // Register reliability components for testing
        $circuitBreaker = new CircuitBreaker(
            'test_circuit_breaker',
            ['failure_threshold' => 5],
            $this->logger,
            $this->ffiManager
        );

        $bulkhead = new BulkheadIsolation(
            $this->logger,
            $this->ffiManager,
            ['default_pool_size' => 10]
        );

        $selfHealing = new SelfHealingManager(
            $this->logger,
            $this->ffiManager
        );

        $observabilityManager->registerReliabilityComponent('circuit_breaker', $circuitBreaker);
        $observabilityManager->registerReliabilityComponent('bulkhead', $bulkhead);
        $observabilityManager->registerReliabilityComponent('self_healing', $selfHealing);

        $observabilityManager->startObservability();

        // Perform comprehensive health check
        $healthData = $observabilityManager->performComprehensiveHealthCheck();

        // Validate health check structure
        $this->assertArrayHasKey('timestamp', $healthData);
        $this->assertArrayHasKey('correlation_id', $healthData);
        $this->assertArrayHasKey('framework_health', $healthData);
        $this->assertArrayHasKey('tracing_health', $healthData);
        $this->assertArrayHasKey('monitoring_health', $healthData);
        $this->assertArrayHasKey('integration_health', $healthData);
        $this->assertArrayHasKey('overall', $healthData);

        // Validate framework health (should include registered components)
        $frameworkHealth = $healthData['framework_health'];
        $this->assertArrayHasKey('components', $frameworkHealth);
        $this->assertArrayHasKey('circuit_breaker', $frameworkHealth['components']);
        $this->assertArrayHasKey('bulkhead', $frameworkHealth['components']);
        $this->assertArrayHasKey('self_healing', $frameworkHealth['components']);

        // Validate overall health
        $overall = $healthData['overall'];
        $this->assertArrayHasKey('status', $overall);
        $this->assertArrayHasKey('health_percentage', $overall);
        $this->assertArrayHasKey('observability_coverage', $overall);

        $observabilityManager->stopObservability();
    }

    /**
     * @group observability
     * @group metrics
     */
    public function testUnifiedMetricsCollection(): void
    {
        $observabilityManager = new ObservabilityManager(
            $this->container,
            $this->logger
        );

        $observabilityManager->startObservability();

        // Perform some operations to generate metrics
        for ($i = 0; $i < 5; $i++) {
            $observabilityManager->traceOperation(
                "test_operation_{$i}",
                function () {
                    usleep(1000); // 1ms
                    return "result_{$i}";
                }
            );
        }

        // Get unified metrics
        $metrics = $observabilityManager->getUnifiedMetrics();

        // Validate metrics structure
        $this->assertArrayHasKey('highper_framework_health_percentage', $metrics);
        $this->assertArrayHasKey('highper_framework_five_nines_compliance', $metrics);
        $this->assertArrayHasKey('observability_operations_traced', $metrics);
        $this->assertArrayHasKey('observability_operations_monitored', $metrics);

        // Validate metric values
        $this->assertEquals(5, $metrics['observability_operations_traced']);
        $this->assertIsFloat($metrics['highper_framework_health_percentage']);
        $this->assertIsBool($metrics['highper_framework_five_nines_compliance']);

        $observabilityManager->stopObservability();
    }

    /**
     * @group observability
     * @group middleware
     */
    public function testObservabilityMiddleware(): void
    {
        $serviceProvider = new ObservabilityServiceProvider([
            'enable_middleware' => true,
            'enable_auto_instrumentation' => true
        ]);

        $serviceProvider->register($this->container);
        $serviceProvider->boot($this->container);

        // Get middleware
        $middleware = $this->container->get('middleware.observability');
        $this->assertIsObject($middleware);

        // Create mock request
        $mockRequest = new class {
            private array $attributes = [];

            public function getUri(): object
            {
                return new class {
                    public function getPath(): string { return '/test/endpoint'; }
                    public function getScheme(): string { return 'https'; }
                    public function getHost(): string { return 'localhost'; }
                    public function __toString(): string { return 'https://localhost/test/endpoint'; }
                };
            }

            public function getMethod(): string { return 'GET'; }
            
            public function withAttribute(string $name, $value): self
            {
                $this->attributes[$name] = $value;
                return $this;
            }

            public function getAttribute(string $name, $default = null)
            {
                return $this->attributes[$name] ?? $default;
            }
        };

        // Create mock handler
        $handlerCalled = false;
        $correlationIdReceived = null;
        $mockHandler = function ($request) use (&$handlerCalled, &$correlationIdReceived) {
            $handlerCalled = true;
            $correlationIdReceived = $request->getAttribute('correlation_id');
            return 'handler_response';
        };

        // Execute middleware
        $response = $middleware($mockRequest, $mockHandler);

        // Verify middleware executed correctly
        $this->assertTrue($handlerCalled);
        $this->assertEquals('handler_response', $response);
        $this->assertNotNull($correlationIdReceived);
        $this->assertStringStartsWith('obs_', $correlationIdReceived);
    }

    /**
     * @group observability
     * @group health_endpoint
     */
    public function testHealthEndpointMiddleware(): void
    {
        $serviceProvider = new ObservabilityServiceProvider([
            'health_monitoring' => [
                'endpoint_enabled' => true,
                'endpoint_path' => '/health'
            ]
        ]);

        $serviceProvider->register($this->container);
        $this->container->singleton('config', fn() => $serviceProvider->getConfiguration()['configuration']);
        $serviceProvider->boot($this->container);

        // Get health middleware
        $healthMiddleware = $this->container->get('middleware.health_check');
        $this->assertIsObject($healthMiddleware);

        // Create mock health request
        $mockHealthRequest = new class {
            public function getUri(): object
            {
                return new class {
                    public function getPath(): string { return '/health'; }
                };
            }
        };

        // Create mock handler (should not be called for health endpoint)
        $handlerCalled = false;
        $mockHandler = function ($request) use (&$handlerCalled) {
            $handlerCalled = true;
            return 'handler_response';
        };

        // Execute health middleware
        $response = $healthMiddleware($mockHealthRequest, $mockHandler);

        // Verify health endpoint response
        $this->assertFalse($handlerCalled); // Handler should not be called
        $this->assertIsObject($response);

        // Test normal request (non-health endpoint)
        $mockNormalRequest = new class {
            public function getUri(): object
            {
                return new class {
                    public function getPath(): string { return '/api/users'; }
                };
            }
        };

        $handlerCalled = false;
        $response = $healthMiddleware($mockNormalRequest, $mockHandler);

        $this->assertTrue($handlerCalled); // Handler should be called for normal requests
        $this->assertEquals('handler_response', $response);
    }

    /**
     * @group observability
     * @group integration_external
     */
    public function testExternalMonitoringIntegration(): void
    {
        // Create mock external monitor
        $callbacksReceived = [];
        $mockExternalMonitor = new class($callbacksReceived) {
            private array $callbacks;

            public function __construct(array &$callbacks)
            {
                $this->callbacks = &$callbacks;
            }

            public function recordHealthCheck(array $healthData): void
            {
                $this->callbacks[] = ['type' => 'health_check', 'data' => $healthData];
            }

            public function recordFailure(array $failureData): void
            {
                $this->callbacks[] = ['type' => 'failure', 'data' => $failureData];
            }

            public function recordRecovery(array $recoveryData): void
            {
                $this->callbacks[] = ['type' => 'recovery', 'data' => $recoveryData];
            }
        };

        $observabilityManager = new ObservabilityManager(
            $this->container,
            $this->logger,
            [
                'health_monitoring' => [
                    'integration_hooks' => true
                ]
            ]
        );

        // Integrate with external monitor
        $observabilityManager->integrateWithExternalMonitoring($mockExternalMonitor);
        $observabilityManager->startObservability();

        // Perform health check to trigger callbacks
        $healthData = $observabilityManager->performComprehensiveHealthCheck();

        // Verify external monitoring integration
        $this->assertNotEmpty($callbacksReceived);
        $this->assertEquals('health_check', $callbacksReceived[0]['type']);
        $this->assertArrayHasKey('data', $callbacksReceived[0]);

        $observabilityManager->stopObservability();
    }

    /**
     * @group observability
     * @group performance
     */
    public function testObservabilityPerformanceImpact(): void
    {
        // Baseline operation without observability
        $baselineOperation = function () {
            $sum = 0;
            for ($i = 0; $i < 1000; $i++) {
                $sum += $i;
            }
            return $sum;
        };

        $baselineStart = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $baselineOperation();
        }
        $baselineTime = microtime(true) - $baselineStart;

        // Operation with full observability
        $observabilityManager = new ObservabilityManager(
            $this->container,
            $this->logger,
            [
                'tracing' => ['enabled' => true],
                'monitoring' => ['enabled' => true],
                'health_monitoring' => ['enabled' => true]
            ]
        );

        $observabilityManager->startObservability();

        $observabilityStart = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $observabilityManager->traceOperation("baseline_operation_{$i}", $baselineOperation);
        }
        $observabilityTime = microtime(true) - $observabilityStart;

        // Calculate overhead
        $overhead = (($observabilityTime - $baselineTime) / $baselineTime) * 100;

        // Observability overhead should be reasonable (< 100% for 100 operations)
        $this->assertLessThan(100, $overhead, 'Observability overhead should be reasonable');

        // Test memory usage impact
        $beforeMemory = memory_get_usage(true);
        
        // Create additional observability components
        for ($i = 0; $i < 5; $i++) {
            $circuitBreaker = new CircuitBreaker("test_cb_{$i}", [], $this->logger, $this->ffiManager);
            $observabilityManager->registerReliabilityComponent("circuit_breaker_{$i}", $circuitBreaker);
        }
        
        $afterMemory = memory_get_usage(true);
        $memoryIncrease = ($afterMemory - $beforeMemory) / 1024 / 1024; // MB
        
        // Memory increase should be reasonable (< 5MB for 5 components)
        $this->assertLessThan(5, $memoryIncrease, 'Memory usage should be reasonable');

        $this->logger->info('Observability performance test completed', [
            'baseline_time' => round($baselineTime * 1000, 2) . 'ms',
            'observability_time' => round($observabilityTime * 1000, 2) . 'ms',
            'overhead_percentage' => round($overhead, 2) . '%',
            'memory_increase_mb' => round($memoryIncrease, 2)
        ]);

        $observabilityManager->stopObservability();
    }

    /**
     * @group observability
     * @group auto_detection
     */
    public function testLibraryAutoDetection(): void
    {
        $observabilityManager = new ObservabilityManager(
            $this->container,
            $this->logger,
            [
                'tracing' => ['auto_detect' => true],
                'monitoring' => ['auto_detect' => true]
            ]
        );

        $stats = $observabilityManager->getStats();
        
        // Verify auto-detection results
        $this->assertArrayHasKey('tracing_available', $stats);
        $this->assertArrayHasKey('monitoring_available', $stats);
        $this->assertArrayHasKey('health_monitoring_available', $stats);

        // Health monitoring should always be available
        $this->assertTrue($stats['health_monitoring_available']);

        // Verify observability is active even with basic capabilities
        $this->assertTrue($observabilityManager->isObservabilityActive());
    }

    /**
     * @group observability
     * @group configuration
     */
    public function testConfigurationManagement(): void
    {
        $config = [
            'tracing' => [
                'enabled' => true,
                'service_name' => 'test-service',
                'sampling_ratio' => 0.5,
                'auto_instrumentation' => [
                    'http' => true,
                    'database' => false,
                    'cache' => true
                ]
            ],
            'monitoring' => [
                'enabled' => false,
                'dashboard_enabled' => true
            ],
            'health_monitoring' => [
                'enabled' => true,
                'check_interval' => 60,
                'health_threshold' => 99.9
            ],
            'integration' => [
                'cross_correlation' => true,
                'unified_context' => false
            ]
        ];

        $serviceProvider = new ObservabilityServiceProvider($config);
        $configuration = $serviceProvider->getConfiguration();

        // Verify configuration structure
        $this->assertArrayHasKey('configuration', $configuration);
        $configData = $configuration['configuration'];

        $this->assertEquals('test-service', $configData['tracing']['service_name']);
        $this->assertEquals(0.5, $configData['tracing']['sampling_ratio']);
        $this->assertTrue($configData['tracing']['auto_instrumentation']['http']);
        $this->assertFalse($configData['tracing']['auto_instrumentation']['database']);
        $this->assertFalse($configData['monitoring']['enabled']);
        $this->assertEquals(60, $configData['health_monitoring']['check_interval']);
        $this->assertEquals(99.9, $configData['health_monitoring']['health_threshold']);

        // Test environment variable integration
        $_ENV['HEALTH_THRESHOLD'] = '99.999';
        $_ENV['TRACING_SAMPLING_RATIO'] = '1.0';

        $envServiceProvider = new ObservabilityServiceProvider();
        $envConfiguration = $envServiceProvider->getConfiguration();
        $envConfigData = $envConfiguration['configuration'];

        $this->assertEquals(99.999, $envConfigData['health_monitoring']['health_threshold']);
        $this->assertEquals(1.0, $envConfigData['tracing']['sampling_ratio']);

        // Cleanup environment variables
        unset($_ENV['HEALTH_THRESHOLD'], $_ENV['TRACING_SAMPLING_RATIO']);
    }

    protected function tearDown(): void
    {
        // Cleanup resources
        gc_collect_cycles();
    }
}