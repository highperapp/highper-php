<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use HighPerApp\HighPer\Foundation\HybridEventLoop;
use HighPerApp\HighPer\Contracts\LoggerInterface;

/**
 * HybridEventLoop Unit Tests
 * 
 * Tests the php-uv auto-detection, threshold-based switching,
 * and transparent fallback mechanisms.
 */
class HybridEventLoopTest extends TestCase
{
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testInitializationWithDefaultConfiguration(): void
    {
        $eventLoop = new HybridEventLoop($this->logger);
        
        $config = $eventLoop->getConfiguration();
        $this->assertArrayHasKey('uv_enabled', $config);
        $this->assertArrayHasKey('auto_switch', $config);
        $this->assertArrayHasKey('thresholds', $config);
        $this->assertArrayHasKey('monitoring', $config);
        
        $this->assertIsBool($config['uv_enabled']);
        $this->assertIsBool($config['auto_switch']);
        $this->assertIsArray($config['thresholds']);
    }

    public function testInitializationWithCustomConfiguration(): void
    {
        $customConfig = [
            'uv_enabled' => false,
            'auto_switch' => false,
            'thresholds' => [
                'connections' => 2000,
                'timers' => 200
            ]
        ];
        
        $eventLoop = new HybridEventLoop($this->logger, $customConfig);
        
        $config = $eventLoop->getConfiguration();
        $this->assertFalse($config['uv_enabled']);
        $this->assertFalse($config['auto_switch']);
        $this->assertEquals(2000, $config['thresholds']['connections']);
        $this->assertEquals(200, $config['thresholds']['timers']);
    }

    public function testConnectionCountManagement(): void
    {
        $eventLoop = new HybridEventLoop($this->logger);
        
        $this->assertEquals(0, $eventLoop->getConnectionCount());
        
        $eventLoop->addConnectionCount(100);
        $this->assertEquals(100, $eventLoop->getConnectionCount());
        
        $eventLoop->addConnectionCount(50);
        $this->assertEquals(150, $eventLoop->getConnectionCount());
        
        $eventLoop->removeConnectionCount(30);
        $this->assertEquals(120, $eventLoop->getConnectionCount());
        
        $eventLoop->removeConnectionCount(200); // Should not go below 0
        $this->assertEquals(0, $eventLoop->getConnectionCount());
    }

    public function testHighPerformanceModeToggle(): void
    {
        $eventLoop = new HybridEventLoop($this->logger);
        
        $initialMetrics = $eventLoop->getMetrics();
        $this->assertFalse($initialMetrics['high_performance_mode']);
        
        $eventLoop->setHighPerformanceMode(true);
        
        $updatedMetrics = $eventLoop->getMetrics();
        $this->assertTrue($updatedMetrics['high_performance_mode']);
    }

    public function testMetricsCollection(): void
    {
        $eventLoop = new HybridEventLoop($this->logger);
        
        $metrics = $eventLoop->getMetrics();
        
        $expectedKeys = [
            'uv_usage',
            'revolt_usage',
            'switches',
            'uv_available',
            'uv_enabled',
            'connection_count',
            'timer_count',
            'high_performance_mode',
            'should_use_uv',
            'memory_usage'
        ];
        
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $metrics, "Missing key: {$key}");
        }
        
        $this->assertIsInt($metrics['uv_usage']);
        $this->assertIsInt($metrics['revolt_usage']);
        $this->assertIsInt($metrics['switches']);
        $this->assertIsBool($metrics['uv_available']);
        $this->assertIsBool($metrics['uv_enabled']);
        $this->assertIsInt($metrics['connection_count']);
        $this->assertIsInt($metrics['timer_count']);
        $this->assertIsBool($metrics['high_performance_mode']);
        $this->assertIsBool($metrics['should_use_uv']);
        $this->assertIsInt($metrics['memory_usage']);
    }

    public function testConfigurationUpdate(): void
    {
        $eventLoop = new HybridEventLoop($this->logger);
        
        $newConfig = [
            'thresholds' => [
                'connections' => 5000,
                'timers' => 500
            ],
            'monitoring' => [
                'enabled' => false
            ]
        ];
        
        $eventLoop->setConfiguration($newConfig);
        
        $config = $eventLoop->getConfiguration();
        $this->assertEquals(5000, $config['thresholds']['connections']);
        $this->assertEquals(500, $config['thresholds']['timers']);
        $this->assertFalse($config['monitoring']['enabled']);
    }

    public function testEnvironmentVariableConfiguration(): void
    {
        // Test environment variable loading with proper boolean conversion
        $oldUvEnabled = $_ENV['EVENT_LOOP_UV_ENABLED'] ?? null;
        $oldAutoSwitch = $_ENV['EVENT_LOOP_AUTO_SWITCH'] ?? null;
        
        // Use empty string or '0' for false values, as (bool) '' and (bool) '0' are false
        $_ENV['EVENT_LOOP_UV_ENABLED'] = '';
        $_ENV['EVENT_LOOP_AUTO_SWITCH'] = '0';
        
        $eventLoop = new HybridEventLoop($this->logger);
        $config = $eventLoop->getConfiguration();
        
        $this->assertFalse($config['uv_enabled']);
        $this->assertFalse($config['auto_switch']);
        
        // Restore environment
        if ($oldUvEnabled !== null) {
            $_ENV['EVENT_LOOP_UV_ENABLED'] = $oldUvEnabled;
        } else {
            unset($_ENV['EVENT_LOOP_UV_ENABLED']);
        }
        
        if ($oldAutoSwitch !== null) {
            $_ENV['EVENT_LOOP_AUTO_SWITCH'] = $oldAutoSwitch;
        } else {
            unset($_ENV['EVENT_LOOP_AUTO_SWITCH']);
        }
    }

    public function testThresholdConfiguration(): void
    {
        $oldConnections = $_ENV['EVENT_LOOP_UV_THRESHOLD_CONNECTIONS'] ?? null;
        $oldTimers = $_ENV['EVENT_LOOP_UV_THRESHOLD_TIMERS'] ?? null;
        
        $_ENV['EVENT_LOOP_UV_THRESHOLD_CONNECTIONS'] = '1500';
        $_ENV['EVENT_LOOP_UV_THRESHOLD_TIMERS'] = '150';
        
        $eventLoop = new HybridEventLoop($this->logger);
        $config = $eventLoop->getConfiguration();
        
        $this->assertEquals(1500, $config['thresholds']['connections']);
        $this->assertEquals(150, $config['thresholds']['timers']);
        
        // Restore environment
        if ($oldConnections !== null) {
            $_ENV['EVENT_LOOP_UV_THRESHOLD_CONNECTIONS'] = $oldConnections;
        } else {
            unset($_ENV['EVENT_LOOP_UV_THRESHOLD_CONNECTIONS']);
        }
        
        if ($oldTimers !== null) {
            $_ENV['EVENT_LOOP_UV_THRESHOLD_TIMERS'] = $oldTimers;
        } else {
            unset($_ENV['EVENT_LOOP_UV_THRESHOLD_TIMERS']);
        }
    }

    public function testUVAvailabilityDetection(): void
    {
        $eventLoop = new HybridEventLoop($this->logger);
        $metrics = $eventLoop->getMetrics();
        
        // Should detect actual php-uv availability
        $this->assertEquals(extension_loaded('uv'), $metrics['uv_available']);
    }

    public function testLoggerIntegration(): void
    {
        // The HybridEventLoop logs different messages based on UV availability
        // We should expect at least one info call during initialization
        $this->logger->expects($this->atLeastOnce())
                     ->method('info')
                     ->with(
                         $this->logicalOr(
                             $this->stringContains('HybridEventLoop initialized'),
                             $this->stringContains('php-uv not available or disabled'),
                             $this->stringContains('php-uv extension detected and enabled'),
                             $this->stringContains('System capabilities detected')
                         ),
                         $this->isType('array')
                     );
        
        new HybridEventLoop($this->logger);
    }

    public function testDriverAccess(): void
    {
        $eventLoop = new HybridEventLoop($this->logger);
        
        $driver = $eventLoop->getDriver();
        $this->assertInstanceOf(\Revolt\EventLoop\Driver::class, $driver);
    }

    public function testWatcherMethods(): void
    {
        $eventLoop = new HybridEventLoop($this->logger);
        
        // Test that watcher methods return string IDs
        $callback = function() {};
        
        $delayId = $eventLoop->delay(0.1, $callback);
        $this->assertIsString($delayId);
        
        $deferId = $eventLoop->defer($callback);
        $this->assertIsString($deferId);
        
        // Test cancellation doesn't throw
        $eventLoop->cancel($delayId);
        $eventLoop->cancel($deferId);
        $eventLoop->cancel('non-existent-id'); // Should not throw
        
        $this->addToAssertionCount(3); // Assert no exceptions were thrown
    }

    public function testStreamWatchers(): void
    {
        $eventLoop = new HybridEventLoop($this->logger);
        
        // Create a test stream
        $stream = fopen('php://memory', 'r+');
        $this->assertIsResource($stream);
        
        $callback = function() {};
        
        $readableId = $eventLoop->onReadable($stream, $callback);
        $this->assertIsString($readableId);
        
        $writableId = $eventLoop->onWritable($stream, $callback);
        $this->assertIsString($writableId);
        
        // Test reference/unreference
        $eventLoop->reference($readableId);
        $eventLoop->unreference($readableId);
        
        // Cleanup
        $eventLoop->cancel($readableId);
        $eventLoop->cancel($writableId);
        fclose($stream);
        
        $this->addToAssertionCount(2);
    }

    public function testSignalWatcher(): void
    {
        if (!function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX functions not available');
        }
        
        $eventLoop = new HybridEventLoop($this->logger);
        
        $callback = function() {};
        $signalId = $eventLoop->onSignal(SIGUSR1, $callback);
        $this->assertIsString($signalId);
        
        $eventLoop->cancel($signalId);
        $this->addToAssertionCount(1);
    }

    public function testMetricsUpdateOnOperations(): void
    {
        $eventLoop = new HybridEventLoop($this->logger);
        
        $initialMetrics = $eventLoop->getMetrics();
        $initialRevoltUsage = $initialMetrics['revolt_usage'];
        
        // Perform some operations
        $callback = function() {};
        $delayId = $eventLoop->delay(0.1, $callback);
        $deferId = $eventLoop->defer($callback);
        
        $updatedMetrics = $eventLoop->getMetrics();
        $updatedRevoltUsage = $updatedMetrics['revolt_usage'];
        
        // Should have increased revolt usage
        $this->assertGreaterThan($initialRevoltUsage, $updatedRevoltUsage);
        
        // Cleanup
        $eventLoop->cancel($delayId);
        $eventLoop->cancel($deferId);
    }
}