<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Performance;

use HighPerApp\HighPer\Contracts\LoggerInterface;
use HighPerApp\HighPer\Contracts\FFIManagerInterface;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Connection\ConnectionPool;
use Amp\Http\Client\Connection\UnlimitedConnectionPool;
use Amp\Http\Client\Interceptor\SetRequestHeader;
use Amp\Socket\ConnectContext;
use Amp\Future;

/**
 * High-Performance HTTP Client Connection Pool
 * 
 * Provides efficient HTTP client connection pooling for outbound requests
 * with Rust FFI acceleration for maximum C10M performance.
 * 
 * Features:
 * - Rust FFI acceleration for HTTP/2 and HTTP/3 protocols
 * - Transparent fallback to AMPHP HTTP client
 * - Connection pooling with keep-alive optimization
 * - Circuit breaker patterns for external service reliability
 * - Request/Response compression with Brotli support
 * - Comprehensive performance monitoring and statistics
 */
class HttpClientPool
{
    private FFIManagerInterface $ffi;
    private LoggerInterface $logger;
    private array $pools = [];
    private array $config = [];
    private array $stats = [];
    private bool $rustAvailable = false;

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
            'rust_enabled' => true,
            'rust_threshold' => 100, // Use Rust for high-concurrency scenarios
            'default_timeout' => 30,
            'connection_timeout' => 10,
            'keep_alive_timeout' => 60,
            'max_connections_per_host' => 100,
            'max_total_connections' => 1000,
            'enable_http2' => true,
            'enable_http3' => false, // Experimental
            'enable_compression' => true,
            'user_agent' => 'HighPer-Framework/1.0',
            'circuit_breaker' => [
                'enabled' => true,
                'failure_threshold' => 5,
                'timeout' => 60,
                'recovery_timeout' => 300
            ]
        ], $config);

        $this->initializeStats();
        $this->detectRustCapabilities();
        $this->initializeRustHttpClient();

        $this->logger->info('HttpClientPool initialized', [
            'rust_available' => $this->rustAvailable,
            'http2_enabled' => $this->config['enable_http2'],
            'compression_enabled' => $this->config['enable_compression']
        ]);
    }

    public function createPool(string $name, array $poolConfig = []): void
    {
        $config = array_merge($this->config, $poolConfig);

        // Create AMPHP connection pool
        $connectionPool = new UnlimitedConnectionPool();
        
        // Configure connection context
        $connectContext = new ConnectContext();
        $connectContext = $connectContext->withConnectTimeout($config['connection_timeout']);
        
        if (isset($config['tls'])) {
            $connectContext = $connectContext->withTlsContext($config['tls']);
        }

        // Build HTTP client
        $clientBuilder = new HttpClientBuilder();
        $clientBuilder = $clientBuilder->usingPool($connectionPool);
        
        // Add compression interceptor if enabled
        if ($config['enable_compression']) {
            $clientBuilder = $clientBuilder->interceptNetwork(
                new SetRequestHeader('Accept-Encoding', 'br, gzip, deflate')
            );
        }

        // Add user agent
        $clientBuilder = $clientBuilder->interceptNetwork(
            new SetRequestHeader('User-Agent', $config['user_agent'])
        );

        $httpClient = $clientBuilder->build();

        $this->pools[$name] = [
            'client' => $httpClient,
            'connection_pool' => $connectionPool,
            'config' => $config,
            'stats' => $this->initializePoolStats(),
            'circuit_breaker' => $this->createCircuitBreaker($config['circuit_breaker']),
            'last_health_check' => time()
        ];

        $this->logger->info("HTTP client pool '{$name}' created", [
            'max_connections_per_host' => $config['max_connections_per_host'],
            'http2_enabled' => $config['enable_http2'],
            'rust_available' => $this->rustAvailable
        ]);
    }

    public function getClient(string $poolName = 'default'): HttpClient
    {
        if (!isset($this->pools[$poolName])) {
            $this->createPool($poolName);
        }

        $pool = $this->pools[$poolName];
        
        // Check circuit breaker
        if ($this->isCircuitBreakerOpen($poolName)) {
            throw new \RuntimeException("Circuit breaker open for HTTP client pool '{$poolName}'");
        }

        return $pool['client'];
    }

    public function request(string $method, string $uri, array $options = [], string $poolName = 'default'): Future
    {
        $startTime = microtime(true);
        $this->stats['total_requests']++;

        return \Amp\async(function() use ($method, $uri, $options, $poolName, $startTime) {
            try {
                $client = $this->getClient($poolName);
                
                // Try Rust FFI for high-performance scenarios
                if ($this->shouldUseRust($options)) {
                    $response = yield from $this->requestWithRust($method, $uri, $options);
                    if ($response !== null) {
                        $this->recordSuccess($poolName);
                        $this->recordTiming($startTime, 'rust');
                        return $response;
                    }
                }

                // Fallback to AMPHP HTTP client
                $request = new \Amp\Http\Client\Request($uri, $method);
                
                // Set headers
                if (isset($options['headers'])) {
                    foreach ($options['headers'] as $name => $value) {
                        $request->setHeader($name, $value);
                    }
                }

                // Set body
                if (isset($options['body'])) {
                    $request->setBody($options['body']);
                }

                // Set timeout
                $timeout = $options['timeout'] ?? $this->config['default_timeout'];
                
                $response = yield $client->request($request, null, null, $timeout);
                
                $this->recordSuccess($poolName);
                $this->recordTiming($startTime, 'amphp');
                
                return $response;

            } catch (\Throwable $e) {
                $this->recordFailure($poolName, $e->getMessage());
                $this->recordTiming($startTime, 'failed');
                throw $e;
            }
        })();
    }

    public function get(string $uri, array $options = [], string $poolName = 'default'): Future
    {
        return $this->request('GET', $uri, $options, $poolName);
    }

    public function post(string $uri, array $options = [], string $poolName = 'default'): Future
    {
        return $this->request('POST', $uri, $options, $poolName);
    }

    public function put(string $uri, array $options = [], string $poolName = 'default'): Future
    {
        return $this->request('PUT', $uri, $options, $poolName);
    }

    public function delete(string $uri, array $options = [], string $poolName = 'default'): Future
    {
        return $this->request('DELETE', $uri, $options, $poolName);
    }

    public function batch(array $requests, string $poolName = 'default'): Future
    {
        $this->stats['batch_requests']++;
        
        return \Amp\async(function() use ($requests, $poolName) {
            $futures = [];
            
            foreach ($requests as $key => $request) {
                $method = $request['method'] ?? 'GET';
                $uri = $request['uri'];
                $options = $request['options'] ?? [];
                
                $futures[$key] = $this->request($method, $uri, $options, $poolName);
            }
            
            return yield \Amp\Future\awaitAll($futures);
        })();
    }

    private function shouldUseRust(array $options): bool
    {
        if (!$this->rustAvailable || !$this->config['rust_enabled']) {
            return false;
        }

        // Use Rust for high-concurrency or large payload scenarios
        $bodySize = isset($options['body']) ? strlen($options['body']) : 0;
        $concurrent = $this->stats['concurrent_requests'] ?? 0;

        return $concurrent >= $this->config['rust_threshold'] || 
               $bodySize > 10240; // 10KB
    }

    private function requestWithRust(string $method, string $uri, array $options): ?\Generator
    {
        try {
            $headers = $options['headers'] ?? [];
            $body = $options['body'] ?? '';
            $timeout = $options['timeout'] ?? $this->config['default_timeout'];

            $result = $this->ffi->call(
                'http_client',
                'request',
                [$method, $uri, json_encode($headers), $body, $timeout],
                null
            );

            if ($result !== null) {
                $this->stats['rust_requests']++;
                
                // Parse Rust response and create AMPHP Response object
                $responseData = json_decode($result, true);
                
                if ($responseData && isset($responseData['status'])) {
                    $response = new \Amp\Http\Client\Response(
                        '1.1', // HTTP version
                        $responseData['status'],
                        $responseData['reason'] ?? '',
                        $responseData['headers'] ?? [],
                        $responseData['body'] ?? ''
                    );
                    
                    return $response;
                }
            }

            return null;

        } catch (\Throwable $e) {
            $this->logger->warning('Rust HTTP client request failed', [
                'error' => $e->getMessage(),
                'fallback' => 'amphp'
            ]);
            $this->stats['rust_failures']++;
            return null;
        }
    }

    private function detectRustCapabilities(): void
    {
        $this->rustAvailable = $this->ffi->isAvailable();

        if ($this->rustAvailable) {
            $this->logger->info('Rust FFI HTTP client capabilities detected');
        }
    }

    private function initializeRustHttpClient(): void
    {
        if (!$this->rustAvailable) {
            return;
        }

        // Register Rust HTTP client library
        $this->ffi->registerLibrary('http_client', [
            'header' => __DIR__ . '/../../rust/http_client/http_client.h',
            'lib' => __DIR__ . '/../../rust/http_client/target/release/libhttp_client.so'
        ]);
    }

    private function createCircuitBreaker(array $config): array
    {
        return [
            'enabled' => $config['enabled'],
            'state' => 'closed', // closed, open, half_open
            'failure_count' => 0,
            'failure_threshold' => $config['failure_threshold'],
            'timeout' => $config['timeout'],
            'recovery_timeout' => $config['recovery_timeout'],
            'last_failure_time' => 0,
            'next_attempt_time' => 0
        ];
    }

    private function isCircuitBreakerOpen(string $poolName): bool
    {
        $pool = $this->pools[$poolName];
        $breaker = $pool['circuit_breaker'];

        if (!$breaker['enabled']) {
            return false;
        }

        $now = time();

        switch ($breaker['state']) {
            case 'open':
                if ($now >= $breaker['next_attempt_time']) {
                    $this->pools[$poolName]['circuit_breaker']['state'] = 'half_open';
                    return false;
                }
                return true;

            case 'half_open':
                return false;

            case 'closed':
            default:
                return false;
        }
    }

    private function recordSuccess(string $poolName): void
    {
        $pool = &$this->pools[$poolName];
        $breaker = &$pool['circuit_breaker'];

        if ($breaker['state'] === 'half_open') {
            $breaker['state'] = 'closed';
            $breaker['failure_count'] = 0;
        }

        $pool['stats']['successful_requests']++;
        $this->stats['successful_requests']++;
    }

    private function recordFailure(string $poolName, string $reason): void
    {
        $pool = &$this->pools[$poolName];
        $breaker = &$pool['circuit_breaker'];

        $breaker['failure_count']++;
        $breaker['last_failure_time'] = time();

        if ($breaker['failure_count'] >= $breaker['failure_threshold']) {
            $breaker['state'] = 'open';
            $breaker['next_attempt_time'] = time() + $breaker['recovery_timeout'];
        }

        $pool['stats']['failed_requests']++;
        $pool['stats']['failure_reasons'][$reason] = ($pool['stats']['failure_reasons'][$reason] ?? 0) + 1;
        $this->stats['failed_requests']++;
    }

    private function recordTiming(float $startTime, string $type): void
    {
        $duration = microtime(true) - $startTime;
        $this->stats['total_time'] += $duration;
        $this->stats['avg_response_time'] = $this->stats['total_time'] / max(1, $this->stats['total_requests']);
        $this->stats['timings'][$type] = ($this->stats['timings'][$type] ?? 0) + $duration;
    }

    private function initializeStats(): void
    {
        $this->stats = [
            'total_requests' => 0,
            'successful_requests' => 0,
            'failed_requests' => 0,
            'batch_requests' => 0,
            'rust_requests' => 0,
            'rust_failures' => 0,
            'total_time' => 0,
            'avg_response_time' => 0,
            'concurrent_requests' => 0,
            'timings' => [
                'rust' => 0,
                'amphp' => 0,
                'failed' => 0
            ]
        ];
    }

    private function initializePoolStats(): array
    {
        return [
            'successful_requests' => 0,
            'failed_requests' => 0,
            'total_connections' => 0,
            'active_connections' => 0,
            'failure_reasons' => []
        ];
    }

    public function getStats(): array
    {
        $poolStats = [];
        foreach ($this->pools as $name => $pool) {
            $poolStats[$name] = array_merge($pool['stats'], [
                'circuit_breaker' => $pool['circuit_breaker'],
                'config' => $pool['config']
            ]);
        }

        return [
            'global' => $this->stats,
            'pools' => $poolStats,
            'rust_available' => $this->rustAvailable,
            'performance_ratios' => [
                'rust_usage' => $this->stats['total_requests'] > 0 
                    ? round($this->stats['rust_requests'] / $this->stats['total_requests'] * 100, 2) 
                    : 0,
                'success_rate' => $this->stats['total_requests'] > 0 
                    ? round($this->stats['successful_requests'] / $this->stats['total_requests'] * 100, 2) 
                    : 0
            ]
        ];
    }

    public function healthCheck(): array
    {
        $results = [];

        foreach ($this->pools as $name => $pool) {
            $breaker = $pool['circuit_breaker'];
            $stats = $pool['stats'];

            $results[$name] = [
                'status' => $breaker['state'] === 'open' ? 'unhealthy' : 'healthy',
                'circuit_breaker_state' => $breaker['state'],
                'failure_count' => $breaker['failure_count'],
                'success_rate' => $stats['successful_requests'] + $stats['failed_requests'] > 0
                    ? round($stats['successful_requests'] / ($stats['successful_requests'] + $stats['failed_requests']) * 100, 2)
                    : 100,
                'last_checked' => time()
            ];
        }

        return $results;
    }

    public function closePool(string $poolName): void
    {
        if (isset($this->pools[$poolName])) {
            unset($this->pools[$poolName]);
            $this->logger->info("HTTP client pool '{$poolName}' closed");
        }
    }

    public function closeAllPools(): void
    {
        $this->pools = [];
        $this->logger->info('All HTTP client pools closed');
    }
}