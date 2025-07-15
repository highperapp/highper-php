<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use HighPerApp\HighPer\Foundation\Application;
use HighPerApp\HighPer\Contracts\ContainerInterface;

/**
 * Base Test Case for HighPer Framework Tests
 * 
 * Provides common utilities and helpers for framework testing including TCP integration
 */
abstract class TestCase extends PHPUnitTestCase
{
    protected ?Application $app = null;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Set test environment
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['APP_DEBUG'] = 'true';
    }

    protected function tearDown(): void
    {
        $this->app = null;
        parent::tearDown();
    }

    /**
     * Create a test application instance
     */
    protected function createApplication(array $config = []): Application
    {
        $defaultConfig = [
            'app' => [
                'name' => 'HighPer Test Application',
                'env' => 'testing',
                'debug' => true
            ],
            'server' => [
                'host' => '127.0.0.1',
                'port' => 8080,
                'protocols' => ['http']
            ]
        ];
        
        $mergedConfig = array_merge_recursive($defaultConfig, $config);
        
        $this->app = new Application($mergedConfig);
        
        return $this->app;
    }

    /**
     * Get the application container
     */
    protected function getContainer(): ContainerInterface
    {
        if (!$this->app) {
            $this->createApplication();
        }
        
        return $this->app->getContainer();
    }

    /**
     * Check if TCP package is available for testing
     */
    protected function isTCPPackageAvailable(): bool
    {
        return class_exists('\\HighPerApp\\HighPer\\TCP\\TCPServiceProvider');
    }

    /**
     * Skip test if TCP package is not available
     */
    protected function requireTCPPackage(): void
    {
        if (!$this->isTCPPackageAvailable()) {
            $this->markTestSkipped('TCP package is not available for testing');
        }
    }

    /**
     * Assert that a service is properly registered in the container
     */
    protected function assertServiceRegistered(string $serviceId, ?string $expectedClass = null): void
    {
        $container = $this->getContainer();
        
        $this->assertTrue($container->has($serviceId), "Service '{$serviceId}' should be registered in container");
        
        $service = $container->get($serviceId);
        $this->assertNotNull($service, "Service '{$serviceId}' should not be null");
        
        if ($expectedClass) {
            $this->assertInstanceOf($expectedClass, $service, "Service '{$serviceId}' should be instance of {$expectedClass}");
        }
    }

    /**
     * Assert that TCP services are properly integrated
     */
    protected function assertTCPIntegration(): void
    {
        $this->requireTCPPackage();
        
        $container = $this->getContainer();
        
        // Check TCP provider registration
        $this->assertServiceRegistered('tcp.provider', '\\HighPerApp\\HighPer\\TCP\\TCPServiceProvider');
        
        // Check TCP services registration
        $this->assertServiceRegistered('tcp.server');
        $this->assertServiceRegistered('tcp.client.pool');
        $this->assertServiceRegistered('tcp.manager');
        
        // Check TCP provider capabilities
        $tcpProvider = $container->get('tcp.provider');
        $this->assertTrue($tcpProvider->isServerAvailable() || $tcpProvider->isClientAvailable(), 
            'TCP provider should have server or client available');
    }

    /**
     * Measure execution time of a callback
     */
    protected function measureExecutionTime(callable $callback): float
    {
        $start = microtime(true);
        $callback();
        return microtime(true) - $start;
    }

    /**
     * Assert that execution time is within expected range
     */
    protected function assertExecutionTimeWithin(float $maxSeconds, callable $callback, string $message = ''): void
    {
        $executionTime = $this->measureExecutionTime($callback);
        $this->assertLessThanOrEqual($maxSeconds, $executionTime, 
            $message ?: "Execution should complete within {$maxSeconds}s, took {$executionTime}s");
    }

    /**
     * Assert that memory usage is reasonable
     */
    protected function assertMemoryUsageReasonable(callable $callback, int $maxIncreaseBytes = 10 * 1024 * 1024): void
    {
        gc_collect_cycles();
        $initialMemory = memory_get_usage(true);
        
        $callback();
        
        gc_collect_cycles();
        $finalMemory = memory_get_usage(true);
        
        $memoryIncrease = $finalMemory - $initialMemory;
        $this->assertLessThanOrEqual($maxIncreaseBytes, $memoryIncrease,
            "Memory increase should be less than " . ($maxIncreaseBytes / 1024 / 1024) . "MB, was " . 
            ($memoryIncrease / 1024 / 1024) . "MB");
    }

    /**
     * Create test configuration for TCP
     */
    protected function createTCPTestConfig(): array
    {
        return [
            'tcp' => [
                'server' => [
                    'host' => '127.0.0.1',
                    'port' => 8888,
                    'max_connections' => 100,
                    'rust_acceleration' => false, // Disable for tests
                    'fallback_to_php' => true
                ],
                'client' => [
                    'min_connections' => 1,
                    'max_connections' => 10,
                    'circuit_breaker' => [
                        'enabled' => true,
                        'failure_threshold' => 3,
                        'recovery_timeout' => 5
                    ],
                    'rust_acceleration' => false, // Disable for tests
                    'fallback_to_php' => true
                ]
            ]
        ];
    }

    /**
     * Get available port for testing
     */
    protected function getAvailablePort(int $startPort = 8888): int
    {
        for ($port = $startPort; $port < $startPort + 100; $port++) {
            $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if ($socket === false) {
                continue;
            }
            
            $result = @socket_bind($socket, '127.0.0.1', $port);
            socket_close($socket);
            
            if ($result) {
                return $port;
            }
        }
        
        throw new \RuntimeException('No available ports found for testing');
    }
}