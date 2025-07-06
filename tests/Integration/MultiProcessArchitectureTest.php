<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Tests\Integration;

use PHPUnit\Framework\TestCase;
use HighPerApp\HighPer\Foundation\Application;
use HighPerApp\HighPer\Foundation\ProcessManager;
use HighPerApp\HighPer\Foundation\HybridEventLoop;
use HighPerApp\HighPer\Foundation\AdaptiveSerializer;

/**
 * Multi-Process Architecture Integration Tests
 * 
 * Comprehensive test suite for the hybrid multi-process + async architecture,
 * zero-downtime deployment capabilities, and performance optimizations.
 */
class MultiProcessArchitectureTest extends TestCase
{
    private Application $app;
    private ProcessManager $processManager;
    private HybridEventLoop $eventLoop;

    protected function setUp(): void
    {
        $this->app = new Application([
            'testing' => true,
            'environment' => 'test'
        ]);
        
        $this->app->bootstrap();
        
        $this->processManager = new ProcessManager($this->app);
        $this->eventLoop = new HybridEventLoop(
            $this->app->getLogger(),
            ['auto_switch' => false] // Disable for testing
        );
    }

    protected function tearDown(): void
    {
        if ($this->processManager->isRunning()) {
            $this->processManager->stop();
        }
    }

    public function testProcessManagerInitialization(): void
    {
        $this->assertFalse($this->processManager->isRunning());
        $this->assertGreaterThan(0, $this->processManager->getWorkersCount());
        
        $config = $this->processManager->getConfig();
        $this->assertArrayHasKey('workers', $config);
        $this->assertArrayHasKey('deployment_strategy', $config);
        $this->assertArrayHasKey('max_connections_per_worker', $config);
    }

    public function testZeroDowntimeCapabilities(): void
    {
        // Test that zero-downtime configuration is properly detected
        $stats = $this->processManager->getStats();
        
        $this->assertArrayHasKey('zero_downtime_enabled', $stats);
        $this->assertArrayHasKey('deployment_strategy', $stats);
        $this->assertContains($stats['deployment_strategy'], ['blue_green', 'rolling', 'socket_handoff']);
    }

    public function testWorkerProcessSpawning(): void
    {
        $initialCount = $this->processManager->getWorkersCount();
        
        // Start process manager
        $this->processManager->start();
        
        $this->assertTrue($this->processManager->isRunning());
        
        // Verify workers are spawned
        $stats = $this->processManager->getStats();
        $this->assertGreaterThan(0, $stats['worker_count']);
        $this->assertArrayHasKey('workers', $stats);
        
        // Stop process manager
        $this->processManager->stop();
        $this->assertFalse($this->processManager->isRunning());
    }

    public function testWorkerScaling(): void
    {
        $this->processManager->start();
        
        $initialCount = $this->processManager->getWorkersCount();
        
        // Scale up
        $this->processManager->scaleWorkers($initialCount + 2);
        $this->assertEquals($initialCount + 2, $this->processManager->getWorkersCount());
        
        // Scale down
        $this->processManager->scaleWorkers($initialCount);
        $this->assertEquals($initialCount, $this->processManager->getWorkersCount());
        
        $this->processManager->stop();
    }

    public function testGracefulShutdown(): void
    {
        $this->processManager->start();
        $this->assertTrue($this->processManager->isRunning());
        
        $startTime = microtime(true);
        $this->processManager->stop();
        $stopTime = microtime(true);
        
        $this->assertFalse($this->processManager->isRunning());
        
        // Should complete shutdown within reasonable time
        $shutdownTime = $stopTime - $startTime;
        $this->assertLessThan(15.0, $shutdownTime, 'Graceful shutdown took too long');
    }

    public function testHybridEventLoopDetection(): void
    {
        $metrics = $this->eventLoop->getMetrics();
        
        $this->assertArrayHasKey('uv_available', $metrics);
        $this->assertArrayHasKey('uv_enabled', $metrics);
        $this->assertArrayHasKey('connection_count', $metrics);
        $this->assertIsBool($metrics['uv_available']);
        $this->assertIsBool($metrics['uv_enabled']);
    }

    public function testEventLoopAutoOptimization(): void
    {
        // Test connection count threshold behavior
        $this->eventLoop->addConnectionCount(500);
        $this->assertEquals(500, $this->eventLoop->getConnectionCount());
        
        $this->eventLoop->addConnectionCount(600);
        $this->assertEquals(1100, $this->eventLoop->getConnectionCount());
        
        $this->eventLoop->removeConnectionCount(100);
        $this->assertEquals(1000, $this->eventLoop->getConnectionCount());
        
        // Test high performance mode
        $this->eventLoop->setHighPerformanceMode(true);
        $metrics = $this->eventLoop->getMetrics();
        $this->assertTrue($metrics['high_performance_mode']);
    }

    public function testEventLoopConfiguration(): void
    {
        $config = $this->eventLoop->getConfiguration();
        
        $this->assertArrayHasKey('uv_enabled', $config);
        $this->assertArrayHasKey('auto_switch', $config);
        $this->assertArrayHasKey('thresholds', $config);
        $this->assertArrayHasKey('monitoring', $config);
        
        // Test configuration update
        $newConfig = ['rust_threshold' => 2048];
        $this->eventLoop->setConfiguration($newConfig);
        
        $updatedConfig = $this->eventLoop->getConfiguration();
        $this->assertEquals(2048, $updatedConfig['rust_threshold']);
    }

    public function testAdaptiveSerializerInitialization(): void
    {
        $serializer = new AdaptiveSerializer(null, $this->app->getLogger());
        
        $this->assertContains('json', $serializer->getAvailableFormats());
        $this->assertFalse($serializer->isRustAvailable());
        
        $stats = $serializer->getStats();
        $this->assertArrayHasKey('rust_available', $stats);
        $this->assertArrayHasKey('available_formats', $stats);
        $this->assertArrayHasKey('default_format', $stats);
    }

    public function testAdaptiveSerializerFormats(): void
    {
        $serializer = new AdaptiveSerializer(null, $this->app->getLogger());
        
        $testData = [
            'string' => 'Hello World',
            'number' => 42,
            'array' => [1, 2, 3],
            'object' => ['key' => 'value'],
            'boolean' => true,
            'null' => null
        ];
        
        // Test JSON serialization
        $jsonSerialized = $serializer->serialize($testData, 'json');
        $this->assertIsString($jsonSerialized);
        
        $jsonDeserialized = $serializer->deserialize($jsonSerialized, 'json');
        $this->assertEquals($testData, $jsonDeserialized);
        
        // Test auto-format detection
        $autoSerialized = $serializer->serialize($testData);
        $autoDeserialized = $serializer->deserialize($autoSerialized);
        $this->assertEquals($testData, $autoDeserialized);
    }

    public function testAdaptiveSerializerValidation(): void
    {
        $serializer = new AdaptiveSerializer(null, $this->app->getLogger());
        
        $validJson = '{"key": "value"}';
        $invalidJson = '{"key": value}'; // Missing quotes
        
        $this->assertTrue($serializer->validate($validJson, 'json'));
        $this->assertFalse($serializer->validate($invalidJson, 'json'));
    }

    public function testAdaptiveSerializerStats(): void
    {
        $serializer = new AdaptiveSerializer(null, $this->app->getLogger());
        
        $initialStats = $serializer->getStats();
        $this->assertEquals(0, $initialStats['serialized']);
        $this->assertEquals(0, $initialStats['deserialized']);
        
        // Perform operations
        $data = ['test' => 'data'];
        $serialized = $serializer->serialize($data);
        $deserialized = $serializer->deserialize($serialized);
        
        $finalStats = $serializer->getStats();
        $this->assertEquals(1, $finalStats['serialized']);
        $this->assertEquals(1, $finalStats['deserialized']);
    }

    public function testIntegratedPerformanceMetrics(): void
    {
        $this->processManager->start();
        
        // Get initial metrics
        $processStats = $this->processManager->getStats();
        $eventLoopMetrics = $this->eventLoop->getMetrics();
        
        $this->assertArrayHasKey('memory_usage', $processStats);
        $this->assertArrayHasKey('worker_count', $processStats);
        $this->assertArrayHasKey('running', $processStats);
        
        $this->assertArrayHasKey('memory_usage', $eventLoopMetrics);
        $this->assertArrayHasKey('uv_usage', $eventLoopMetrics);
        $this->assertArrayHasKey('revolt_usage', $eventLoopMetrics);
        
        $this->processManager->stop();
    }

    public function testEnvironmentBasedConfiguration(): void
    {
        // Test that environment variables are properly loaded
        $oldValue = $_ENV['WORKER_COUNT'] ?? null;
        $_ENV['WORKER_COUNT'] = '6';
        
        $testApp = new Application(['testing' => true]);
        $testApp->bootstrap();
        
        $testProcessManager = new ProcessManager($testApp);
        $config = $testProcessManager->getConfig();
        
        $this->assertEquals(6, $config['workers']);
        
        // Restore environment
        if ($oldValue !== null) {
            $_ENV['WORKER_COUNT'] = $oldValue;
        } else {
            unset($_ENV['WORKER_COUNT']);
        }
    }

    public function testDeploymentStrategyConfiguration(): void
    {
        $strategies = ['blue_green', 'rolling'];
        
        foreach ($strategies as $strategy) {
            $_ENV['DEPLOYMENT_STRATEGY'] = $strategy;
            
            $testApp = new Application(['testing' => true]);
            $testApp->bootstrap();
            
            $testProcessManager = new ProcessManager($testApp);
            $config = $testProcessManager->getConfig();
            
            $this->assertEquals($strategy, $config['deployment_strategy']);
        }
        
        unset($_ENV['DEPLOYMENT_STRATEGY']);
    }

    public function testMemoryUsageMonitoring(): void
    {
        $initialMemory = memory_get_usage(true);
        
        $this->processManager->start();
        
        $stats = $this->processManager->getStats();
        $this->assertArrayHasKey('memory_usage', $stats);
        $this->assertIsInt($stats['memory_usage']);
        $this->assertGreaterThanOrEqual($initialMemory, $stats['memory_usage']);
        
        $this->processManager->stop();
    }

    public function testConcurrentOperations(): void
    {
        $serializer = new AdaptiveSerializer(null, $this->app->getLogger());
        
        // Test concurrent serialization/deserialization
        $operations = [];
        for ($i = 0; $i < 100; $i++) {
            $data = ['iteration' => $i, 'data' => str_repeat('x', $i * 10)];
            $serialized = $serializer->serialize($data);
            $deserialized = $serializer->deserialize($serialized);
            $operations[] = $deserialized;
        }
        
        $this->assertCount(100, $operations);
        
        $stats = $serializer->getStats();
        $this->assertEquals(100, $stats['serialized']);
        $this->assertEquals(100, $stats['deserialized']);
    }

    public function testErrorHandlingAndRecovery(): void
    {
        $serializer = new AdaptiveSerializer(null, $this->app->getLogger());
        
        // Test invalid data handling
        try {
            $result = $serializer->deserialize('invalid json data', 'json');
            $this->fail('Expected exception was not thrown');
        } catch (\JsonException $e) {
            $this->assertStringContains('Syntax error', $e->getMessage());
        }
        
        // Test that serializer continues to work after error
        $validData = ['test' => 'recovery'];
        $result = $serializer->serialize($validData);
        $this->assertIsString($result);
    }

    public function testResourceCleanup(): void
    {
        $this->processManager->start();
        $initialPids = $this->processManager->getWorkerPids();
        
        $this->processManager->stop();
        
        // Verify all processes are cleaned up
        foreach ($initialPids as $pid) {
            // Check if process still exists
            $exists = posix_kill($pid, 0);
            $this->assertFalse($exists, "Process {$pid} was not properly cleaned up");
        }
    }
}