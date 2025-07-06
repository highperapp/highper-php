<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Tests\Performance;

use PHPUnit\Framework\TestCase;
use HighPerApp\HighPer\Foundation\Application;
use HighPerApp\HighPer\Foundation\ProcessManager;
use HighPerApp\HighPer\Foundation\HybridEventLoop;
use HighPerApp\HighPer\Foundation\AdaptiveSerializer;

/**
 * Performance Benchmark Tests
 * 
 * Validates performance claims and measures actual throughput,
 * latency, and resource usage of the framework components.
 */
class PerformanceBenchmarkTest extends TestCase
{
    private Application $app;
    private const BENCHMARK_ITERATIONS = 1000;
    private const MEMORY_LEAK_THRESHOLD = 1024 * 1024; // 1MB

    protected function setUp(): void
    {
        $this->app = new Application([
            'testing' => true,
            'environment' => 'benchmark'
        ]);
        
        $this->app->bootstrap();
        
        // Ensure clean memory state
        gc_collect_cycles();
    }

    public function testSerializationPerformance(): void
    {
        $serializer = new AdaptiveSerializer(null, $this->app->getLogger());
        
        $testData = [
            'small' => ['id' => 1, 'name' => 'test'],
            'medium' => array_fill(0, 100, ['id' => rand(1, 1000), 'name' => 'test_' . rand(1, 1000)]),
            'large' => array_fill(0, 1000, ['id' => rand(1, 1000), 'name' => str_repeat('x', 100)])
        ];

        foreach ($testData as $size => $data) {
            $this->measureSerializationPerformance($serializer, $data, $size);
        }
    }

    private function measureSerializationPerformance(AdaptiveSerializer $serializer, array $data, string $size): void
    {
        $startMemory = memory_get_usage(true);
        $startTime = microtime(true);
        
        // Serialization benchmark
        $serialized = [];
        for ($i = 0; $i < self::BENCHMARK_ITERATIONS; $i++) {
            $serialized[] = $serializer->serialize($data);
        }
        
        $serializeTime = microtime(true) - $startTime;
        
        // Deserialization benchmark
        $startTime = microtime(true);
        for ($i = 0; $i < self::BENCHMARK_ITERATIONS; $i++) {
            $deserialized = $serializer->deserialize($serialized[$i]);
            $this->assertIsArray($deserialized);
        }
        $deserializeTime = microtime(true) - $startTime;
        
        $endMemory = memory_get_usage(true);
        $memoryUsed = $endMemory - $startMemory;
        
        // Performance assertions
        $serializeOpsPerSecond = self::BENCHMARK_ITERATIONS / $serializeTime;
        $deserializeOpsPerSecond = self::BENCHMARK_ITERATIONS / $deserializeTime;
        
        echo "\n{$size} data serialization performance:\n";
        echo "  Serialize: " . number_format($serializeOpsPerSecond, 0) . " ops/sec\n";
        echo "  Deserialize: " . number_format($deserializeOpsPerSecond, 0) . " ops/sec\n";
        echo "  Memory used: " . number_format($memoryUsed / 1024, 2) . " KB\n";
        
        // Basic performance expectations
        $this->assertGreaterThan(100, $serializeOpsPerSecond, "Serialization too slow for {$size} data");
        $this->assertGreaterThan(100, $deserializeOpsPerSecond, "Deserialization too slow for {$size} data");
        
        // Memory leak check
        $this->assertLessThan(self::MEMORY_LEAK_THRESHOLD, $memoryUsed, "Potential memory leak detected for {$size} data");
    }

    public function testEventLoopPerformance(): void
    {
        $eventLoop = new HybridEventLoop($this->app->getLogger(), [
            'auto_switch' => false // Disable for consistent benchmarking
        ]);
        
        $this->measureTimerPerformance($eventLoop);
        $this->measureDeferPerformance($eventLoop);
    }

    private function measureTimerPerformance(HybridEventLoop $eventLoop): void
    {
        $startMemory = memory_get_usage(true);
        $startTime = microtime(true);
        
        $timers = [];
        for ($i = 0; $i < self::BENCHMARK_ITERATIONS; $i++) {
            $timers[] = $eventLoop->delay(0.001, function() {});
        }
        
        $creationTime = microtime(true) - $startTime;
        
        // Cancel all timers
        $startTime = microtime(true);
        foreach ($timers as $timerId) {
            $eventLoop->cancel($timerId);
        }
        $cancellationTime = microtime(true) - $startTime;
        
        $endMemory = memory_get_usage(true);
        $memoryUsed = $endMemory - $startMemory;
        
        $creationOpsPerSecond = self::BENCHMARK_ITERATIONS / $creationTime;
        $cancellationOpsPerSecond = self::BENCHMARK_ITERATIONS / $cancellationTime;
        
        echo "\nTimer performance:\n";
        echo "  Creation: " . number_format($creationOpsPerSecond, 0) . " ops/sec\n";
        echo "  Cancellation: " . number_format($cancellationOpsPerSecond, 0) . " ops/sec\n";
        echo "  Memory used: " . number_format($memoryUsed / 1024, 2) . " KB\n";
        
        $this->assertGreaterThan(1000, $creationOpsPerSecond, "Timer creation too slow");
        $this->assertGreaterThan(1000, $cancellationOpsPerSecond, "Timer cancellation too slow");
        $this->assertLessThan(self::MEMORY_LEAK_THRESHOLD, $memoryUsed, "Timer memory leak detected");
    }

    private function measureDeferPerformance(HybridEventLoop $eventLoop): void
    {
        $startMemory = memory_get_usage(true);
        $startTime = microtime(true);
        
        $defers = [];
        for ($i = 0; $i < self::BENCHMARK_ITERATIONS; $i++) {
            $defers[] = $eventLoop->defer(function() {});
        }
        
        $creationTime = microtime(true) - $startTime;
        
        // Cancel all defers
        $startTime = microtime(true);
        foreach ($defers as $deferId) {
            $eventLoop->cancel($deferId);
        }
        $cancellationTime = microtime(true) - $startTime;
        
        $endMemory = memory_get_usage(true);
        $memoryUsed = $endMemory - $startMemory;
        
        $creationOpsPerSecond = self::BENCHMARK_ITERATIONS / $creationTime;
        $cancellationOpsPerSecond = self::BENCHMARK_ITERATIONS / $cancellationTime;
        
        echo "\nDefer performance:\n";
        echo "  Creation: " . number_format($creationOpsPerSecond, 0) . " ops/sec\n";
        echo "  Cancellation: " . number_format($cancellationOpsPerSecond, 0) . " ops/sec\n";
        echo "  Memory used: " . number_format($memoryUsed / 1024, 2) . " KB\n";
        
        $this->assertGreaterThan(1000, $creationOpsPerSecond, "Defer creation too slow");
        $this->assertGreaterThan(1000, $cancellationOpsPerSecond, "Defer cancellation too slow");
        $this->assertLessThan(self::MEMORY_LEAK_THRESHOLD, $memoryUsed, "Defer memory leak detected");
    }

    public function testApplicationBootstrapPerformance(): void
    {
        $iterations = 10; // Smaller number due to bootstrap overhead
        
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            $app = new Application(['testing' => true]);
            $app->bootstrap();
            
            // Verify bootstrap completed
            $this->assertInstanceOf(Application::class, $app);
            $this->assertNotNull($app->getContainer());
            $this->assertNotNull($app->getRouter());
        }
        
        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);
        
        $bootstrapTime = ($endTime - $startTime) / $iterations;
        $memoryPerBootstrap = ($endMemory - $startMemory) / $iterations;
        
        echo "\nApplication bootstrap performance:\n";
        echo "  Average time: " . number_format($bootstrapTime * 1000, 2) . " ms\n";
        echo "  Average memory: " . number_format($memoryPerBootstrap / 1024, 2) . " KB\n";
        
        // Performance expectations
        $this->assertLessThan(0.1, $bootstrapTime, "Bootstrap too slow"); // 100ms max
        $this->assertLessThan(1024 * 1024, $memoryPerBootstrap, "Bootstrap uses too much memory"); // 1MB max
    }

    public function testProcessManagerPerformance(): void
    {
        $processManager = new ProcessManager($this->app);
        
        $startTime = microtime(true);
        $processManager->start();
        $startupTime = microtime(true) - $startTime;
        
        // Test scaling performance
        $initialWorkers = $processManager->getWorkersCount();
        
        $startTime = microtime(true);
        $processManager->scaleWorkers($initialWorkers + 2);
        $scaleUpTime = microtime(true) - $startTime;
        
        $startTime = microtime(true);
        $processManager->scaleWorkers($initialWorkers);
        $scaleDownTime = microtime(true) - $startTime;
        
        // Test shutdown performance
        $startTime = microtime(true);
        $processManager->stop();
        $shutdownTime = microtime(true) - $startTime;
        
        echo "\nProcessManager performance:\n";
        echo "  Startup time: " . number_format($startupTime * 1000, 2) . " ms\n";
        echo "  Scale up time: " . number_format($scaleUpTime * 1000, 2) . " ms\n";
        echo "  Scale down time: " . number_format($scaleDownTime * 1000, 2) . " ms\n";
        echo "  Shutdown time: " . number_format($shutdownTime * 1000, 2) . " ms\n";
        
        // Performance expectations
        $this->assertLessThan(5.0, $startupTime, "ProcessManager startup too slow");
        $this->assertLessThan(2.0, $scaleUpTime, "Worker scale up too slow");
        $this->assertLessThan(2.0, $scaleDownTime, "Worker scale down too slow");
        $this->assertLessThan(10.0, $shutdownTime, "ProcessManager shutdown too slow");
    }

    public function testMemoryStability(): void
    {
        $serializer = new AdaptiveSerializer(null, $this->app->getLogger());
        $eventLoop = new HybridEventLoop($this->app->getLogger());
        
        $initialMemory = memory_get_usage(true);
        $memorySnapshots = [];
        
        // Perform sustained operations
        for ($i = 0; $i < 1000; $i++) {
            // Serialization operations
            $data = ['iteration' => $i, 'data' => str_repeat('x', 100)];
            $serialized = $serializer->serialize($data);
            $deserialized = $serializer->deserialize($serialized);
            
            // Event loop operations
            $timerId = $eventLoop->delay(0.001, function() {});
            $eventLoop->cancel($timerId);
            
            // Take memory snapshot every 100 iterations
            if ($i % 100 === 0) {
                gc_collect_cycles(); // Force garbage collection
                $memorySnapshots[] = memory_get_usage(true);
            }
        }
        
        $finalMemory = memory_get_usage(true);
        $memoryGrowth = $finalMemory - $initialMemory;
        
        echo "\nMemory stability test:\n";
        echo "  Initial memory: " . number_format($initialMemory / 1024, 2) . " KB\n";
        echo "  Final memory: " . number_format($finalMemory / 1024, 2) . " KB\n";
        echo "  Memory growth: " . number_format($memoryGrowth / 1024, 2) . " KB\n";
        
        // Check for memory leaks
        $this->assertLessThan(self::MEMORY_LEAK_THRESHOLD * 2, $memoryGrowth, "Significant memory leak detected");
        
        // Check memory growth trend
        $firstSnapshot = $memorySnapshots[0] ?? $initialMemory;
        $lastSnapshot = end($memorySnapshots);
        $trendGrowth = $lastSnapshot - $firstSnapshot;
        
        echo "  Trend growth: " . number_format($trendGrowth / 1024, 2) . " KB\n";
        $this->assertLessThan(self::MEMORY_LEAK_THRESHOLD, $trendGrowth, "Memory leak trend detected");
    }

    public function testConcurrentOperationsPerformance(): void
    {
        $serializer = new AdaptiveSerializer(null, $this->app->getLogger());
        
        $operationsCount = 100;
        $dataSize = 1000;
        
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        
        // Simulate concurrent operations
        $operations = [];
        for ($i = 0; $i < $operationsCount; $i++) {
            $data = array_fill(0, $dataSize, ['id' => $i, 'data' => str_repeat('x', 50)]);
            
            $serialized = $serializer->serialize($data);
            $deserialized = $serializer->deserialize($serialized);
            
            $operations[] = $deserialized;
        }
        
        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);
        
        $totalTime = $endTime - $startTime;
        $memoryUsed = $endMemory - $startMemory;
        $operationsPerSecond = $operationsCount / $totalTime;
        
        echo "\nConcurrent operations performance:\n";
        echo "  Operations: {$operationsCount}\n";
        echo "  Total time: " . number_format($totalTime * 1000, 2) . " ms\n";
        echo "  Ops/sec: " . number_format($operationsPerSecond, 0) . "\n";
        echo "  Memory used: " . number_format($memoryUsed / 1024, 2) . " KB\n";
        echo "  Memory per op: " . number_format($memoryUsed / $operationsCount / 1024, 2) . " KB\n";
        
        // Performance expectations
        $this->assertGreaterThan(10, $operationsPerSecond, "Concurrent operations too slow");
        $this->assertLessThan(self::MEMORY_LEAK_THRESHOLD * 10, $memoryUsed, "Excessive memory usage");
        
        $this->assertCount($operationsCount, $operations);
    }

    public function testComponentInteractionPerformance(): void
    {
        $eventLoop = new HybridEventLoop($this->app->getLogger());
        $serializer = new AdaptiveSerializer(null, $this->app->getLogger());
        
        $iterations = 100;
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            // Simulate complex interaction
            $eventLoop->addConnectionCount(1);
            
            $data = ['connection_id' => $i, 'timestamp' => microtime(true)];
            $serialized = $serializer->serialize($data);
            
            $timerId = $eventLoop->delay(0.001, function() use ($serializer, $serialized) {
                $deserialized = $serializer->deserialize($serialized);
                return $deserialized;
            });
            
            $eventLoop->cancel($timerId);
            $eventLoop->removeConnectionCount(1);
        }
        
        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);
        
        $totalTime = $endTime - $startTime;
        $memoryUsed = $endMemory - $startMemory;
        $interactionsPerSecond = $iterations / $totalTime;
        
        echo "\nComponent interaction performance:\n";
        echo "  Interactions: {$iterations}\n";
        echo "  Total time: " . number_format($totalTime * 1000, 2) . " ms\n";
        echo "  Interactions/sec: " . number_format($interactionsPerSecond, 0) . "\n";
        echo "  Memory used: " . number_format($memoryUsed / 1024, 2) . " KB\n";
        
        $this->assertGreaterThan(50, $interactionsPerSecond, "Component interactions too slow");
        $this->assertLessThan(self::MEMORY_LEAK_THRESHOLD, $memoryUsed, "Component interaction memory leak");
    }
}