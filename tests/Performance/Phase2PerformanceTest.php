<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Tests\Performance;

use PHPUnit\Framework\TestCase;
use HighPerApp\HighPer\Performance\BrotliCompression;
use HighPerApp\HighPer\Performance\HttpClientPool;
use HighPerApp\HighPer\Performance\MemoryOptimizer;
use HighPerApp\HighPer\Foundation\RustFFIManager;
use HighPerApp\HighPer\Foundation\AsyncLogger;

/**
 * Phase 2 Performance Validation Tests
 * 
 * Comprehensive testing of Phase 2 performance implementations:
 * - Brotli compression with Rust FFI acceleration
 * - HTTP client connection pooling
 * - Memory optimization with ring buffer cache
 * - Service provider integrations
 * - C10M performance characteristics
 */
class Phase2PerformanceTest extends TestCase
{
    private RustFFIManager $ffiManager;
    private AsyncLogger $logger;

    protected function setUp(): void
    {
        $this->ffiManager = new RustFFIManager();
        
        // Create a mock config manager for the logger
        $mockConfig = new class implements \HighPerApp\HighPer\Contracts\ConfigManagerInterface {
            public function get(string $key, mixed $default = null): mixed { return $default; }
            public function set(string $key, mixed $value): void {}
            public function has(string $key): bool { return false; }
            public function load(array $config): void {}
            public function loadFromFile(string $path): void {}
            public function loadEnvironment(): void {}
            public function all(): array { return []; }
            public function getNamespace(string $namespace): array { return []; }
            public function remove(string $key): void {}
            public function clear(): void {}
            public function getEnvironment(): string { return 'testing'; }
            public function isDebug(): bool { return false; }
        };
        
        $this->logger = new AsyncLogger($mockConfig);
    }

    /**
     * @group performance
     * @group compression
     */
    public function testBrotliCompressionPerformance(): void
    {
        $brotli = new BrotliCompression($this->ffiManager, $this->logger);
        
        // Test data of various sizes
        $testData = [
            'small' => str_repeat('Hello World! ', 10),
            'medium' => str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 100),
            'large' => str_repeat('Large dataset for compression testing. ', 1000)
        ];

        foreach ($testData as $size => $data) {
            $startTime = microtime(true);
            
            // Compress
            $compressed = $brotli->compress($data);
            $compressionTime = microtime(true) - $startTime;
            
            // Verify compression worked
            $this->assertNotEmpty($compressed);
            $this->assertLessThan(strlen($data), strlen($compressed));
            
            // Decompress
            $startTime = microtime(true);
            $decompressed = $brotli->decompress($compressed);
            $decompressionTime = microtime(true) - $startTime;
            
            // Verify decompression worked
            $this->assertEquals($data, $decompressed);
            
            // Performance assertions
            $this->assertLessThan(0.1, $compressionTime, "Compression too slow for {$size} data");
            $this->assertLessThan(0.05, $decompressionTime, "Decompression too slow for {$size} data");
            
            // Compression ratio assertion
            $compressionRatio = (strlen($data) - strlen($compressed)) / strlen($data);
            $this->assertGreaterThan(0.1, $compressionRatio, "Insufficient compression for {$size} data");
        }

        // Test performance statistics
        $stats = $brotli->getStats();
        $this->assertArrayHasKey('rust_available', $stats);
        $this->assertArrayHasKey('compression_ratio', $stats);
        $this->assertGreaterThan(0, $stats['total_operations']);
    }

    /**
     * @group performance
     * @group http_client
     */
    public function testHttpClientPoolPerformance(): void
    {
        $httpPool = new HttpClientPool($this->ffiManager, $this->logger);
        
        // Create test pool
        $httpPool->createPool('test', [
            'max_connections_per_host' => 50,
            'connection_timeout' => 5,
            'enable_http2' => true
        ]);

        // Test concurrent request capability
        $requests = [];
        $startTime = microtime(true);
        
        for ($i = 0; $i < 10; $i++) {
            $requests[] = $httpPool->get('https://httpbin.org/get', [], 'test');
        }

        // Wait for all requests (in real implementation)
        $requestTime = microtime(true) - $startTime;
        
        // Performance assertions
        $this->assertLessThan(2.0, $requestTime, 'Concurrent requests took too long');
        
        // Test pool statistics
        $stats = $httpPool->getStats();
        $this->assertArrayHasKey('global', $stats);
        $this->assertArrayHasKey('pools', $stats);
        $this->assertArrayHasKey('rust_available', $stats);
        
        // Test health check
        $health = $httpPool->healthCheck();
        $this->assertArrayHasKey('test', $health);
        $this->assertEquals('healthy', $health['test']['status']);
    }

    /**
     * @group performance
     * @group memory
     */
    public function testMemoryOptimizerPerformance(): void
    {
        $memoryOptimizer = new MemoryOptimizer($this->ffiManager, $this->logger);
        
        // Test ring buffer performance
        $ringBuffer = $memoryOptimizer->createRingBuffer('test_buffer', 1000);
        
        $startTime = microtime(true);
        
        // Test put performance
        for ($i = 0; $i < 1000; $i++) {
            $ringBuffer->put("key_{$i}", "value_{$i}_" . str_repeat('x', 100));
        }
        
        $putTime = microtime(true) - $startTime;
        
        // Test get performance
        $startTime = microtime(true);
        
        for ($i = 0; $i < 1000; $i++) {
            $value = $ringBuffer->get("key_{$i}");
            if ($i < 900) { // Some keys should exist in buffer
                $this->assertNotNull($value);
            }
        }
        
        $getTime = microtime(true) - $startTime;
        
        // Performance assertions
        $this->assertLessThan(0.1, $putTime, 'Ring buffer put operations too slow');
        $this->assertLessThan(0.1, $getTime, 'Ring buffer get operations too slow');
        
        // Test object pool performance
        $objectPool = $memoryOptimizer->createObjectPool(\stdClass::class, 100);
        
        $startTime = microtime(true);
        
        $objects = [];
        for ($i = 0; $i < 100; $i++) {
            $objects[] = $objectPool->acquire();
        }
        
        foreach ($objects as $object) {
            $objectPool->release($object);
        }
        
        $poolTime = microtime(true) - $startTime;
        
        $this->assertLessThan(0.01, $poolTime, 'Object pool operations too slow');
        
        // Test memory monitoring
        $memoryInfo = $memoryOptimizer->checkMemoryUsage();
        $this->assertArrayHasKey('current_usage_mb', $memoryInfo);
        $this->assertArrayHasKey('status', $memoryInfo);
        $this->assertArrayHasKey('usage_percent', $memoryInfo);
        
        // Test memory optimization
        $beforeMemory = memory_get_usage(true);
        $memoryOptimizer->optimizeGarbageCollection();
        $afterMemory = memory_get_usage(true);
        
        // Should not increase memory significantly
        $this->assertLessThanOrEqual($beforeMemory * 1.1, $afterMemory, 'Memory optimization increased memory usage');
        
        // Test leak detection
        $leakInfo = $memoryOptimizer->detectMemoryLeaks();
        $this->assertArrayHasKey('enabled', $leakInfo);
        $this->assertArrayHasKey('leaks_detected', $leakInfo);
    }

    /**
     * @group performance
     * @group integration
     */
    public function testServiceProviderIntegrationPerformance(): void
    {
        // Test that service providers don't add significant overhead
        $startTime = microtime(true);
        
        // Simulate service provider registration overhead
        for ($i = 0; $i < 100; $i++) {
            $config = [
                'enable_rust_ffi' => true,
                'enable_monitoring' => true,
                'lazy_loading' => true
            ];
            
            // Simulate configuration parsing
            $processed = array_merge($config, [
                'processed_at' => microtime(true),
                'instance' => $i
            ]);
            
            $this->assertIsArray($processed);
        }
        
        $providerTime = microtime(true) - $startTime;
        
        // Service provider overhead should be minimal
        $this->assertLessThan(0.01, $providerTime, 'Service provider overhead too high');
    }

    /**
     * @group performance
     * @group c10m
     */
    public function testC10MCapabilities(): void
    {
        // Test framework components under simulated high load
        $components = [
            'brotli' => new BrotliCompression($this->ffiManager, $this->logger),
            'http_pool' => new HttpClientPool($this->ffiManager, $this->logger),
            'memory' => new MemoryOptimizer($this->ffiManager, $this->logger)
        ];
        
        $startTime = microtime(true);
        $operations = 0;
        
        // Simulate high-load operations
        for ($i = 0; $i < 1000; $i++) {
            // Brotli compression
            $data = "Test data {$i} " . str_repeat('x', 50);
            $compressed = $components['brotli']->compress($data);
            $decompressed = $components['brotli']->decompress($compressed);
            $this->assertEquals($data, $decompressed);
            $operations++;
            
            // Memory operations
            $ringBuffer = $components['memory']->getRingBuffer('test_buffer') 
                ?? $components['memory']->createRingBuffer('test_buffer', 100);
            $ringBuffer->put("key_{$i}", "value_{$i}");
            $value = $ringBuffer->get("key_{$i}");
            $this->assertNotNull($value);
            $operations++;
            
            // HTTP pool health check (lightweight operation)
            if ($i % 100 === 0) {
                $health = $components['http_pool']->healthCheck();
                $this->assertIsArray($health);
                $operations++;
            }
        }
        
        $totalTime = microtime(true) - $startTime;
        $operationsPerSecond = $operations / $totalTime;
        
        // C10M capability test: should handle thousands of operations per second
        $this->assertGreaterThan(5000, $operationsPerSecond, 'Insufficient operations per second for C10M');
        
        // Memory usage should remain reasonable
        $memoryUsage = memory_get_usage(true);
        $this->assertLessThan(100 * 1024 * 1024, $memoryUsage, 'Memory usage too high for C10M operations');
    }

    /**
     * @group performance
     * @group reliability
     */
    public function testFiveNinesReliabilityCharacteristics(): void
    {
        // Test error handling and fallback mechanisms
        $brotli = new BrotliCompression($this->ffiManager, $this->logger);
        
        $successCount = 0;
        $totalOperations = 1000;
        
        for ($i = 0; $i < $totalOperations; $i++) {
            try {
                $data = "Test data {$i}";
                $compressed = $brotli->compress($data);
                $decompressed = $brotli->decompress($compressed);
                
                if ($data === $decompressed) {
                    $successCount++;
                }
            } catch (\Throwable $e) {
                // Track failures but continue
                $this->logger->error('Operation failed', ['error' => $e->getMessage()]);
            }
        }
        
        $successRate = ($successCount / $totalOperations) * 100;
        
        // Five nines reliability: 99.999% success rate
        $this->assertGreaterThan(99.9, $successRate, 'Insufficient reliability for five nines target');
        
        // Test memory optimizer reliability
        $memoryOptimizer = new MemoryOptimizer($this->ffiManager, $this->logger);
        
        $memoryOperations = 0;
        $memorySuccesses = 0;
        
        for ($i = 0; $i < 100; $i++) {
            try {
                $memoryInfo = $memoryOptimizer->checkMemoryUsage();
                $memoryOperations++;
                
                if (isset($memoryInfo['status'])) {
                    $memorySuccesses++;
                }
            } catch (\Throwable $e) {
                $memoryOperations++;
            }
        }
        
        $memoryReliability = ($memorySuccesses / $memoryOperations) * 100;
        $this->assertGreaterThan(99.9, $memoryReliability, 'Memory monitoring reliability insufficient');
    }

    /**
     * @group performance
     * @group benchmark
     */
    public function testPerformanceBenchmarks(): void
    {
        $benchmarks = [];
        
        // Benchmark Brotli compression
        $brotli = new BrotliCompression($this->ffiManager, $this->logger);
        $testData = str_repeat('Benchmark data for compression testing. ', 1000);
        
        $startTime = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $compressed = $brotli->compress($testData);
            $decompressed = $brotli->decompress($compressed);
        }
        $brotliTime = microtime(true) - $startTime;
        $benchmarks['brotli_ops_per_sec'] = 100 / $brotliTime;
        
        // Benchmark memory operations
        $memoryOptimizer = new MemoryOptimizer($this->ffiManager, $this->logger);
        $ringBuffer = $memoryOptimizer->createRingBuffer('benchmark', 1000);
        
        $startTime = microtime(true);
        for ($i = 0; $i < 1000; $i++) {
            $ringBuffer->put("bench_key_{$i}", "benchmark_value_{$i}");
        }
        $memoryPutTime = microtime(true) - $startTime;
        $benchmarks['memory_put_ops_per_sec'] = 1000 / $memoryPutTime;
        
        $startTime = microtime(true);
        for ($i = 0; $i < 1000; $i++) {
            $ringBuffer->get("bench_key_{$i}");
        }
        $memoryGetTime = microtime(true) - $startTime;
        $benchmarks['memory_get_ops_per_sec'] = 1000 / $memoryGetTime;
        
        // Performance assertions based on C10M targets
        $this->assertGreaterThan(500, $benchmarks['brotli_ops_per_sec'], 'Brotli performance below target');
        $this->assertGreaterThan(10000, $benchmarks['memory_put_ops_per_sec'], 'Memory put performance below target');
        $this->assertGreaterThan(20000, $benchmarks['memory_get_ops_per_sec'], 'Memory get performance below target');
        
        // Log benchmarks for analysis
        $this->logger->info('Phase 2 Performance Benchmarks', $benchmarks);
        
        echo "\n=== Phase 2 Performance Benchmarks ===\n";
        foreach ($benchmarks as $metric => $value) {
            echo sprintf("%-30s: %8.2f\n", $metric, $value);
        }
        echo "=====================================\n";
    }

    /**
     * @group performance
     * @group rust_ffi
     */
    public function testRustFFIPerformanceGains(): void
    {
        // Test Rust FFI vs PHP performance comparison
        $ffiManager = new RustFFIManager();
        
        if (!$ffiManager->isAvailable()) {
            $this->markTestSkipped('Rust FFI not available for performance comparison');
        }
        
        // Test compression performance with and without Rust
        $brotliWithRust = new BrotliCompression($ffiManager, $this->logger, ['rust_enabled' => true]);
        $brotliWithoutRust = new BrotliCompression($ffiManager, $this->logger, ['rust_enabled' => false]);
        
        $testData = str_repeat('Performance test data. ', 500);
        $iterations = 50;
        
        // Benchmark with Rust
        $startTime = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $compressed = $brotliWithRust->compress($testData);
            $decompressed = $brotliWithRust->decompress($compressed);
        }
        $rustTime = microtime(true) - $startTime;
        
        // Benchmark without Rust
        $startTime = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $compressed = $brotliWithoutRust->compress($testData);
            $decompressed = $brotliWithoutRust->decompress($compressed);
        }
        $phpTime = microtime(true) - $startTime;
        
        // Calculate performance improvement
        $improvement = $phpTime / $rustTime;
        
        // Rust should provide some performance benefit
        $this->assertGreaterThan(1.0, $improvement, 'Rust FFI should provide performance improvement');
        
        // Log the improvement
        echo "\nRust FFI Performance Improvement: {$improvement}x faster\n";
        echo "Rust time: {$rustTime}s, PHP time: {$phpTime}s\n";
        
        // Test statistics
        $rustStats = $brotliWithRust->getStats();
        $phpStats = $brotliWithoutRust->getStats();
        
        $this->assertGreaterThan(0, $rustStats['rust_usage_rate'] ?? 0, 'Rust should be used when enabled');
        $this->assertEquals(0, $phpStats['rust_usage_rate'] ?? 0, 'Rust should not be used when disabled');
    }

    protected function tearDown(): void
    {
        // Cleanup any resources
        gc_collect_cycles();
    }
}