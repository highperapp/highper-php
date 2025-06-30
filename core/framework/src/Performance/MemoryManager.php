<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Performance;

use HighPerApp\HighPer\Contracts\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Memory Manager for C10M Performance
 * 
 * Manages memory usage optimization for high-concurrency scenarios.
 * Leverages object pooling from standalone libraries and implements
 * memory-efficient patterns for long-running server processes.
 */
class MemoryManager
{
    private array $config;
    private LoggerInterface $logger;
    private array $objectPools = [];
    private array $stats = [
        'allocations' => 0,
        'deallocations' => 0,
        'pool_hits' => 0,
        'pool_misses' => 0,
        'gc_runs' => 0,
        'memory_warnings' => 0
    ];
    
    private int $lastGcRun = 0;
    private int $memoryLimit;
    private int $warningThreshold;

    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->config = array_merge([
            'memory_limit_mb' => 512,
            'warning_threshold_percent' => 85,
            'gc_interval' => 30, // seconds
            'object_pool_enabled' => true,
            'pool_sizes' => [
                'response' => 1000,
                'request' => 1000,
                'connection' => 5000,
                'generic' => 500
            ]
        ], $config);
        
        $this->logger = $logger ?? new NullLogger();
        $this->memoryLimit = $this->config['memory_limit_mb'] * 1024 * 1024;
        $this->warningThreshold = (int) ($this->memoryLimit * $this->config['warning_threshold_percent'] / 100);
        
        $this->initializeObjectPools();
        $this->setupMemoryMonitoring();
    }

    /**
     * Get an object from the appropriate pool
     */
    public function getObject(string $type, array $initParams = []): object
    {
        if (!$this->config['object_pool_enabled']) {
            return $this->createObject($type, $initParams);
        }

        $pool = $this->getObjectPool($type);
        $object = $pool->acquire($initParams);
        
        if ($object) {
            $this->stats['pool_hits']++;
            return $object;
        }
        
        $this->stats['pool_misses']++;
        return $this->createObject($type, $initParams);
    }

    /**
     * Return an object to its pool
     */
    public function returnObject(string $type, object $object): void
    {
        if (!$this->config['object_pool_enabled']) {
            return;
        }

        $pool = $this->getObjectPool($type);
        $pool->release($object);
        $this->stats['deallocations']++;
    }

    /**
     * Check current memory usage and trigger cleanup if needed
     */
    public function checkMemoryUsage(): array
    {
        $currentUsage = memory_get_usage(true);
        $currentPeak = memory_get_peak_usage(true);
        $usagePercent = ($currentUsage / $this->memoryLimit) * 100;
        
        $status = [
            'current_bytes' => $currentUsage,
            'current_mb' => round($currentUsage / 1024 / 1024, 2),
            'peak_bytes' => $currentPeak,
            'peak_mb' => round($currentPeak / 1024 / 1024, 2),
            'limit_mb' => $this->config['memory_limit_mb'],
            'usage_percent' => round($usagePercent, 2),
            'status' => 'ok'
        ];

        if ($currentUsage > $this->warningThreshold) {
            $status['status'] = 'warning';
            $this->stats['memory_warnings']++;
            
            $this->logger->warning('High memory usage detected', $status);
            $this->triggerCleanup();
        }

        // Trigger garbage collection if interval passed
        if (time() - $this->lastGcRun > $this->config['gc_interval']) {
            $this->runGarbageCollection();
        }

        return $status;
    }

    /**
     * Force garbage collection and cleanup
     */
    public function triggerCleanup(): void
    {
        $beforeMemory = memory_get_usage(true);
        
        // Clean up object pools
        foreach ($this->objectPools as $pool) {
            $pool->cleanup();
        }
        
        // Run garbage collection
        $this->runGarbageCollection();
        
        $afterMemory = memory_get_usage(true);
        $freed = $beforeMemory - $afterMemory;
        
        $this->logger->info('Memory cleanup completed', [
            'freed_bytes' => $freed,
            'freed_mb' => round($freed / 1024 / 1024, 2),
            'before_mb' => round($beforeMemory / 1024 / 1024, 2),
            'after_mb' => round($afterMemory / 1024 / 1024, 2)
        ]);
    }

    /**
     * Get memory statistics
     */
    public function getStats(): array
    {
        $poolStats = [];
        foreach ($this->objectPools as $type => $pool) {
            $poolStats[$type] = $pool->getStats();
        }

        return array_merge($this->stats, [
            'memory' => $this->checkMemoryUsage(),
            'object_pools' => $poolStats,
            'gc_enabled' => gc_enabled(),
            'last_gc_run' => $this->lastGcRun
        ]);
    }

    /**
     * Optimize memory settings for long-running process
     */
    public function optimizeForLongRunning(): void
    {
        // Enable garbage collection
        gc_enable();
        
        // Optimize garbage collection settings
        ini_set('memory_limit', $this->config['memory_limit_mb'] . 'M');
        
        // Optimize realpath cache for long-running processes
        ini_set('realpath_cache_size', '4096K');
        ini_set('realpath_cache_ttl', '600');
        
        $this->logger->info('Memory optimized for long-running process', [
            'memory_limit' => $this->config['memory_limit_mb'] . 'M',
            'gc_enabled' => gc_enabled()
        ]);
    }

    private function initializeObjectPools(): void
    {
        if (!$this->config['object_pool_enabled']) {
            return;
        }

        foreach ($this->config['pool_sizes'] as $type => $size) {
            $this->objectPools[$type] = new ObjectPool($type, $size, $this->logger);
        }

        // Initialize specialized pools if external packages are available
        $this->initializeExternalPools();
    }

    private function initializeExternalPools(): void
    {
        // Use Response Pool from highper-container if available
        if (class_exists('\\EaseAppPHP\\HighPer\\Container\\ResponsePool')) {
            $this->objectPools['response'] = new ResponsePoolAdapter($this->logger);
        }

        // Use Object Pool from highper-container if available
        if (class_exists('\\EaseAppPHP\\HighPer\\Container\\ObjectPool')) {
            $this->objectPools['container_objects'] = new ContainerObjectPoolAdapter($this->logger);
        }
    }

    private function getObjectPool(string $type): ObjectPool
    {
        if (!isset($this->objectPools[$type])) {
            $defaultSize = $this->config['pool_sizes']['generic'] ?? 500;
            $this->objectPools[$type] = new ObjectPool($type, $defaultSize, $this->logger);
        }

        return $this->objectPools[$type];
    }

    private function createObject(string $type, array $initParams = []): object
    {
        $this->stats['allocations']++;
        
        switch ($type) {
            case 'response':
                return new ResponseObject($initParams);
            case 'request':
                return new RequestObject($initParams);
            case 'connection':
                return new ConnectionObject($initParams);
            default:
                return new GenericObject($type, $initParams);
        }
    }

    private function runGarbageCollection(): void
    {
        $beforeCycles = gc_status()['runs'] ?? 0;
        $collected = gc_collect_cycles();
        $afterCycles = gc_status()['runs'] ?? 0;
        
        $this->lastGcRun = time();
        $this->stats['gc_runs']++;
        
        if ($collected > 0) {
            $this->logger->debug('Garbage collection completed', [
                'collected_cycles' => $collected,
                'gc_runs' => $afterCycles - $beforeCycles
            ]);
        }
    }

    private function setupMemoryMonitoring(): void
    {
        // Set up periodic memory monitoring
        if (function_exists('register_tick_function')) {
            register_tick_function([$this, 'checkMemoryUsage']);
        }
    }
}

/**
 * Generic Object Pool
 */
class ObjectPool
{
    private string $type;
    private int $maxSize;
    private LoggerInterface $logger;
    private array $pool = [];
    private array $stats = [
        'acquisitions' => 0,
        'releases' => 0,
        'created' => 0,
        'cleaned' => 0
    ];

    public function __construct(string $type, int $maxSize, LoggerInterface $logger)
    {
        $this->type = $type;
        $this->maxSize = $maxSize;
        $this->logger = $logger;
    }

    public function acquire(array $initParams = []): ?object
    {
        if (!empty($this->pool)) {
            $object = array_pop($this->pool);
            $this->stats['acquisitions']++;
            
            // Reset object state if possible
            if (method_exists($object, 'reset')) {
                $object->reset($initParams);
            }
            
            return $object;
        }

        return null;
    }

    public function release(object $object): void
    {
        if (count($this->pool) < $this->maxSize) {
            // Clean object state if possible
            if (method_exists($object, 'cleanup')) {
                $object->cleanup();
            }
            
            $this->pool[] = $object;
            $this->stats['releases']++;
        }
    }

    public function cleanup(): void
    {
        $cleaned = count($this->pool);
        $this->pool = [];
        $this->stats['cleaned'] += $cleaned;
        
        if ($cleaned > 0) {
            $this->logger->debug("Cleaned {$cleaned} objects from {$this->type} pool");
        }
    }

    public function getStats(): array
    {
        return array_merge($this->stats, [
            'pool_size' => count($this->pool),
            'max_size' => $this->maxSize,
            'type' => $this->type
        ]);
    }
}

/**
 * Response Pool Adapter for highper-container
 */
class ResponsePoolAdapter extends ObjectPool
{
    private $responsePool;

    public function __construct(LoggerInterface $logger)
    {
        parent::__construct('response', 1000, $logger);
        
        if (class_exists('\\EaseAppPHP\\HighPer\\Container\\ResponsePool')) {
            $this->responsePool = new \HighPerApp\HighPer\Container\ResponsePool();
        }
    }

    public function acquire(array $initParams = []): ?object
    {
        if ($this->responsePool && method_exists($this->responsePool, 'get')) {
            return $this->responsePool->get();
        }
        
        return parent::acquire($initParams);
    }

    public function release(object $object): void
    {
        if ($this->responsePool && method_exists($this->responsePool, 'release')) {
            $this->responsePool->release($object);
            return;
        }
        
        parent::release($object);
    }
}

/**
 * Container Object Pool Adapter
 */
class ContainerObjectPoolAdapter extends ObjectPool
{
    private $objectPool;

    public function __construct(LoggerInterface $logger)
    {
        parent::__construct('container_objects', 1000, $logger);
        
        if (class_exists('\\EaseAppPHP\\HighPer\\Container\\ObjectPool')) {
            $this->objectPool = new \HighPerApp\HighPer\Container\ObjectPool();
        }
    }

    public function acquire(array $initParams = []): ?object
    {
        if ($this->objectPool && method_exists($this->objectPool, 'get')) {
            return $this->objectPool->get($initParams['class'] ?? 'stdClass');
        }
        
        return parent::acquire($initParams);
    }

    public function release(object $object): void
    {
        if ($this->objectPool && method_exists($this->objectPool, 'release')) {
            $this->objectPool->release($object);
            return;
        }
        
        parent::release($object);
    }
}

/**
 * Poolable Object Implementations
 */
class ResponseObject
{
    private array $headers = [];
    private string $body = '';
    private int $statusCode = 200;

    public function __construct(array $params = [])
    {
        $this->reset($params);
    }

    public function reset(array $params = []): void
    {
        $this->headers = $params['headers'] ?? [];
        $this->body = $params['body'] ?? '';
        $this->statusCode = $params['status'] ?? 200;
    }

    public function cleanup(): void
    {
        $this->headers = [];
        $this->body = '';
        $this->statusCode = 200;
    }
}

class RequestObject
{
    private string $method = 'GET';
    private string $uri = '/';
    private array $headers = [];
    private string $body = '';

    public function __construct(array $params = [])
    {
        $this->reset($params);
    }

    public function reset(array $params = []): void
    {
        $this->method = $params['method'] ?? 'GET';
        $this->uri = $params['uri'] ?? '/';
        $this->headers = $params['headers'] ?? [];
        $this->body = $params['body'] ?? '';
    }

    public function cleanup(): void
    {
        $this->method = 'GET';
        $this->uri = '/';
        $this->headers = [];
        $this->body = '';
    }
}

class ConnectionObject
{
    private string $id;
    private int $createdAt;
    private int $lastUsed;
    private array $metadata = [];

    public function __construct(array $params = [])
    {
        $this->id = $params['id'] ?? uniqid();
        $this->createdAt = time();
        $this->reset($params);
    }

    public function reset(array $params = []): void
    {
        $this->lastUsed = time();
        $this->metadata = $params['metadata'] ?? [];
    }

    public function cleanup(): void
    {
        $this->metadata = [];
    }
}

class GenericObject
{
    private string $type;
    private array $data = [];

    public function __construct(string $type, array $params = [])
    {
        $this->type = $type;
        $this->reset($params);
    }

    public function reset(array $params = []): void
    {
        $this->data = $params;
    }

    public function cleanup(): void
    {
        $this->data = [];
    }
}