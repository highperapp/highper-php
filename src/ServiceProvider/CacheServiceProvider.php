<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\ServiceProvider;

use HighPerApp\HighPer\Contracts\ServiceProviderInterface;
use HighPerApp\HighPer\Contracts\ContainerInterface;
use HighPerApp\HighPer\Contracts\LoggerInterface;

/**
 * Cache Service Provider for HighPer Framework
 * 
 * Integrates the standalone highperapp/cache library into HighPer Framework
 * providing high-performance caching with Redis, Memcached, and in-memory support.
 * 
 * Features from Cache Library:
 * - PSR-16 compliant caching interface
 * - Multiple driver support (Redis, Memcached, Memory)
 * - AmPHP parallel processing integration
 * - Remember pattern for cache-aside operations
 * - Connection pooling ready architecture
 */
class CacheServiceProvider implements ServiceProviderInterface
{
    private array $config = [];
    private bool $cacheAvailable = false;
    private array $registeredServices = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'auto_detect' => true,
            'default_driver' => 'redis',
            'enable_pooling' => true,
            'enable_parallel' => true,
            'lazy_loading' => true,
            'serialization' => 'json',
            'compression' => false
        ], $config);

        $this->detectCacheAvailability();
    }

    private ?ContainerInterface $container = null;

    public function setContainer(ContainerInterface $container): void
    {
        $this->container = $container;
    }

    public function register(): void
    {
        if ($this->container === null) {
            throw new \RuntimeException('Container must be set before calling register()');
        }

        if (!$this->cacheAvailable) {
            if ($this->config['auto_detect']) {
                $this->registerNullCacheServices($this->container);
            }
            return;
        }

        $this->registerCacheDrivers($this->container);
        $this->registerCacheManager($this->container);
        $this->registerCacheStores($this->container);
        $this->registerParallelProcessing($this->container);
    }

    public function boot(): void
    {
        if ($this->container === null) {
            throw new \RuntimeException('Container must be set before calling boot()');
        }

        if (!$this->cacheAvailable) {
            $logger = $this->getLogger($this->container);
            $logger?->info('HighPer Cache services not available', [
                'reason' => 'highperapp/cache package not installed',
                'suggestion' => 'Run: composer require highperapp/cache'
            ]);
            return;
        }

        $this->initializeCacheConnections($this->container);
        $this->registerCacheMiddleware($this->container);
        $this->startCacheMonitoring($this->container);
    }

    private function detectCacheAvailability(): void
    {
        // Check if highperapp/cache is installed
        $this->cacheAvailable = class_exists('\\HighPerApp\\HighPer\\Cache\\CacheManager');
        
        if ($this->cacheAvailable) {
            $this->registeredServices[] = 'cache_core';
        }
    }

    private function registerCacheDrivers(ContainerInterface $container): void
    {
        // Redis Driver
        $container->singleton('cache.driver.redis', function() {
            $config = $this->getRedisConfiguration();
            return new \HighPerApp\HighPer\Cache\Drivers\RedisDriver($config);
        });

        // Memcached Driver
        $container->singleton('cache.driver.memcached', function() {
            $config = $this->getMemcachedConfiguration();
            return new \HighPerApp\HighPer\Cache\Drivers\MemcachedDriver($config);
        });

        // Memory Driver (in-process)
        $container->singleton('cache.driver.memory', function() {
            $config = $this->getMemoryConfiguration();
            return new \HighPerApp\HighPer\Cache\Drivers\MemoryDriver($config);
        });

        // File Driver (fallback)
        $container->singleton('cache.driver.file', function() {
            $config = $this->getFileConfiguration();
            return new \HighPerApp\HighPer\Cache\Drivers\FileDriver($config);
        });

        $this->registeredServices[] = 'cache_drivers';
    }

    private function registerCacheManager(ContainerInterface $container): void
    {
        // Cache Manager (unified interface)
        $container->singleton('cache.manager', function() use ($container) {
            $drivers = [
                'redis' => $container->get('cache.driver.redis'),
                'memcached' => $container->get('cache.driver.memcached'),
                'memory' => $container->get('cache.driver.memory'),
                'file' => $container->get('cache.driver.file')
            ];
            
            $config = $this->getCacheManagerConfiguration();
            $logger = $this->getLogger($container);
            
            return new \HighPerApp\HighPer\Cache\CacheManager($drivers, $config, $logger);
        });

        // Default Cache Store (PSR-16 compliant)
        $container->singleton('cache.store', function() use ($container) {
            $manager = $container->get('cache.manager');
            $defaultDriver = $this->config['default_driver'];
            
            return $manager->store($defaultDriver);
        });

        // Alias for PSR-16 interface
        $container->alias('cache.store', \Psr\SimpleCache\CacheInterface::class);

        $this->registeredServices[] = 'cache_manager';
    }

    private function registerCacheStores(ContainerInterface $container): void
    {
        // Session Cache Store
        $container->singleton('cache.session', function() use ($container) {
            $manager = $container->get('cache.manager');
            $config = $this->getSessionCacheConfiguration();
            
            return $manager->store($config['driver'], $config);
        });

        // Query Cache Store
        $container->singleton('cache.query', function() use ($container) {
            $manager = $container->get('cache.manager');
            $config = $this->getQueryCacheConfiguration();
            
            return $manager->store($config['driver'], $config);
        });

        // Rate Limiting Cache Store
        $container->singleton('cache.rate_limit', function() use ($container) {
            $manager = $container->get('cache.manager');
            $config = $this->getRateLimitCacheConfiguration();
            
            return $manager->store($config['driver'], $config);
        });

        // Template Cache Store
        $container->singleton('cache.template', function() use ($container) {
            $manager = $container->get('cache.manager');
            $config = $this->getTemplateCacheConfiguration();
            
            return $manager->store($config['driver'], $config);
        });

        $this->registeredServices[] = 'cache_stores';
    }

    private function registerParallelProcessing(ContainerInterface $container): void
    {
        if (!$this->config['enable_parallel']) {
            return;
        }

        // Parallel Cache Processor
        $container->singleton('cache.parallel_processor', function() use ($container) {
            $manager = $container->get('cache.manager');
            $config = $this->getParallelProcessingConfiguration();
            
            return new \HighPerApp\HighPer\Cache\Parallel\ParallelProcessor($manager, $config);
        });

        // Batch Cache Operations
        $container->singleton('cache.batch_processor', function() use ($container) {
            $parallelProcessor = $container->get('cache.parallel_processor');
            $config = $this->getBatchProcessingConfiguration();
            
            return new \HighPerApp\HighPer\Cache\Batch\BatchProcessor($parallelProcessor, $config);
        });

        $this->registeredServices[] = 'parallel_processing';
    }

    private function registerNullCacheServices(ContainerInterface $container): void
    {
        $nullCache = new class implements \Psr\SimpleCache\CacheInterface {
            public function get(string $key, mixed $default = null): mixed
            {
                return $default;
            }

            public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
            {
                return false; // Cache not available
            }

            public function delete(string $key): bool
            {
                return false;
            }

            public function clear(): bool
            {
                return false;
            }

            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                return [];
            }

            public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
            {
                return false;
            }

            public function deleteMultiple(iterable $keys): bool
            {
                return false;
            }

            public function has(string $key): bool
            {
                return false;
            }
        };

        $container->singleton('cache.store', fn() => $nullCache);
        $container->singleton('cache.manager', fn() => $nullCache);
        $container->alias('cache.store', \Psr\SimpleCache\CacheInterface::class);

        $this->registeredServices[] = 'null_cache_services';
    }

    private function initializeCacheConnections(ContainerInterface $container): void
    {
        if (!$this->config['enable_pooling']) {
            return;
        }

        try {
            // Initialize Redis connections if configured
            if ($this->isRedisConfigured()) {
                $redisDriver = $container->get('cache.driver.redis');
                $redisDriver->connect();
            }

            // Initialize Memcached connections if configured
            if ($this->isMemcachedConfigured()) {
                $memcachedDriver = $container->get('cache.driver.memcached');
                $memcachedDriver->connect();
            }

            $logger = $this->getLogger($container);
            $logger?->info('Cache connections initialized successfully');

        } catch (\Throwable $e) {
            $logger = $this->getLogger($container);
            $logger?->error('Failed to initialize cache connections', [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function registerCacheMiddleware(ContainerInterface $container): void
    {
        if (!$container->has('http.server')) {
            return;
        }

        // Cache Response Middleware
        $container->singleton('middleware.cache_response', function() use ($container) {
            return new class($container) {
                private ContainerInterface $container;

                public function __construct(ContainerInterface $container)
                {
                    $this->container = $container;
                }

                public function __invoke($request, $handler)
                {
                    $cache = $this->container->get('cache.store');
                    $cacheKey = $this->generateCacheKey($request);
                    
                    // Try to get cached response
                    $cachedResponse = $cache->get($cacheKey);
                    if ($cachedResponse !== null) {
                        return $cachedResponse;
                    }

                    // Generate response and cache it
                    $response = $handler($request);
                    if ($this->shouldCache($request, $response)) {
                        $cache->set($cacheKey, $response, 300); // 5 minutes default
                    }

                    return $response;
                }

                private function generateCacheKey($request): string
                {
                    $uri = $request->getUri();
                    $method = $request->getMethod();
                    return "response:" . hash('sha256', $method . ':' . $uri);
                }

                private function shouldCache($request, $response): bool
                {
                    return $request->getMethod() === 'GET' && 
                           $response->getStatus() === 200;
                }
            };
        });

        // Rate Limiting Middleware
        $container->singleton('middleware.rate_limit', function() use ($container) {
            return new class($container) {
                private ContainerInterface $container;

                public function __construct(ContainerInterface $container)
                {
                    $this->container = $container;
                }

                public function __invoke($request, $handler)
                {
                    $rateCache = $this->container->get('cache.rate_limit');
                    $key = $this->getRateLimitKey($request);
                    
                    $attempts = (int) $rateCache->get($key, 0);
                    $maxAttempts = 100; // per minute
                    
                    if ($attempts >= $maxAttempts) {
                        return new \Amp\Http\Server\Response(429, [], 'Rate limit exceeded');
                    }

                    $rateCache->set($key, $attempts + 1, 60); // 1 minute window
                    return $handler($request);
                }

                private function getRateLimitKey($request): string
                {
                    $ip = $request->getClient()->getRemoteAddress()->getHost();
                    return "rate_limit:" . $ip . ":" . floor(time() / 60);
                }
            };
        });

        $this->registeredServices[] = 'cache_middleware';
    }

    private function startCacheMonitoring(ContainerInterface $container): void
    {
        // In a real implementation, this would start cache monitoring
        // For now, we'll just log that monitoring is available
        $logger = $this->getLogger($container);
        $logger?->info('Cache monitoring available', [
            'drivers' => $this->getAvailableDrivers()
        ]);
    }

    private function getRedisConfiguration(): array
    {
        return [
            'host' => $_ENV['REDIS_HOST'] ?? 'localhost',
            'port' => (int) ($_ENV['REDIS_PORT'] ?? 6379),
            'password' => $_ENV['REDIS_PASSWORD'] ?? null,
            'database' => (int) ($_ENV['REDIS_DATABASE'] ?? 0),
            'prefix' => $_ENV['REDIS_PREFIX'] ?? 'highper:',
            'serialization' => $this->config['serialization'],
            'compression' => $this->config['compression'],
            'pool' => [
                'min_connections' => (int) ($_ENV['REDIS_POOL_MIN'] ?? 2),
                'max_connections' => (int) ($_ENV['REDIS_POOL_MAX'] ?? 20),
                'idle_timeout' => (int) ($_ENV['REDIS_POOL_IDLE_TIMEOUT'] ?? 300)
            ]
        ];
    }

    private function getMemcachedConfiguration(): array
    {
        return [
            'servers' => [
                [
                    'host' => $_ENV['MEMCACHED_HOST'] ?? 'localhost',
                    'port' => (int) ($_ENV['MEMCACHED_PORT'] ?? 11211),
                    'weight' => (int) ($_ENV['MEMCACHED_WEIGHT'] ?? 100)
                ]
            ],
            'prefix' => $_ENV['MEMCACHED_PREFIX'] ?? 'highper:',
            'serialization' => $this->config['serialization'],
            'compression' => $this->config['compression']
        ];
    }

    private function getMemoryConfiguration(): array
    {
        return [
            'max_size' => (int) ($_ENV['MEMORY_CACHE_MAX_SIZE'] ?? 100 * 1024 * 1024), // 100MB
            'gc_probability' => (float) ($_ENV['MEMORY_CACHE_GC_PROBABILITY'] ?? 0.1),
            'serialization' => $this->config['serialization']
        ];
    }

    private function getFileConfiguration(): array
    {
        return [
            'path' => $_ENV['FILE_CACHE_PATH'] ?? sys_get_temp_dir() . '/highper_cache',
            'prefix' => $_ENV['FILE_CACHE_PREFIX'] ?? 'highper_',
            'serialization' => $this->config['serialization'],
            'compression' => $this->config['compression']
        ];
    }

    private function getCacheManagerConfiguration(): array
    {
        return [
            'default_driver' => $this->config['default_driver'],
            'fallback_driver' => $_ENV['CACHE_FALLBACK_DRIVER'] ?? 'file',
            'enable_fallback' => (bool) ($_ENV['CACHE_ENABLE_FALLBACK'] ?? true),
            'serialization' => $this->config['serialization'],
            'compression' => $this->config['compression']
        ];
    }

    private function getSessionCacheConfiguration(): array
    {
        return [
            'driver' => $_ENV['SESSION_CACHE_DRIVER'] ?? 'redis',
            'prefix' => 'session:',
            'ttl' => (int) ($_ENV['SESSION_LIFETIME'] ?? 7200) // 2 hours
        ];
    }

    private function getQueryCacheConfiguration(): array
    {
        return [
            'driver' => $_ENV['QUERY_CACHE_DRIVER'] ?? 'redis',
            'prefix' => 'query:',
            'ttl' => (int) ($_ENV['QUERY_CACHE_TTL'] ?? 300) // 5 minutes
        ];
    }

    private function getRateLimitCacheConfiguration(): array
    {
        return [
            'driver' => $_ENV['RATE_LIMIT_CACHE_DRIVER'] ?? 'redis',
            'prefix' => 'rate_limit:',
            'ttl' => (int) ($_ENV['RATE_LIMIT_WINDOW'] ?? 60) // 1 minute
        ];
    }

    private function getTemplateCacheConfiguration(): array
    {
        return [
            'driver' => $_ENV['TEMPLATE_CACHE_DRIVER'] ?? 'file',
            'prefix' => 'template:',
            'ttl' => (int) ($_ENV['TEMPLATE_CACHE_TTL'] ?? 3600) // 1 hour
        ];
    }

    private function getParallelProcessingConfiguration(): array
    {
        return [
            'max_workers' => (int) ($_ENV['CACHE_PARALLEL_WORKERS'] ?? 4),
            'batch_size' => (int) ($_ENV['CACHE_PARALLEL_BATCH_SIZE'] ?? 100),
            'timeout' => (int) ($_ENV['CACHE_PARALLEL_TIMEOUT'] ?? 30)
        ];
    }

    private function getBatchProcessingConfiguration(): array
    {
        return [
            'max_batch_size' => (int) ($_ENV['CACHE_BATCH_MAX_SIZE'] ?? 1000),
            'flush_interval' => (int) ($_ENV['CACHE_BATCH_FLUSH_INTERVAL'] ?? 5),
            'auto_flush' => (bool) ($_ENV['CACHE_BATCH_AUTO_FLUSH'] ?? true)
        ];
    }

    private function isRedisConfigured(): bool
    {
        return !empty($_ENV['REDIS_HOST']);
    }

    private function isMemcachedConfigured(): bool
    {
        return !empty($_ENV['MEMCACHED_HOST']);
    }

    private function getAvailableDrivers(): array
    {
        $drivers = ['memory', 'file'];
        
        if ($this->isRedisConfigured()) {
            $drivers[] = 'redis';
        }
        
        if ($this->isMemcachedConfigured()) {
            $drivers[] = 'memcached';
        }
        
        return $drivers;
    }

    private function getLogger(ContainerInterface $container): ?LoggerInterface
    {
        return $container->has(LoggerInterface::class) 
            ? $container->get(LoggerInterface::class) 
            : null;
    }

    public function provides(): array
    {
        $services = [
            'cache.store',
            'cache.manager',
            \Psr\SimpleCache\CacheInterface::class
        ];

        if ($this->cacheAvailable) {
            $services = array_merge($services, [
                'cache.driver.redis',
                'cache.driver.memcached',
                'cache.driver.memory',
                'cache.driver.file',
                'cache.session',
                'cache.query',
                'cache.rate_limit',
                'cache.template',
                'middleware.cache_response',
                'middleware.rate_limit'
            ]);

            if ($this->config['enable_parallel']) {
                $services = array_merge($services, [
                    'cache.parallel_processor',
                    'cache.batch_processor'
                ]);
            }
        }

        return $services;
    }

    public function isRegistered(): bool
    {
        return !empty($this->registeredServices);
    }

    public function getRegisteredServices(): array
    {
        return $this->registeredServices;
    }

    public function isCacheAvailable(): bool
    {
        return $this->cacheAvailable;
    }

    public function getConfiguration(): array
    {
        return [
            'cache_available' => $this->cacheAvailable,
            'registered_services' => $this->registeredServices,
            'available_drivers' => $this->getAvailableDrivers(),
            'configuration' => $this->config
        ];
    }
}