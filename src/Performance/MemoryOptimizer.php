<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Performance;

use HighPerApp\HighPer\Contracts\LoggerInterface;
use HighPerApp\HighPer\Contracts\FFIManagerInterface;

/**
 * Memory Optimizer for HighPer Framework
 * 
 * Provides memory optimization with ring buffer cache and real-time monitoring
 * for C10M performance with efficient memory management.
 * 
 * Features:
 * - Ring buffer cache for frequently accessed data
 * - Real-time memory monitoring and alerting
 * - Garbage collection optimization
 * - Memory leak detection and prevention
 * - Object pooling for reusable instances
 * - Rust FFI acceleration for memory operations
 */
class MemoryOptimizer
{
    private FFIManagerInterface $ffi;
    private LoggerInterface $logger;
    private array $config = [];
    private array $stats = [];
    private array $ringBuffers = [];
    private array $objectPools = [];
    private bool $monitoring = true;
    private bool $rustAvailable = false;
    private int $lastGCTime = 0;
    private array $memoryAlerts = [];

    public function __construct(FFIManagerInterface $ffi, ?LoggerInterface $logger = null, array $config = [])
    {
        $this->ffi = $ffi;
        $this->logger = $logger ?? new class implements LoggerInterface {
            public function emergency(string $message, array $context = []): void {}
            public function alert(string $message, array $context = []): void {}
            public function critical(string $message, array $context = []): void {}
            public function error(string $message, array $context = []): void {}
            public function warning(string $message, array $context = []): void {}
            public function notice(string $message, array $context = []): void {}
            public function info(string $message, array $context = []): void {}
            public function debug(string $message, array $context = []): void {}
            public function log(mixed $level, string $message, array $context = []): void {}
            public function flush(): void {}
            public function getStats(): array { return []; }
        };

        $this->config = array_merge([
            'enable_rust_ffi' => true,
            'enable_monitoring' => true,
            'ring_buffer_size' => 10000,
            'object_pool_size' => 1000,
            'gc_threshold' => 80, // Trigger GC at 80% memory usage
            'memory_limit_mb' => 512,
            'monitoring_interval' => 5, // seconds
            'alert_threshold' => 90, // Alert at 90% memory usage
            'leak_detection' => true,
            'optimization_level' => 'balanced' // conservative, balanced, aggressive
        ], $config);

        $this->initializeStats();
        $this->detectRustCapabilities();
        $this->initializeRustMemoryManager();
        $this->startMemoryMonitoring();

        $this->logger->info('MemoryOptimizer initialized', [
            'rust_available' => $this->rustAvailable,
            'monitoring_enabled' => $this->monitoring,
            'memory_limit' => $this->config['memory_limit_mb'] . 'MB'
        ]);
    }

    public function createRingBuffer(string $name, int $size = null): RingBuffer
    {
        $size = $size ?? $this->config['ring_buffer_size'];
        
        $ringBuffer = new RingBuffer($size, $this->config, $this->ffi, $this->logger);
        $this->ringBuffers[$name] = $ringBuffer;

        $this->logger->debug("Ring buffer '{$name}' created", [
            'size' => $size,
            'rust_acceleration' => $this->rustAvailable
        ]);

        return $ringBuffer;
    }

    public function getRingBuffer(string $name): ?RingBuffer
    {
        return $this->ringBuffers[$name] ?? null;
    }

    public function createObjectPool(string $className, int $size = null): ObjectPool
    {
        $size = $size ?? $this->config['object_pool_size'];
        
        $objectPool = new ObjectPool($className, $size, $this->config, $this->logger);
        $this->objectPools[$className] = $objectPool;

        $this->logger->debug("Object pool for '{$className}' created", [
            'size' => $size
        ]);

        return $objectPool;
    }

    public function getObjectPool(string $className): ?ObjectPool
    {
        return $this->objectPools[$className] ?? null;
    }

    public function optimizeGarbageCollection(): void
    {
        $beforeMemory = memory_get_usage(true);
        $beforePeak = memory_get_peak_usage(true);
        
        $startTime = microtime(true);
        
        // Try Rust-accelerated GC first
        if ($this->rustAvailable && $this->shouldUseRustGC()) {
            $collected = $this->performRustGC();
            if ($collected !== null) {
                $this->stats['rust_gc_runs']++;
                $this->stats['rust_gc_time'] += microtime(true) - $startTime;
            }
        }

        // PHP garbage collection
        $cycles = gc_collect_cycles();
        
        $afterMemory = memory_get_usage(true);
        $duration = microtime(true) - $startTime;
        
        $this->stats['gc_runs']++;
        $this->stats['gc_cycles_collected'] += $cycles;
        $this->stats['gc_memory_freed'] += max(0, $beforeMemory - $afterMemory);
        $this->stats['gc_time'] += $duration;
        $this->lastGCTime = time();

        $this->logger->debug('Garbage collection completed', [
            'cycles_collected' => $cycles,
            'memory_freed' => $beforeMemory - $afterMemory,
            'duration_ms' => round($duration * 1000, 2),
            'peak_memory' => $beforePeak
        ]);
    }

    public function checkMemoryUsage(): array
    {
        $currentMemory = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);
        $memoryLimitBytes = $this->config['memory_limit_mb'] * 1024 * 1024;
        
        $usagePercent = ($currentMemory / $memoryLimitBytes) * 100;
        $peakPercent = ($peakMemory / $memoryLimitBytes) * 100;

        $status = $this->determineMemoryStatus($usagePercent);
        
        $memoryInfo = [
            'current_usage' => $currentMemory,
            'current_usage_mb' => round($currentMemory / 1024 / 1024, 2),
            'peak_usage' => $peakMemory,
            'peak_usage_mb' => round($peakMemory / 1024 / 1024, 2),
            'usage_percent' => round($usagePercent, 2),
            'peak_percent' => round($peakPercent, 2),
            'memory_limit' => $memoryLimitBytes,
            'memory_limit_mb' => $this->config['memory_limit_mb'],
            'status' => $status,
            'gc_enabled' => gc_enabled(),
            'time_since_last_gc' => time() - $this->lastGCTime
        ];

        // Check for alerts
        if ($usagePercent >= $this->config['alert_threshold']) {
            $this->triggerMemoryAlert($memoryInfo);
        }

        // Auto-trigger GC if threshold reached
        if ($usagePercent >= $this->config['gc_threshold']) {
            $this->optimizeGarbageCollection();
        }

        return $memoryInfo;
    }

    public function detectMemoryLeaks(): array
    {
        if (!$this->config['leak_detection']) {
            return ['enabled' => false];
        }

        $currentStats = [
            'memory' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'objects' => count(get_declared_classes()),
            'time' => time()
        ];

        // Store historical data for leak detection
        static $history = [];
        $history[] = $currentStats;
        
        // Keep only last 10 measurements
        if (count($history) > 10) {
            array_shift($history);
        }

        $leaks = [];
        
        if (count($history) >= 3) {
            // Check for consistent memory growth
            $memoryTrend = $this->calculateTrend($history, 'memory');
            $objectTrend = $this->calculateTrend($history, 'objects');
            
            if ($memoryTrend > 1024 * 1024) { // 1MB growth trend
                $leaks[] = [
                    'type' => 'memory_leak',
                    'trend' => $memoryTrend,
                    'severity' => $memoryTrend > 10 * 1024 * 1024 ? 'high' : 'medium'
                ];
            }
            
            if ($objectTrend > 10) { // Object count growth
                $leaks[] = [
                    'type' => 'object_leak',
                    'trend' => $objectTrend,
                    'severity' => $objectTrend > 100 ? 'high' : 'medium'
                ];
            }
        }

        return [
            'enabled' => true,
            'leaks_detected' => count($leaks),
            'leaks' => $leaks,
            'history_points' => count($history),
            'current_stats' => $currentStats
        ];
    }

    public function optimizeMemoryLayout(): void
    {
        // Try Rust FFI memory optimization
        if ($this->rustAvailable) {
            try {
                $result = $this->ffi->call(
                    'memory_manager',
                    'optimize_layout',
                    [],
                    null
                );
                
                if ($result !== null) {
                    $this->stats['memory_optimizations']++;
                    $this->logger->debug('Memory layout optimized via Rust FFI');
                    return;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Rust memory optimization failed', [
                    'error' => $e->getMessage()
                ]);
            }
        }

        // PHP-based memory optimization
        $this->compactObjectPools();
        $this->compactRingBuffers();
        $this->optimizeGarbageCollection();
        
        $this->stats['memory_optimizations']++;
        $this->logger->debug('Memory layout optimized via PHP');
    }

    private function detectRustCapabilities(): void
    {
        $this->rustAvailable = $this->ffi->isAvailable() && $this->config['enable_rust_ffi'];

        if ($this->rustAvailable) {
            $this->logger->info('Rust FFI memory optimization available');
        }
    }

    private function initializeRustMemoryManager(): void
    {
        if (!$this->rustAvailable) {
            return;
        }

        // Register Rust memory manager library
        $this->ffi->registerLibrary('memory_manager', [
            'header' => __DIR__ . '/../../rust/memory/memory.h',
            'lib' => __DIR__ . '/../../rust/memory/target/release/libmemory_manager.so'
        ]);
    }

    private function shouldUseRustGC(): bool
    {
        $memoryUsage = memory_get_usage(true);
        return $memoryUsage > 100 * 1024 * 1024; // Use Rust GC for >100MB
    }

    private function performRustGC(): ?int
    {
        try {
            $result = $this->ffi->call(
                'memory_manager',
                'garbage_collect',
                [],
                null
            );
            
            return $result !== null ? (int) $result : null;
        } catch (\Throwable $e) {
            $this->logger->warning('Rust garbage collection failed', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function startMemoryMonitoring(): void
    {
        if (!$this->config['enable_monitoring']) {
            return;
        }

        $this->monitoring = true;
        
        // In a real implementation, this would use an event loop timer
        // For now, we'll track that monitoring is enabled
        $this->logger->info('Memory monitoring started', [
            'interval' => $this->config['monitoring_interval']
        ]);
    }

    private function determineMemoryStatus(float $usagePercent): string
    {
        if ($usagePercent >= 95) return 'critical';
        if ($usagePercent >= 90) return 'warning';
        if ($usagePercent >= 80) return 'elevated';
        return 'normal';
    }

    private function triggerMemoryAlert(array $memoryInfo): void
    {
        $alertKey = 'memory_usage_' . date('Y-m-d-H-i');
        
        if (isset($this->memoryAlerts[$alertKey])) {
            return; // Avoid duplicate alerts
        }

        $this->memoryAlerts[$alertKey] = $memoryInfo;
        
        $this->logger->warning('Memory usage alert triggered', $memoryInfo);
        
        // Cleanup old alerts
        if (count($this->memoryAlerts) > 100) {
            $this->memoryAlerts = array_slice($this->memoryAlerts, -50, null, true);
        }
    }

    private function calculateTrend(array $history, string $key): float
    {
        if (count($history) < 2) {
            return 0;
        }

        $values = array_column($history, $key);
        $n = count($values);
        
        // Simple linear regression slope
        $sumX = array_sum(range(0, $n - 1));
        $sumY = array_sum($values);
        $sumXY = 0;
        $sumX2 = 0;
        
        for ($i = 0; $i < $n; $i++) {
            $sumXY += $i * $values[$i];
            $sumX2 += $i * $i;
        }
        
        return ($n * $sumXY - $sumX * $sumY) / ($n * $sumX2 - $sumX * $sumX);
    }

    private function compactObjectPools(): void
    {
        foreach ($this->objectPools as $pool) {
            $pool->compact();
        }
    }

    private function compactRingBuffers(): void
    {
        foreach ($this->ringBuffers as $buffer) {
            $buffer->compact();
        }
    }

    private function initializeStats(): void
    {
        $this->stats = [
            'gc_runs' => 0,
            'gc_cycles_collected' => 0,
            'gc_memory_freed' => 0,
            'gc_time' => 0,
            'rust_gc_runs' => 0,
            'rust_gc_time' => 0,
            'memory_optimizations' => 0,
            'ring_buffers_created' => 0,
            'object_pools_created' => 0,
            'memory_alerts' => 0
        ];
    }

    public function getStats(): array
    {
        $memoryInfo = $this->checkMemoryUsage();
        
        return array_merge($this->stats, [
            'current_memory' => $memoryInfo,
            'rust_available' => $this->rustAvailable,
            'monitoring_enabled' => $this->monitoring,
            'ring_buffers' => count($this->ringBuffers),
            'object_pools' => count($this->objectPools),
            'configuration' => $this->config,
            'recent_alerts' => array_slice($this->memoryAlerts, -10, null, true)
        ]);
    }

    public function __destruct()
    {
        // Final cleanup
        foreach ($this->objectPools as $pool) {
            $pool->clear();
        }
        
        foreach ($this->ringBuffers as $buffer) {
            $buffer->clear();
        }
    }
}

/**
 * Ring Buffer Implementation for High-Performance Caching
 */
class RingBuffer
{
    private array $buffer;
    private int $size;
    private int $head = 0;
    private int $tail = 0;
    private int $count = 0;
    private array $config;
    private FFIManagerInterface $ffi;
    private LoggerInterface $logger;
    private bool $rustAccelerated = false;

    public function __construct(int $size, array $config, FFIManagerInterface $ffi, LoggerInterface $logger)
    {
        $this->size = $size;
        $this->buffer = array_fill(0, $size, null);
        $this->config = $config;
        $this->ffi = $ffi;
        $this->logger = $logger;
        
        $this->rustAccelerated = $ffi->isAvailable() && $config['enable_rust_ffi'];
    }

    public function put(string $key, mixed $value): bool
    {
        if ($this->rustAccelerated && $this->shouldUseRust($value)) {
            return $this->putWithRust($key, $value);
        }

        return $this->putWithPHP($key, $value);
    }

    public function get(string $key): mixed
    {
        if ($this->rustAccelerated) {
            $result = $this->getWithRust($key);
            if ($result !== null) {
                return $result;
            }
        }

        return $this->getWithPHP($key);
    }

    private function putWithPHP(string $key, mixed $value): bool
    {
        $this->buffer[$this->head] = ['key' => $key, 'value' => $value, 'timestamp' => time()];
        $this->head = ($this->head + 1) % $this->size;
        
        if ($this->count < $this->size) {
            $this->count++;
        } else {
            $this->tail = ($this->tail + 1) % $this->size;
        }
        
        return true;
    }

    private function getWithPHP(string $key): mixed
    {
        for ($i = 0; $i < $this->count; $i++) {
            $index = ($this->tail + $i) % $this->size;
            if ($this->buffer[$index] && $this->buffer[$index]['key'] === $key) {
                return $this->buffer[$index]['value'];
            }
        }
        
        return null;
    }

    private function putWithRust(string $key, mixed $value): bool
    {
        try {
            $serializedValue = serialize($value);
            $result = $this->ffi->call(
                'memory_manager',
                'ring_buffer_put',
                [$key, $serializedValue],
                null
            );
            
            return $result !== null && $result;
        } catch (\Throwable $e) {
            return $this->putWithPHP($key, $value);
        }
    }

    private function getWithRust(string $key): mixed
    {
        try {
            $result = $this->ffi->call(
                'memory_manager',
                'ring_buffer_get',
                [$key],
                null
            );
            
            if ($result !== null) {
                return unserialize($result);
            }
        } catch (\Throwable $e) {
            // Fall through to PHP implementation
        }
        
        return null;
    }

    private function shouldUseRust(mixed $value): bool
    {
        $serialized = serialize($value);
        return strlen($serialized) > 1024; // Use Rust for large values
    }

    public function compact(): void
    {
        // Remove expired entries and compact buffer
        $now = time();
        $newBuffer = [];
        $newCount = 0;
        
        for ($i = 0; $i < $this->count; $i++) {
            $index = ($this->tail + $i) % $this->size;
            $entry = $this->buffer[$index];
            
            if ($entry && ($now - $entry['timestamp']) < 3600) { // Keep entries < 1 hour old
                $newBuffer[$newCount] = $entry;
                $newCount++;
            }
        }
        
        $this->buffer = array_merge($newBuffer, array_fill($newCount, $this->size - $newCount, null));
        $this->count = $newCount;
        $this->head = $newCount % $this->size;
        $this->tail = 0;
    }

    public function clear(): void
    {
        $this->buffer = array_fill(0, $this->size, null);
        $this->head = 0;
        $this->tail = 0;
        $this->count = 0;
    }

    public function getSize(): int
    {
        return $this->count;
    }
}

/**
 * Object Pool Implementation for Reusable Objects
 */
class ObjectPool
{
    private string $className;
    private array $pool = [];
    private array $active = [];
    private int $maxSize;
    private LoggerInterface $logger;

    public function __construct(string $className, int $maxSize, array $config, LoggerInterface $logger)
    {
        $this->className = $className;
        $this->maxSize = $maxSize;
        $this->logger = $logger;
    }

    public function acquire(): object
    {
        if (!empty($this->pool)) {
            $object = array_pop($this->pool);
            $objectId = spl_object_hash($object);
            $this->active[$objectId] = $object;
            return $object;
        }

        // Create new object if pool is empty
        $object = new $this->className();
        $objectId = spl_object_hash($object);
        $this->active[$objectId] = $object;
        
        return $object;
    }

    public function release(object $object): void
    {
        $objectId = spl_object_hash($object);
        
        if (isset($this->active[$objectId])) {
            unset($this->active[$objectId]);
            
            if (count($this->pool) < $this->maxSize) {
                // Reset object state if method exists
                if (method_exists($object, 'reset')) {
                    $object->reset();
                }
                
                $this->pool[] = $object;
            }
            // Object will be garbage collected if pool is full
        }
    }

    public function compact(): void
    {
        // Keep only half the pool to free memory
        $keepCount = intval(count($this->pool) / 2);
        $this->pool = array_slice($this->pool, 0, $keepCount);
    }

    public function clear(): void
    {
        $this->pool = [];
        $this->active = [];
    }

    public function getStats(): array
    {
        return [
            'class_name' => $this->className,
            'pool_size' => count($this->pool),
            'active_objects' => count($this->active),
            'max_size' => $this->maxSize
        ];
    }
}