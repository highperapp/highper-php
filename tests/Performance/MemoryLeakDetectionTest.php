<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Tests\Performance;

/**
 * Memory Leak Detection Test
 * 
 * Long-running stability test to detect memory leaks
 * in the v3 framework components under sustained load.
 */
class MemoryLeakDetectionTest
{
    private array $memorySnapshots = [];
    private array $testResults = [];
    private float $startTime;
    private int $iterations = 0;

    public function __construct()
    {
        $this->startTime = microtime(true);
    }

    public function runMemoryLeakDetection(): array
    {
        echo "🧪 HighPer Framework v3 - Memory Leak Detection Test\n";
        echo "===================================================\n\n";

        // Test core components for memory leaks
        $this->testCoreComponentMemoryLeaks();
        
        // Test reliability stack memory usage
        $this->testReliabilityStackMemoryUsage();
        
        // Test cache components for memory leaks
        $this->testCacheComponentMemoryLeaks();
        
        // Test sustained load memory behavior
        $this->testSustainedLoadMemoryBehavior();
        
        return $this->generateMemoryLeakReport();
    }

    private function testCoreComponentMemoryLeaks(): void
    {
        echo "🔍 Testing Core Components for Memory Leaks...\n";
        echo str_repeat("─", 60) . "\n";

        // Load framework
        $autoloader = __DIR__ . '/../../core/framework/vendor/autoload.php';
        if (file_exists($autoloader)) {
            require_once $autoloader;
        }

        $this->takeMemorySnapshot('baseline');

        // Test AsyncManager memory usage
        if (class_exists('HighPerApp\\HighPer\\Foundation\\AsyncManager')) {
            echo "  🧪 Testing AsyncManager memory usage...\n";
            
            $initialMemory = memory_get_usage(true);
            $managers = [];
            
            // Create and destroy AsyncManager instances
            for ($i = 0; $i < 1000; $i++) {
                $manager = new \HighPerApp\HighPer\Foundation\AsyncManager();
                $managers[] = $manager;
                
                if ($i % 100 === 0) {
                    $currentMemory = memory_get_usage(true);
                    $growth = $currentMemory - $initialMemory;
                    echo "    📊 After {$i} instances: " . $this->formatBytes($growth) . " growth\n";
                }
            }
            
            unset($managers);
            gc_collect_cycles();
            
            $finalMemory = memory_get_usage(true);
            $netGrowth = $finalMemory - $initialMemory;
            
            $this->recordMemoryTest('AsyncManager', $initialMemory, $finalMemory, $netGrowth);
            echo "    ✅ Net memory growth: " . $this->formatBytes($netGrowth) . "\n";
        }

        // Test AdaptiveSerializer memory usage
        if (class_exists('HighPerApp\\HighPer\\Foundation\\AdaptiveSerializer')) {
            echo "  🧪 Testing AdaptiveSerializer memory usage...\n";
            
            $initialMemory = memory_get_usage(true);
            $serializer = new \HighPerApp\HighPer\Foundation\AdaptiveSerializer();
            
            // Test serialization/deserialization cycles
            $testData = [
                'large_array' => array_fill(0, 1000, 'test_data_' . str_repeat('x', 100)),
                'nested_data' => ['level1' => ['level2' => ['level3' => array_fill(0, 100, 'nested')]]],
                'timestamp' => microtime(true)
            ];
            
            for ($i = 0; $i < 10000; $i++) {
                $serialized = $serializer->serialize($testData);
                $deserialized = $serializer->deserialize($serialized);
                
                if ($i % 1000 === 0) {
                    $currentMemory = memory_get_usage(true);
                    $growth = $currentMemory - $initialMemory;
                    echo "    📊 After {$i} cycles: " . $this->formatBytes($growth) . " growth\n";
                }
            }
            
            gc_collect_cycles();
            $finalMemory = memory_get_usage(true);
            $netGrowth = $finalMemory - $initialMemory;
            
            $this->recordMemoryTest('AdaptiveSerializer', $initialMemory, $finalMemory, $netGrowth);
            echo "    ✅ Net memory growth: " . $this->formatBytes($netGrowth) . "\n";
        }

        $this->takeMemorySnapshot('after_core_tests');
        echo "\n";
    }

    private function testReliabilityStackMemoryUsage(): void
    {
        echo "🔍 Testing Reliability Stack Memory Usage...\n";
        echo str_repeat("─", 60) . "\n";

        $initialMemory = memory_get_usage(true);

        try {
            // Test CircuitBreaker memory usage under load
            if (class_exists('HighPerApp\\HighPer\\Resilience\\CircuitBreaker')) {
                echo "  🧪 Testing CircuitBreaker memory usage...\n";
                
                $circuitBreaker = new \HighPerApp\HighPer\Resilience\CircuitBreaker();
                
                // Simulate many circuit breaker operations
                for ($i = 0; $i < 50000; $i++) {
                    try {
                        $circuitBreaker->execute(function() use ($i) {
                            if ($i % 10 === 0) {
                                throw new \Exception('Simulated failure');
                            }
                            return 'success';
                        });
                    } catch (\Exception $e) {
                        // Expected failures
                    }
                    
                    if ($i % 5000 === 0) {
                        $currentMemory = memory_get_usage(true);
                        $growth = $currentMemory - $initialMemory;
                        echo "    📊 After {$i} operations: " . $this->formatBytes($growth) . " growth\n";
                    }
                }
                
                $stats = $circuitBreaker->getStats();
                echo "    📈 Circuit breaker stats: " . json_encode($stats) . "\n";
            }

            // Test BulkheadIsolator memory usage
            if (class_exists('HighPerApp\\HighPer\\Resilience\\BulkheadIsolator')) {
                echo "  🧪 Testing BulkheadIsolator memory usage...\n";
                
                $bulkhead = new \HighPerApp\HighPer\Resilience\BulkheadIsolator();
                
                // Create and execute operations in different compartments
                for ($i = 0; $i < 10000; $i++) {
                    $compartment = 'compartment_' . ($i % 10);
                    
                    try {
                        $bulkhead->execute($compartment, function() {
                            return 'bulkhead_test_' . uniqid();
                        });
                    } catch (\Exception $e) {
                        // Handle compartment errors
                    }
                    
                    if ($i % 1000 === 0) {
                        $currentMemory = memory_get_usage(true);
                        $growth = $currentMemory - $initialMemory;
                        echo "    📊 After {$i} compartment operations: " . $this->formatBytes($growth) . " growth\n";
                    }
                }
            }

        } catch (\Exception $e) {
            echo "  ❌ Error testing reliability stack: " . $e->getMessage() . "\n";
        }

        gc_collect_cycles();
        $finalMemory = memory_get_usage(true);
        $netGrowth = $finalMemory - $initialMemory;
        
        $this->recordMemoryTest('ReliabilityStack', $initialMemory, $finalMemory, $netGrowth);
        echo "  ✅ Reliability stack net memory growth: " . $this->formatBytes($netGrowth) . "\n\n";
    }

    private function testCacheComponentMemoryLeaks(): void
    {
        echo "🔍 Testing Cache Components for Memory Leaks...\n";
        echo str_repeat("─", 60) . "\n";

        $initialMemory = memory_get_usage(true);

        // Test RingBufferCache memory usage
        if (class_exists('HighPerApp\\HighPer\\Router\\RingBufferCache')) {
            echo "  🧪 Testing RingBufferCache memory usage...\n";
            
            $cache = new \HighPerApp\HighPer\Router\RingBufferCache(1024);
            
            // Fill cache with data and test eviction
            for ($i = 0; $i < 100000; $i++) {
                $key = 'cache_key_' . $i;
                $value = 'cache_value_' . str_repeat('x', 100) . '_' . $i;
                
                $cache->set($key, $value);
                
                // Occasionally get values to test retrieval
                if ($i % 100 === 0) {
                    $retrievedValue = $cache->get($key);
                }
                
                if ($i % 10000 === 0) {
                    $currentMemory = memory_get_usage(true);
                    $growth = $currentMemory - $initialMemory;
                    $stats = $cache->getStats();
                    echo "    📊 After {$i} cache operations: " . $this->formatBytes($growth) . " growth, ";
                    echo "hits: {$stats['hits']}, misses: {$stats['misses']}\n";
                }
            }
            
            $finalStats = $cache->getStats();
            echo "    📈 Final cache stats: " . json_encode($finalStats) . "\n";
        }

        gc_collect_cycles();
        $finalMemory = memory_get_usage(true);
        $netGrowth = $finalMemory - $initialMemory;
        
        $this->recordMemoryTest('CacheComponents', $initialMemory, $finalMemory, $netGrowth);
        echo "  ✅ Cache components net memory growth: " . $this->formatBytes($netGrowth) . "\n\n";
    }

    private function testSustainedLoadMemoryBehavior(): void
    {
        echo "🔍 Testing Sustained Load Memory Behavior...\n";
        echo str_repeat("─", 60) . "\n";

        $testDuration = 60; // 60 seconds
        $startTime = microtime(true);
        $initialMemory = memory_get_usage(true);
        $peakMemory = $initialMemory;
        $samples = [];

        echo "  ⏱️ Running sustained load test for {$testDuration} seconds...\n";

        while ((microtime(true) - $startTime) < $testDuration) {
            $this->iterations++;
            
            // Simulate various framework operations
            $this->simulateFrameworkOperations();
            
            $currentMemory = memory_get_usage(true);
            $peakMemory = max($peakMemory, $currentMemory);
            
            // Take memory samples every 5 seconds
            if ($this->iterations % 1000 === 0) {
                $elapsed = microtime(true) - $startTime;
                $growth = $currentMemory - $initialMemory;
                $samples[] = [
                    'time' => $elapsed,
                    'memory' => $currentMemory,
                    'growth' => $growth,
                    'iterations' => $this->iterations
                ];
                
                echo sprintf("    📊 %.1fs: %s memory, %s growth, %d iterations\n",
                    $elapsed, $this->formatBytes($currentMemory), 
                    $this->formatBytes($growth), $this->iterations);
                
                // Force garbage collection periodically
                if ($this->iterations % 5000 === 0) {
                    gc_collect_cycles();
                }
            }
        }

        $finalMemory = memory_get_usage(true);
        $totalGrowth = $finalMemory - $initialMemory;
        $peakGrowth = $peakMemory - $initialMemory;

        $this->recordMemoryTest('SustainedLoad', $initialMemory, $finalMemory, $totalGrowth, [
            'peak_memory' => $peakMemory,
            'peak_growth' => $peakGrowth,
            'test_duration' => $testDuration,
            'total_iterations' => $this->iterations,
            'samples' => $samples
        ]);

        echo "  ✅ Sustained load test completed:\n";
        echo "    • Duration: {$testDuration} seconds\n";
        echo "    • Iterations: {$this->iterations}\n";
        echo "    • Final growth: " . $this->formatBytes($totalGrowth) . "\n";
        echo "    • Peak growth: " . $this->formatBytes($peakGrowth) . "\n";
        echo "    • Average memory/iteration: " . $this->formatBytes($totalGrowth / max($this->iterations, 1)) . "\n\n";
    }

    private function simulateFrameworkOperations(): void
    {
        // Simulate typical framework operations that might cause memory leaks
        
        // Create temporary objects
        $tempData = [
            'id' => uniqid(),
            'timestamp' => microtime(true),
            'data' => array_fill(0, 50, 'temp_' . rand(1000, 9999))
        ];
        
        // Simulate serialization
        $serialized = json_encode($tempData);
        $deserialized = json_decode($serialized, true);
        
        // Simulate string operations
        $longString = str_repeat('framework_test_', 100) . $this->iterations;
        $processed = strtoupper(substr($longString, 0, 500));
        
        // Simulate array operations
        $array = array_fill(0, 100, $this->iterations);
        $filtered = array_filter($array, fn($n) => $n % 2 === 0);
        $mapped = array_map(fn($n) => $n * 2, $filtered);
        
        // Unset variables to help with cleanup
        unset($tempData, $serialized, $deserialized, $longString, $processed, $array, $filtered, $mapped);
    }

    private function takeMemorySnapshot(string $label): void
    {
        $this->memorySnapshots[$label] = [
            'timestamp' => microtime(true),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'iterations' => $this->iterations
        ];
    }

    private function recordMemoryTest(string $name, int $initialMemory, int $finalMemory, int $growth, array $extra = []): void
    {
        $this->testResults[] = [
            'name' => $name,
            'initial_memory' => $initialMemory,
            'final_memory' => $finalMemory,
            'memory_growth' => $growth,
            'growth_percentage' => $initialMemory > 0 ? ($growth / $initialMemory) * 100 : 0,
            'timestamp' => microtime(true),
            'extra_data' => $extra
        ];
    }

    private function formatBytes(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    private function generateMemoryLeakReport(): array
    {
        echo "📊 Memory Leak Detection Report\n";
        echo "==============================\n\n";
        
        $totalTests = count($this->testResults);
        $totalRuntime = microtime(true) - $this->startTime;
        
        echo "📈 Summary:\n";
        echo "  • Total Tests: {$totalTests}\n";
        echo "  • Total Runtime: " . round($totalRuntime, 2) . " seconds\n";
        echo "  • Total Iterations: {$this->iterations}\n\n";
        
        echo "📋 Memory Usage by Component:\n";
        foreach ($this->testResults as $test) {
            $status = $test['memory_growth'] < (50 * 1024 * 1024) ? '✅' : '⚠️'; // 50MB threshold
            echo "  {$status} {$test['name']}:\n";
            echo "    • Growth: " . $this->formatBytes($test['memory_growth']) . "\n";
            echo "    • Growth %: " . round($test['growth_percentage'], 2) . "%\n";
            echo "    • Final: " . $this->formatBytes($test['final_memory']) . "\n";
        }
        
        echo "\n📊 Memory Snapshots:\n";
        foreach ($this->memorySnapshots as $label => $snapshot) {
            echo "  • {$label}: " . $this->formatBytes($snapshot['memory_usage']) . 
                 " (peak: " . $this->formatBytes($snapshot['memory_peak']) . ")\n";
        }
        
        // Determine overall result
        $maxGrowth = max(array_column($this->testResults, 'memory_growth'));
        $memoryLeakDetected = $maxGrowth > (100 * 1024 * 1024); // 100MB threshold
        
        echo "\n🎯 Memory Leak Assessment:\n";
        if ($memoryLeakDetected) {
            echo "  ⚠️ POTENTIAL MEMORY LEAK DETECTED\n";
            echo "  • Maximum growth: " . $this->formatBytes($maxGrowth) . "\n";
            echo "  • Recommendation: Investigate components with high growth\n";
        } else {
            echo "  ✅ NO SIGNIFICANT MEMORY LEAKS DETECTED\n";
            echo "  • Maximum growth: " . $this->formatBytes($maxGrowth) . "\n";
            echo "  • Framework memory usage is stable\n";
        }
        
        return [
            'total_tests' => $totalTests,
            'runtime_seconds' => $totalRuntime,
            'total_iterations' => $this->iterations,
            'memory_leak_detected' => $memoryLeakDetected,
            'max_memory_growth' => $maxGrowth,
            'test_results' => $this->testResults,
            'memory_snapshots' => $this->memorySnapshots
        ];
    }
}

// CLI execution
if (php_sapi_name() === 'cli') {
    $tester = new MemoryLeakDetectionTest();
    $results = $tester->runMemoryLeakDetection();
    
    if (!$results['memory_leak_detected']) {
        echo "\n🎉 Memory leak detection test PASSED!\n";
        exit(0);
    } else {
        echo "\n⚠️ Memory leak detection test found potential issues!\n";
        exit(1);
    }
}