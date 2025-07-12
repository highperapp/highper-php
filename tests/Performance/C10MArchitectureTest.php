<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Tests\Performance;

use PHPUnit\Framework\TestCase;
use HighPerApp\HighPer\Foundation\Application;
use HighPerApp\HighPer\Foundation\ProcessManager;
use HighPerApp\HighPer\Foundation\HybridEventLoop;
use HighPerApp\HighPer\Foundation\ArchitectureValidator;

/**
 * C10M Architecture Performance Tests
 * 
 * Tests performance characteristics of the hybrid multi-process + async architecture
 * with C10M optimizations enabled.
 */
class C10MArchitectureTest extends TestCase
{
    private Application $app;
    private ProcessManager $processManager;
    private HybridEventLoop $eventLoop;
    private ArchitectureValidator $validator;

    protected function setUp(): void
    {
        $this->app = new Application([
            'testing' => true,
            'environment' => 'performance-test'
        ]);
        
        $this->app->bootstrap();
        
        $this->processManager = new ProcessManager($this->app);
        $this->eventLoop = new HybridEventLoop($this->app->getLogger());
        $this->validator = new ArchitectureValidator($this->app->getLogger());
    }

    protected function tearDown(): void
    {
        if ($this->processManager->isRunning()) {
            $this->processManager->stop();
        }
    }

    public function testC10MConfigurationOptimizations(): void
    {
        $config = [
            'server' => ['c10m_enabled' => true],
            'workers' => ['memory_limit' => '256M']
        ];
        
        $optimized = $this->validator->validateConfiguration($config);
        
        // C10M optimizations should be applied
        $this->assertEquals(10000, $optimized['workers']['max_connections_per_worker']);
        $this->assertEquals(50000, $optimized['workers']['restart_threshold']);
        $this->assertEquals(5000, $optimized['event_loop']['thresholds']['connections']);
        $this->assertEquals('512M', $optimized['workers']['memory_limit']);
    }

    public function testHighConnectionCountHandling(): void
    {
        // Simulate high connection count
        $this->eventLoop->addConnectionCount(5000);
        
        $metrics = $this->eventLoop->getMetrics();
        $this->assertEquals(5000, $metrics['connection_count']);
        
        // Should trigger optimization thresholds
        $config = $this->eventLoop->getConfiguration();
        if ($config['auto_switch'] && $metrics['uv_available']) {
            $this->assertTrue($metrics['should_use_uv']);
        }
    }

    public function testWorkerScalingPerformance(): void
    {
        $startTime = microtime(true);
        
        // Test scaling multiple workers
        $this->processManager->start();
        $initialCount = $this->processManager->getWorkersCount();
        
        // Scale up significantly
        $targetWorkers = min($initialCount + 4, 16); // Don't exceed reasonable limits
        $this->processManager->scaleWorkers($targetWorkers);
        
        $scaleUpTime = microtime(true);
        
        // Scale back down
        $this->processManager->scaleWorkers($initialCount);
        
        $scaleDownTime = microtime(true);
        
        $this->processManager->stop();
        $stopTime = microtime(true);
        
        // Performance assertions
        $scaleUpDuration = $scaleUpTime - $startTime;
        $scaleDownDuration = $scaleDownTime - $scaleUpTime;
        $totalDuration = $stopTime - $startTime;
        
        $this->assertLessThan(10.0, $scaleUpDuration, 'Worker scale-up took too long');
        $this->assertLessThan(5.0, $scaleDownDuration, 'Worker scale-down took too long');
        $this->assertLessThan(20.0, $totalDuration, 'Total operation took too long');
    }

    public function testEventLoopSwitchingPerformance(): void
    {
        $startTime = microtime(true);
        
        // Test rapid connection count changes
        for ($i = 0; $i < 100; $i++) {
            $this->eventLoop->addConnectionCount(10);
            $this->eventLoop->removeConnectionCount(5);
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        $this->assertLessThan(0.1, $duration, 'Connection count updates took too long');
        $this->assertEquals(500, $this->eventLoop->getConnectionCount());
    }

    public function testMemoryEfficiencyUnderLoad(): void
    {
        $initialMemory = memory_get_usage(true);
        
        // Simulate memory-intensive operations
        $this->eventLoop->addConnectionCount(1000);
        $this->eventLoop->setHighPerformanceMode(true);
        
        // Create multiple event loop operations
        $watchers = [];
        for ($i = 0; $i < 100; $i++) {
            $watchers[] = $this->eventLoop->delay(0.1, function() {});
        }
        
        $peakMemory = memory_get_usage(true);
        
        // Clean up watchers
        foreach ($watchers as $watcher) {
            $this->eventLoop->cancel($watcher);
        }
        
        $finalMemory = memory_get_usage(true);
        
        // Memory should not grow excessively
        $memoryGrowth = $peakMemory - $initialMemory;
        $this->assertLessThan(50 * 1024 * 1024, $memoryGrowth, 'Memory growth exceeded 50MB');
        
        // Memory should be released after cleanup
        $memoryReleased = $peakMemory - $finalMemory;
        $this->assertGreaterThan(0, $memoryReleased, 'Memory was not released after cleanup');
    }

    public function testConcurrentOperationsPerformance(): void
    {
        $startTime = microtime(true);
        
        // Test concurrent timer operations
        $timers = [];
        for ($i = 0; $i < 1000; $i++) {
            $timers[] = $this->eventLoop->delay(0.001, function() {});
        }
        
        $timerCreationTime = microtime(true);
        
        // Test concurrent stream operations
        $streams = [];
        for ($i = 0; $i < 100; $i++) {
            $stream = fopen('php://memory', 'r+');
            $streams[] = [
                'stream' => $stream,
                'readable' => $this->eventLoop->onReadable($stream, function() {}),
                'writable' => $this->eventLoop->onWritable($stream, function() {})
            ];
        }
        
        $streamCreationTime = microtime(true);
        
        // Cleanup
        foreach ($timers as $timer) {
            $this->eventLoop->cancel($timer);
        }
        
        foreach ($streams as $streamData) {
            $this->eventLoop->cancel($streamData['readable']);
            $this->eventLoop->cancel($streamData['writable']);
            fclose($streamData['stream']);
        }
        
        $cleanupTime = microtime(true);
        
        // Performance assertions
        $timerDuration = $timerCreationTime - $startTime;
        $streamDuration = $streamCreationTime - $timerCreationTime;
        $cleanupDuration = $cleanupTime - $streamCreationTime;
        
        $this->assertLessThan(1.0, $timerDuration, 'Timer creation took too long');
        $this->assertLessThan(0.5, $streamDuration, 'Stream watcher creation took too long');
        $this->assertLessThan(0.5, $cleanupDuration, 'Cleanup took too long');
    }

    public function testProcessManagerStartupPerformance(): void
    {
        $startTime = microtime(true);
        
        $this->processManager->start();
        
        $startupTime = microtime(true) - $startTime;
        
        $this->assertTrue($this->processManager->isRunning());
        $this->assertLessThan(5.0, $startupTime, 'Process manager startup took too long');
        
        $shutdownStart = microtime(true);
        $this->processManager->stop();
        $shutdownTime = microtime(true) - $shutdownStart;
        
        $this->assertFalse($this->processManager->isRunning());
        $this->assertLessThan(10.0, $shutdownTime, 'Process manager shutdown took too long');
    }

    public function testConfigurationValidationPerformance(): void
    {
        $config = [
            'workers' => [
                'count' => 16,
                'memory_limit' => '1G'
            ],
            'server' => [
                'c10m_enabled' => true,
                'rust_enabled' => true,
                'mode' => 'dedicated_ports',
                'ports' => ['http' => 8080, 'ws' => 8081]
            ],
            'event_loop' => [
                'uv_enabled' => true,
                'thresholds' => [
                    'connections' => 5000,
                    'timers' => 500
                ]
            ],
            'zero_downtime' => [
                'enabled' => true,
                'deployment_strategy' => 'blue_green'
            ]
        ];
        
        $startTime = microtime(true);
        
        // Validate configuration multiple times
        for ($i = 0; $i < 100; $i++) {
            $validated = $this->validator->validateConfiguration($config);
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        $this->assertLessThan(0.5, $duration, 'Configuration validation took too long');
        $this->assertArrayHasKey('workers', $validated);
        $this->assertArrayHasKey('server', $validated);
        $this->assertArrayHasKey('event_loop', $validated);
        $this->assertArrayHasKey('zero_downtime', $validated);
    }

    public function testSystemCapabilityDetectionPerformance(): void
    {
        $startTime = microtime(true);
        
        // Test capability detection multiple times
        for ($i = 0; $i < 50; $i++) {
            $capabilities = $this->validator->getSystemCapabilities();
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        $this->assertLessThan(0.1, $duration, 'System capability detection took too long');
        $this->assertArrayHasKey('cpu_cores', $capabilities);
        $this->assertArrayHasKey('total_memory', $capabilities);
    }

    public function testEventLoopMetricsCollectionPerformance(): void
    {
        $startTime = microtime(true);
        
        // Perform operations that update metrics
        for ($i = 0; $i < 500; $i++) {
            $this->eventLoop->addConnectionCount(1);
            $this->eventLoop->getMetrics();
            $this->eventLoop->removeConnectionCount(1);
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        $this->assertLessThan(0.5, $duration, 'Metrics collection took too long');
    }

    public function testHighPerformanceModeTogglePerformance(): void
    {
        $startTime = microtime(true);
        
        // Toggle high performance mode rapidly
        for ($i = 0; $i < 1000; $i++) {
            $this->eventLoop->setHighPerformanceMode($i % 2 === 0);
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        $this->assertLessThan(0.1, $duration, 'High performance mode toggle took too long');
    }

    public function testOptimalConfigurationGeneration(): void
    {
        $startTime = microtime(true);
        
        // Generate optimal configuration multiple times
        for ($i = 0; $i < 100; $i++) {
            $optimal = $this->validator->generateOptimalConfig();
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        $this->assertLessThan(0.1, $duration, 'Optimal configuration generation took too long');
        $this->assertArrayHasKey('workers', $optimal);
        $this->assertArrayHasKey('event_loop', $optimal);
        $this->assertArrayHasKey('server', $optimal);
        $this->assertArrayHasKey('zero_downtime', $optimal);
    }

    public function testConnectionCountScalingPerformance(): void
    {
        $startTime = microtime(true);
        
        // Test large connection count changes
        $this->eventLoop->addConnectionCount(10000);
        $this->assertEquals(10000, $this->eventLoop->getConnectionCount());
        
        $this->eventLoop->addConnectionCount(50000);
        $this->assertEquals(60000, $this->eventLoop->getConnectionCount());
        
        $this->eventLoop->removeConnectionCount(30000);
        $this->assertEquals(30000, $this->eventLoop->getConnectionCount());
        
        $this->eventLoop->removeConnectionCount(50000); // Should not go below 0
        $this->assertEquals(0, $this->eventLoop->getConnectionCount());
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        $this->assertLessThan(0.001, $duration, 'Connection count scaling took too long');
    }

    public function testProcessManagerStatsCollectionPerformance(): void
    {
        $this->processManager->start();
        
        $startTime = microtime(true);
        
        // Collect stats multiple times
        for ($i = 0; $i < 100; $i++) {
            $stats = $this->processManager->getStats();
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        $this->processManager->stop();
        
        $this->assertLessThan(0.1, $duration, 'Stats collection took too long');
        $this->assertArrayHasKey('running', $stats);
        $this->assertArrayHasKey('worker_count', $stats);
        $this->assertArrayHasKey('memory_usage', $stats);
    }
}