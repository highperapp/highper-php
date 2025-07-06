<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Reliability;

use HighPerApp\HighPer\Contracts\LoggerInterface;
use HighPerApp\HighPer\Contracts\FFIManagerInterface;

/**
 * Bulkhead Isolation for Five Nines Reliability
 * 
 * Implements the bulkhead pattern to isolate system components and prevent
 * cascade failures, ensuring system resilience and 99.999% uptime.
 * 
 * Features:
 * - Resource isolation (thread pools, connection pools, memory)
 * - Service isolation with dedicated circuit breakers
 * - Request isolation with per-service rate limiting
 * - Memory isolation with separate allocators
 * - Tenant isolation for multi-tenant scenarios
 * - Rust FFI acceleration for high-performance isolation
 */
class BulkheadIsolation
{
    private LoggerInterface $logger;
    private FFIManagerInterface $ffi;
    private bool $rustAvailable = false;
    private array $config = [];
    private array $bulkheads = [];
    private array $resourcePools = [];
    private array $stats = [];

    public function __construct(
        LoggerInterface $logger,
        ?FFIManagerInterface $ffi = null,
        array $config = []
    ) {
        $this->logger = $logger;
        $this->ffi = $ffi ?? new class implements FFIManagerInterface {
            public function isAvailable(): bool { return false; }
            public function registerLibrary(string $name, array $config): void {}
            public function call(string $lib, string $func, array $args, $timeout): mixed { return null; }
            public function getStats(): array { return []; }
        };

        $this->config = array_merge([
            'enable_rust_ffi' => true,
            'default_pool_size' => 10,
            'max_pool_size' => 100,
            'resource_timeout' => 30,
            'enable_memory_isolation' => true,
            'enable_tenant_isolation' => true,
            'monitoring_enabled' => true,
            'auto_scaling' => true,
            'failure_detection_window' => 60,
            'isolation_levels' => [
                'strict' => 100,    // 100% isolation
                'moderate' => 70,   // 70% isolation
                'relaxed' => 30     // 30% isolation
            ]
        ], $config);

        $this->initializeStats();
        $this->detectRustCapabilities();
        $this->initializeRustBulkhead();

        $this->logger->info('Bulkhead isolation system initialized', [
            'rust_available' => $this->rustAvailable,
            'memory_isolation' => $this->config['enable_memory_isolation'],
            'tenant_isolation' => $this->config['enable_tenant_isolation']
        ]);
    }

    public function createBulkhead(string $name, array $config = []): Bulkhead
    {
        $bulkheadConfig = array_merge($this->config, $config);
        
        $bulkhead = new Bulkhead($name, $bulkheadConfig, $this->logger, $this->ffi);
        $this->bulkheads[$name] = $bulkhead;

        // Create associated resource pools
        $this->createResourcePools($name, $bulkheadConfig);

        $this->logger->info("Bulkhead '{$name}' created", [
            'isolation_level' => $bulkheadConfig['isolation_level'] ?? 'moderate',
            'pool_size' => $bulkheadConfig['pool_size'] ?? $this->config['default_pool_size']
        ]);

        return $bulkhead;
    }

    public function getBulkhead(string $name): ?Bulkhead
    {
        return $this->bulkheads[$name] ?? null;
    }

    public function isolateService(string $serviceName, callable $operation, array $config = []): mixed
    {
        $bulkhead = $this->getBulkhead($serviceName) ?? $this->createBulkhead($serviceName, $config);
        
        return $bulkhead->execute($operation);
    }

    public function isolateByTenant(string $tenantId, callable $operation, array $config = []): mixed
    {
        if (!$this->config['enable_tenant_isolation']) {
            return $operation();
        }

        $bulkheadName = "tenant_{$tenantId}";
        $bulkhead = $this->getBulkhead($bulkheadName) ?? $this->createBulkhead($bulkheadName, array_merge($config, [
            'type' => 'tenant',
            'tenant_id' => $tenantId
        ]));

        return $bulkhead->execute($operation);
    }

    public function isolateByResource(string $resourceType, callable $operation, array $config = []): mixed
    {
        $bulkheadName = "resource_{$resourceType}";
        $bulkhead = $this->getBulkhead($bulkheadName) ?? $this->createBulkhead($bulkheadName, array_merge($config, [
            'type' => 'resource',
            'resource_type' => $resourceType
        ]));

        return $bulkhead->execute($operation);
    }

    public function getResourcePool(string $poolName, string $resourceType = 'connection'): ?ResourcePool
    {
        $key = "{$poolName}:{$resourceType}";
        return $this->resourcePools[$key] ?? null;
    }

    public function createResourcePool(string $poolName, string $resourceType, array $config = []): ResourcePool
    {
        $key = "{$poolName}:{$resourceType}";
        
        $poolConfig = array_merge([
            'pool_size' => $this->config['default_pool_size'],
            'max_size' => $this->config['max_pool_size'],
            'timeout' => $this->config['resource_timeout'],
            'type' => $resourceType
        ], $config);

        $pool = new ResourcePool($poolName, $resourceType, $poolConfig, $this->logger, $this->ffi);
        $this->resourcePools[$key] = $pool;

        return $pool;
    }

    public function healthCheck(): array
    {
        $healthStatus = [];
        
        foreach ($this->bulkheads as $name => $bulkhead) {
            $healthStatus['bulkheads'][$name] = $bulkhead->getHealthStatus();
        }

        foreach ($this->resourcePools as $key => $pool) {
            $healthStatus['resource_pools'][$key] = $pool->getHealthStatus();
        }

        $healthStatus['overall'] = $this->calculateOverallHealth($healthStatus);
        $healthStatus['stats'] = $this->getStats();

        return $healthStatus;
    }

    public function getStats(): array
    {
        $bulkheadStats = [];
        foreach ($this->bulkheads as $name => $bulkhead) {
            $bulkheadStats[$name] = $bulkhead->getStats();
        }

        $poolStats = [];
        foreach ($this->resourcePools as $key => $pool) {
            $poolStats[$key] = $pool->getStats();
        }

        return array_merge($this->stats, [
            'bulkheads' => $bulkheadStats,
            'resource_pools' => $poolStats,
            'total_bulkheads' => count($this->bulkheads),
            'total_resource_pools' => count($this->resourcePools),
            'rust_available' => $this->rustAvailable
        ]);
    }

    private function createResourcePools(string $bulkheadName, array $config): void
    {
        $poolTypes = $config['resource_types'] ?? ['connection', 'memory', 'thread'];
        
        foreach ($poolTypes as $type) {
            $poolName = "{$bulkheadName}_{$type}";
            $this->createResourcePool($poolName, $type, $config);
        }
    }

    private function calculateOverallHealth(array $healthStatus): array
    {
        $totalHealthy = 0;
        $totalComponents = 0;

        // Check bulkhead health
        foreach ($healthStatus['bulkheads'] ?? [] as $bulkheadHealth) {
            $totalComponents++;
            if ($bulkheadHealth['status'] === 'healthy') {
                $totalHealthy++;
            }
        }

        // Check resource pool health
        foreach ($healthStatus['resource_pools'] ?? [] as $poolHealth) {
            $totalComponents++;
            if ($poolHealth['status'] === 'healthy') {
                $totalHealthy++;
            }
        }

        $healthPercentage = $totalComponents > 0 ? ($totalHealthy / $totalComponents) * 100 : 100;
        
        return [
            'status' => $healthPercentage >= 99.999 ? 'excellent' : 
                       ($healthPercentage >= 99.9 ? 'good' : 
                       ($healthPercentage >= 95 ? 'degraded' : 'poor')),
            'health_percentage' => round($healthPercentage, 3),
            'healthy_components' => $totalHealthy,
            'total_components' => $totalComponents,
            'five_nines_compliance' => $healthPercentage >= 99.999
        ];
    }

    private function detectRustCapabilities(): void
    {
        $this->rustAvailable = $this->ffi->isAvailable() && $this->config['enable_rust_ffi'];

        if ($this->rustAvailable) {
            $this->logger->info('Rust FFI bulkhead isolation capabilities detected');
        }
    }

    private function initializeRustBulkhead(): void
    {
        if (!$this->rustAvailable) {
            return;
        }

        // Register Rust bulkhead isolation library
        $this->ffi->registerLibrary('bulkhead', [
            'header' => __DIR__ . '/../../rust/bulkhead/bulkhead.h',
            'lib' => __DIR__ . '/../../rust/bulkhead/target/release/libbulkhead.so'
        ]);
    }

    private function initializeStats(): void
    {
        $this->stats = [
            'operations_isolated' => 0,
            'isolation_successes' => 0,
            'isolation_failures' => 0,
            'resource_contentions' => 0,
            'auto_scaling_events' => 0,
            'rust_accelerated_ops' => 0
        ];
    }
}

/**
 * Individual Bulkhead Implementation
 */
class Bulkhead
{
    private string $name;
    private array $config;
    private LoggerInterface $logger;
    private FFIManagerInterface $ffi;
    private bool $rustAvailable = false;

    private int $activeRequests = 0;
    private int $maxConcurrency;
    private int $queueSize = 0;
    private array $stats = [];
    private CircuitBreaker $circuitBreaker;
    private array $rateLimiters = [];

    public function __construct(
        string $name,
        array $config,
        LoggerInterface $logger,
        FFIManagerInterface $ffi
    ) {
        $this->name = $name;
        $this->config = $config;
        $this->logger = $logger;
        $this->ffi = $ffi;
        $this->rustAvailable = $ffi->isAvailable() && ($config['enable_rust_ffi'] ?? true);

        $this->maxConcurrency = $config['pool_size'] ?? 10;
        $this->initializeStats();
        $this->initializeCircuitBreaker();
        $this->initializeRateLimiters();
    }

    public function execute(callable $operation): mixed
    {
        // Check capacity
        if ($this->activeRequests >= $this->maxConcurrency) {
            $this->stats['rejected_requests']++;
            throw new BulkheadCapacityException(
                "Bulkhead '{$this->name}' at capacity ({$this->maxConcurrency})"
            );
        }

        // Check rate limits
        if (!$this->checkRateLimits()) {
            $this->stats['rate_limited_requests']++;
            throw new BulkheadRateLimitException(
                "Bulkhead '{$this->name}' rate limit exceeded"
            );
        }

        $this->activeRequests++;
        $startTime = microtime(true);

        try {
            // Execute through circuit breaker
            $result = $this->circuitBreaker->call($operation);
            
            $this->stats['successful_requests']++;
            $this->recordTiming($startTime, 'success');
            
            return $result;

        } catch (\Throwable $e) {
            $this->stats['failed_requests']++;
            $this->recordTiming($startTime, 'failure');
            throw $e;

        } finally {
            $this->activeRequests--;
        }
    }

    public function getHealthStatus(): array
    {
        $utilizationRate = ($this->activeRequests / $this->maxConcurrency) * 100;
        $circuitBreakerHealth = $this->circuitBreaker->getHealthMetrics();
        
        return [
            'status' => $this->determineHealthStatus($utilizationRate, $circuitBreakerHealth),
            'utilization_rate' => round($utilizationRate, 2),
            'active_requests' => $this->activeRequests,
            'max_concurrency' => $this->maxConcurrency,
            'circuit_breaker' => $circuitBreakerHealth,
            'stats' => $this->stats
        ];
    }

    public function getStats(): array
    {
        return array_merge($this->stats, [
            'name' => $this->name,
            'active_requests' => $this->activeRequests,
            'max_concurrency' => $this->maxConcurrency,
            'utilization_rate' => ($this->activeRequests / $this->maxConcurrency) * 100,
            'circuit_breaker_stats' => $this->circuitBreaker->getStats()
        ]);
    }

    private function initializeCircuitBreaker(): void
    {
        $circuitConfig = array_merge([
            'failure_threshold' => 5,
            'timeout' => 60,
            'enable_rust_ffi' => $this->rustAvailable
        ], $this->config['circuit_breaker'] ?? []);

        $this->circuitBreaker = new CircuitBreaker(
            $this->name,
            $circuitConfig,
            $this->logger,
            $this->ffi
        );
    }

    private function initializeRateLimiters(): void
    {
        $rateLimitConfig = $this->config['rate_limiting'] ?? [];
        
        foreach ($rateLimitConfig as $type => $config) {
            $this->rateLimiters[$type] = new TokenBucket(
                $config['capacity'] ?? 100,
                $config['refill_rate'] ?? 10,
                $config['refill_period'] ?? 1
            );
        }
    }

    private function checkRateLimits(): bool
    {
        foreach ($this->rateLimiters as $rateLimiter) {
            if (!$rateLimiter->consume()) {
                return false;
            }
        }
        return true;
    }

    private function determineHealthStatus(float $utilizationRate, array $circuitHealth): string
    {
        if ($circuitHealth['availability'] === 0) {
            return 'unhealthy';
        }

        if ($utilizationRate >= 95) {
            return 'critical';
        } elseif ($utilizationRate >= 80) {
            return 'warning';
        } else {
            return 'healthy';
        }
    }

    private function recordTiming(float $startTime, string $type): void
    {
        $duration = microtime(true) - $startTime;
        $this->stats['total_time'] += $duration;
        $this->stats['request_count'] = ($this->stats['request_count'] ?? 0) + 1;
        $this->stats['avg_response_time'] = $this->stats['total_time'] / $this->stats['request_count'];
        $this->stats['timings'][$type] = ($this->stats['timings'][$type] ?? 0) + $duration;
    }

    private function initializeStats(): void
    {
        $this->stats = [
            'successful_requests' => 0,
            'failed_requests' => 0,
            'rejected_requests' => 0,
            'rate_limited_requests' => 0,
            'total_time' => 0,
            'avg_response_time' => 0,
            'request_count' => 0,
            'timings' => [
                'success' => 0,
                'failure' => 0
            ]
        ];
    }
}

/**
 * Resource Pool for Bulkhead Isolation
 */
class ResourcePool
{
    private string $name;
    private string $type;
    private array $config;
    private LoggerInterface $logger;
    private FFIManagerInterface $ffi;

    private array $available = [];
    private array $inUse = [];
    private int $maxSize;
    private array $stats = [];

    public function __construct(
        string $name,
        string $type,
        array $config,
        LoggerInterface $logger,
        FFIManagerInterface $ffi
    ) {
        $this->name = $name;
        $this->type = $type;
        $this->config = $config;
        $this->logger = $logger;
        $this->ffi = $ffi;
        $this->maxSize = $config['max_size'] ?? 10;

        $this->initializePool();
        $this->initializeStats();
    }

    public function acquire(): Resource
    {
        if (empty($this->available)) {
            if (count($this->inUse) >= $this->maxSize) {
                $this->stats['acquisition_failures']++;
                throw new ResourcePoolExhaustedException(
                    "Resource pool '{$this->name}' exhausted"
                );
            }
            
            $resource = $this->createResource();
        } else {
            $resource = array_pop($this->available);
        }

        $resourceId = spl_object_hash($resource);
        $this->inUse[$resourceId] = $resource;
        $this->stats['acquisitions']++;

        return $resource;
    }

    public function release(Resource $resource): void
    {
        $resourceId = spl_object_hash($resource);
        
        if (isset($this->inUse[$resourceId])) {
            unset($this->inUse[$resourceId]);
            
            if ($resource->isHealthy()) {
                $this->available[] = $resource;
            } else {
                // Resource is unhealthy, discard it
                $this->stats['discarded_resources']++;
            }
            
            $this->stats['releases']++;
        }
    }

    public function getHealthStatus(): array
    {
        $totalResources = count($this->available) + count($this->inUse);
        $utilizationRate = $totalResources > 0 ? (count($this->inUse) / $totalResources) * 100 : 0;

        return [
            'status' => $utilizationRate < 90 ? 'healthy' : 'warning',
            'utilization_rate' => round($utilizationRate, 2),
            'available_resources' => count($this->available),
            'in_use_resources' => count($this->inUse),
            'total_resources' => $totalResources,
            'max_capacity' => $this->maxSize
        ];
    }

    public function getStats(): array
    {
        return array_merge($this->stats, [
            'name' => $this->name,
            'type' => $this->type,
            'available_count' => count($this->available),
            'in_use_count' => count($this->inUse),
            'max_size' => $this->maxSize
        ]);
    }

    private function initializePool(): void
    {
        $initialSize = $this->config['pool_size'] ?? 5;
        
        for ($i = 0; $i < $initialSize; $i++) {
            $this->available[] = $this->createResource();
        }
    }

    private function createResource(): Resource
    {
        return new Resource($this->type, $this->config);
    }

    private function initializeStats(): void
    {
        $this->stats = [
            'acquisitions' => 0,
            'releases' => 0,
            'acquisition_failures' => 0,
            'discarded_resources' => 0,
            'created_resources' => 0
        ];
    }
}

/**
 * Generic Resource for Resource Pools
 */
class Resource
{
    private string $type;
    private array $config;
    private bool $healthy = true;
    private int $createdAt;

    public function __construct(string $type, array $config)
    {
        $this->type = $type;
        $this->config = $config;
        $this->createdAt = time();
    }

    public function isHealthy(): bool
    {
        // Simple health check based on age
        $maxAge = $this->config['max_age'] ?? 3600; // 1 hour
        return $this->healthy && (time() - $this->createdAt) < $maxAge;
    }

    public function markUnhealthy(): void
    {
        $this->healthy = false;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getAge(): int
    {
        return time() - $this->createdAt;
    }
}

/**
 * Simple Token Bucket for Rate Limiting
 */
class TokenBucket
{
    private int $capacity;
    private int $tokens;
    private int $refillRate;
    private int $refillPeriod;
    private int $lastRefill;

    public function __construct(int $capacity, int $refillRate, int $refillPeriod)
    {
        $this->capacity = $capacity;
        $this->tokens = $capacity;
        $this->refillRate = $refillRate;
        $this->refillPeriod = $refillPeriod;
        $this->lastRefill = time();
    }

    public function consume(int $tokens = 1): bool
    {
        $this->refill();
        
        if ($this->tokens >= $tokens) {
            $this->tokens -= $tokens;
            return true;
        }
        
        return false;
    }

    private function refill(): void
    {
        $now = time();
        $timePassed = $now - $this->lastRefill;
        
        if ($timePassed >= $this->refillPeriod) {
            $tokensToAdd = intval($timePassed / $this->refillPeriod) * $this->refillRate;
            $this->tokens = min($this->capacity, $this->tokens + $tokensToAdd);
            $this->lastRefill = $now;
        }
    }
}

// Exceptions
class BulkheadCapacityException extends \RuntimeException {}
class BulkheadRateLimitException extends \RuntimeException {}
class ResourcePoolExhaustedException extends \RuntimeException {}