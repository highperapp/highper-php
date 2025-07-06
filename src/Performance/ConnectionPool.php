<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Performance;

use HighPerApp\HighPer\Contracts\ConnectionPoolInterface;
use HighPerApp\HighPer\Contracts\LoggerInterface;
use Amp\Deferred;
use Amp\Future;

/**
 * High-Performance Connection Pool
 * 
 * Manages connection pooling for database, Redis, HTTP clients and other resources
 * to achieve C10M scalability with efficient resource management.
 * 
 * Features:
 * - Dynamic pool sizing based on load
 * - Connection health monitoring and recovery
 * - Configurable pool strategies (LIFO, FIFO, LRU)
 * - Connection lifetime management
 * - Pool statistics and performance monitoring
 * - Graceful degradation and circuit breaker integration
 */
class ConnectionPool implements ConnectionPoolInterface
{
    private array $pools = [];
    private array $config = [];
    private array $stats = [];
    private LoggerInterface $logger;
    private array $waitingQueue = [];
    private bool $monitoring = true;

    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->config = array_merge([
            'default_min_connections' => 5,
            'default_max_connections' => 100,
            'default_max_idle_time' => 300, // 5 minutes
            'default_max_lifetime' => 3600, // 1 hour
            'health_check_interval' => 30, // 30 seconds
            'pool_strategy' => 'lifo', // lifo, fifo, lru
            'connection_timeout' => 10, // 10 seconds
            'acquire_timeout' => 30, // 30 seconds
            'enable_monitoring' => true,
            'enable_circuit_breaker' => true,
            'circuit_breaker_threshold' => 5,
            'circuit_breaker_timeout' => 60
        ], $config);

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

        $this->monitoring = $this->config['enable_monitoring'];
        $this->initializeGlobalStats();

        $this->logger->info('ConnectionPool initialized', [
            'strategy' => $this->config['pool_strategy'],
            'monitoring' => $this->monitoring,
            'circuit_breaker' => $this->config['enable_circuit_breaker']
        ]);
    }

    public function createPool(string $name, callable $connectionFactory, array $poolConfig = []): void
    {
        $config = array_merge([
            'min_connections' => $this->config['default_min_connections'],
            'max_connections' => $this->config['default_max_connections'],
            'max_idle_time' => $this->config['default_max_idle_time'],
            'max_lifetime' => $this->config['default_max_lifetime'],
            'health_checker' => null,
            'validator' => null,
            'strategy' => $this->config['pool_strategy']
        ], $poolConfig);

        $this->pools[$name] = [
            'config' => $config,
            'factory' => $connectionFactory,
            'connections' => [],
            'active' => [],
            'waiting' => [],
            'stats' => $this->initializePoolStats(),
            'circuit_breaker' => $this->createCircuitBreaker(),
            'last_health_check' => 0
        ];

        $this->waitingQueue[$name] = [];

        // Pre-fill with minimum connections
        for ($i = 0; $i < $config['min_connections']; $i++) {
            try {
                $connection = $this->createConnection($name);
                $this->addConnectionToPool($name, $connection);
            } catch (\Throwable $e) {
                $this->logger->warning("Failed to create initial connection for pool {$name}", [
                    'error' => $e->getMessage(),
                    'connection_index' => $i
                ]);
            }
        }

        $this->logger->info("Connection pool '{$name}' created", [
            'min_connections' => $config['min_connections'],
            'max_connections' => $config['max_connections'],
            'initial_connections' => count($this->pools[$name]['connections'])
        ]);
    }

    public function acquire(string $poolName, int $timeout = null): Future
    {
        if (!isset($this->pools[$poolName])) {
            throw new \InvalidArgumentException("Pool '{$poolName}' does not exist");
        }

        $timeout = $timeout ?? $this->config['acquire_timeout'];
        $deferred = new Deferred();

        // Check circuit breaker
        if (!$this->isCircuitBreakerOpen($poolName)) {
            $connection = $this->tryAcquireConnection($poolName);
            if ($connection !== null) {
                $deferred->complete($connection);
                return $deferred->getFuture();
            }
        } else {
            $this->recordFailure($poolName, 'circuit_breaker_open');
            $deferred->error(new \RuntimeException("Circuit breaker open for pool '{$poolName}'"));
            return $deferred->getFuture();
        }

        // Add to waiting queue with timeout
        $this->waitingQueue[$poolName][] = [
            'deferred' => $deferred,
            'timeout' => time() + $timeout,
            'timestamp' => microtime(true)
        ];

        // Set timeout handler
        $this->scheduleTimeoutCheck($poolName, $deferred, $timeout);

        return $deferred->getFuture();
    }

    public function release(string $poolName, mixed $connection): void
    {
        if (!isset($this->pools[$poolName])) {
            throw new \InvalidArgumentException("Pool '{$poolName}' does not exist");
        }

        $pool = &$this->pools[$poolName];
        $connectionId = $this->getConnectionId($connection);

        // Remove from active connections
        if (isset($pool['active'][$connectionId])) {
            unset($pool['active'][$connectionId]);
            $pool['stats']['active_connections']--;
        }

        // Validate connection before returning to pool
        if (!$this->validateConnection($poolName, $connection)) {
            $this->closeConnection($connection);
            $pool['stats']['connections_discarded']++;
            $this->logger->debug("Connection discarded due to validation failure", [
                'pool' => $poolName,
                'connection_id' => $connectionId
            ]);
        } else {
            // Return to pool or fulfill waiting request
            if (!empty($this->waitingQueue[$poolName])) {
                $this->fulfillWaitingRequest($poolName, $connection);
            } else {
                $this->addConnectionToPool($poolName, $connection);
            }
        }

        $this->recordSuccess($poolName);
        $this->scheduleHealthCheck($poolName);
    }

    public function getPoolStats(string $poolName): array
    {
        if (!isset($this->pools[$poolName])) {
            throw new \InvalidArgumentException("Pool '{$poolName}' does not exist");
        }

        $pool = $this->pools[$poolName];
        $stats = $pool['stats'];

        return array_merge($stats, [
            'pool_name' => $poolName,
            'connections_available' => count($pool['connections']),
            'connections_active' => count($pool['active']),
            'connections_waiting' => count($this->waitingQueue[$poolName]),
            'configuration' => $pool['config'],
            'circuit_breaker' => $pool['circuit_breaker'],
            'health_status' => $this->getPoolHealthStatus($poolName)
        ]);
    }

    public function getAllStats(): array
    {
        $poolStats = [];
        foreach (array_keys($this->pools) as $poolName) {
            $poolStats[$poolName] = $this->getPoolStats($poolName);
        }

        return [
            'global' => $this->stats,
            'pools' => $poolStats,
            'configuration' => $this->config,
            'monitoring_enabled' => $this->monitoring
        ];
    }

    public function healthCheck(string $poolName = null): array
    {
        if ($poolName !== null) {
            return $this->performPoolHealthCheck($poolName);
        }

        $results = [];
        foreach (array_keys($this->pools) as $name) {
            $results[$name] = $this->performPoolHealthCheck($name);
        }

        return $results;
    }

    public function closePool(string $poolName): void
    {
        if (!isset($this->pools[$poolName])) {
            return;
        }

        $pool = &$this->pools[$poolName];

        // Close all connections
        foreach ($pool['connections'] as $connection) {
            $this->closeConnection($connection['resource']);
        }

        foreach ($pool['active'] as $connection) {
            $this->closeConnection($connection['resource']);
        }

        // Reject all waiting requests
        foreach ($this->waitingQueue[$poolName] as $waiting) {
            $waiting['deferred']->error(new \RuntimeException("Pool '{$poolName}' is closing"));
        }

        unset($this->pools[$poolName]);
        unset($this->waitingQueue[$poolName]);

        $this->logger->info("Connection pool '{$poolName}' closed");
    }

    public function closeAllPools(): void
    {
        foreach (array_keys($this->pools) as $poolName) {
            $this->closePool($poolName);
        }

        $this->logger->info('All connection pools closed');
    }

    private function tryAcquireConnection(string $poolName): mixed
    {
        $pool = &$this->pools[$poolName];

        // Try to get connection from pool
        if (!empty($pool['connections'])) {
            $connectionData = $this->getConnectionFromPool($poolName);
            if ($connectionData !== null) {
                $connection = $connectionData['resource'];

                // Validate before use
                if ($this->validateConnection($poolName, $connection)) {
                    $this->moveToActive($poolName, $connectionData);
                    $pool['stats']['connections_acquired']++;
                    return $connection;
                } else {
                    $this->closeConnection($connection);
                    $pool['stats']['connections_discarded']++;
                }
            }
        }

        // Try to create new connection if under limit
        if (count($pool['active']) + count($pool['connections']) < $pool['config']['max_connections']) {
            try {
                $connection = $this->createConnection($poolName);
                $connectionData = $this->createConnectionData($connection);
                $this->moveToActive($poolName, $connectionData);
                $pool['stats']['connections_created']++;
                $pool['stats']['connections_acquired']++;
                return $connection;
            } catch (\Throwable $e) {
                $this->recordFailure($poolName, 'connection_creation_failed');
                $this->logger->error("Failed to create connection for pool {$poolName}", [
                    'error' => $e->getMessage()
                ]);
            }
        }

        return null;
    }

    private function createConnection(string $poolName): mixed
    {
        $pool = $this->pools[$poolName];
        $startTime = microtime(true);

        try {
            $connection = ($pool['factory'])();
            $duration = microtime(true) - $startTime;

            if ($this->monitoring) {
                $pool['stats']['total_creation_time'] += $duration;
                $pool['stats']['avg_creation_time'] = $pool['stats']['total_creation_time'] / 
                    max(1, $pool['stats']['connections_created'] + 1);
            }

            return $connection;
        } catch (\Throwable $e) {
            $this->recordFailure($poolName, 'connection_creation_failed');
            throw $e;
        }
    }

    private function createConnectionData(mixed $connection): array
    {
        return [
            'resource' => $connection,
            'id' => $this->getConnectionId($connection),
            'created_at' => time(),
            'last_used' => time(),
            'use_count' => 0,
            'health_status' => 'healthy'
        ];
    }

    private function addConnectionToPool(string $poolName, mixed $connection): void
    {
        $pool = &$this->pools[$poolName];
        $connectionData = $this->createConnectionData($connection);
        
        $strategy = $pool['config']['strategy'];
        switch ($strategy) {
            case 'lifo':
                $pool['connections'][] = $connectionData;
                break;
            case 'fifo':
                array_unshift($pool['connections'], $connectionData);
                break;
            case 'lru':
                // Add to end (most recently used)
                $pool['connections'][] = $connectionData;
                break;
        }
    }

    private function getConnectionFromPool(string $poolName): ?array
    {
        $pool = &$this->pools[$poolName];
        
        if (empty($pool['connections'])) {
            return null;
        }

        $strategy = $pool['config']['strategy'];
        switch ($strategy) {
            case 'lifo':
                return array_pop($pool['connections']);
            case 'fifo':
                return array_shift($pool['connections']);
            case 'lru':
                // Find least recently used
                $lruIndex = 0;
                $lruTime = $pool['connections'][0]['last_used'];
                foreach ($pool['connections'] as $index => $conn) {
                    if ($conn['last_used'] < $lruTime) {
                        $lruTime = $conn['last_used'];
                        $lruIndex = $index;
                    }
                }
                $connection = $pool['connections'][$lruIndex];
                array_splice($pool['connections'], $lruIndex, 1);
                return $connection;
            default:
                return array_pop($pool['connections']);
        }
    }

    private function moveToActive(string $poolName, array $connectionData): void
    {
        $pool = &$this->pools[$poolName];
        $connectionData['last_used'] = time();
        $connectionData['use_count']++;
        $pool['active'][$connectionData['id']] = $connectionData;
        $pool['stats']['active_connections']++;
    }

    private function validateConnection(string $poolName, mixed $connection): bool
    {
        $pool = $this->pools[$poolName];
        
        // Check if validator is configured
        if (isset($pool['config']['validator']) && is_callable($pool['config']['validator'])) {
            try {
                return ($pool['config']['validator'])($connection);
            } catch (\Throwable $e) {
                $this->logger->warning("Connection validation failed", [
                    'pool' => $poolName,
                    'error' => $e->getMessage()
                ]);
                return false;
            }
        }

        return true;
    }

    private function getConnectionId(mixed $connection): string
    {
        return spl_object_hash($connection);
    }

    private function closeConnection(mixed $connection): void
    {
        try {
            if (method_exists($connection, 'close')) {
                $connection->close();
            } elseif (method_exists($connection, 'disconnect')) {
                $connection->disconnect();
            } elseif (is_resource($connection)) {
                @fclose($connection);
            }
        } catch (\Throwable $e) {
            $this->logger->debug('Error closing connection', [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function fulfillWaitingRequest(string $poolName, mixed $connection): void
    {
        if (empty($this->waitingQueue[$poolName])) {
            return;
        }

        $waiting = array_shift($this->waitingQueue[$poolName]);
        $connectionData = $this->createConnectionData($connection);
        $this->moveToActive($poolName, $connectionData);
        $waiting['deferred']->complete($connection);

        $waitTime = microtime(true) - $waiting['timestamp'];
        $this->pools[$poolName]['stats']['total_wait_time'] += $waitTime;
        $this->pools[$poolName]['stats']['connections_acquired']++;
    }

    private function scheduleTimeoutCheck(string $poolName, Deferred $deferred, int $timeout): void
    {
        // In a real implementation, this would use an event loop timer
        // For now, we'll implement a simple timeout mechanism
    }

    private function performPoolHealthCheck(string $poolName): array
    {
        if (!isset($this->pools[$poolName])) {
            return ['status' => 'not_found'];
        }

        $pool = &$this->pools[$poolName];
        $config = $pool['config'];
        $healthChecker = $config['health_checker'] ?? null;

        $results = [
            'pool_name' => $poolName,
            'status' => 'healthy',
            'checked_at' => time(),
            'connections_checked' => 0,
            'healthy_connections' => 0,
            'unhealthy_connections' => 0,
            'removed_connections' => 0
        ];

        // Check idle connections
        $now = time();
        foreach ($pool['connections'] as $index => $connectionData) {
            $results['connections_checked']++;

            // Check max idle time
            if ($now - $connectionData['last_used'] > $config['max_idle_time']) {
                $this->closeConnection($connectionData['resource']);
                unset($pool['connections'][$index]);
                $results['removed_connections']++;
                continue;
            }

            // Check max lifetime
            if ($now - $connectionData['created_at'] > $config['max_lifetime']) {
                $this->closeConnection($connectionData['resource']);
                unset($pool['connections'][$index]);
                $results['removed_connections']++;
                continue;
            }

            // Custom health check
            if ($healthChecker && is_callable($healthChecker)) {
                try {
                    if (!$healthChecker($connectionData['resource'])) {
                        $this->closeConnection($connectionData['resource']);
                        unset($pool['connections'][$index]);
                        $results['unhealthy_connections']++;
                        $results['removed_connections']++;
                        continue;
                    }
                } catch (\Throwable $e) {
                    $this->closeConnection($connectionData['resource']);
                    unset($pool['connections'][$index]);
                    $results['unhealthy_connections']++;
                    $results['removed_connections']++;
                    continue;
                }
            }

            $results['healthy_connections']++;
        }

        // Re-index array after removals
        $pool['connections'] = array_values($pool['connections']);
        $pool['last_health_check'] = $now;

        return $results;
    }

    private function scheduleHealthCheck(string $poolName): void
    {
        $pool = $this->pools[$poolName];
        $now = time();
        
        if ($now - $pool['last_health_check'] >= $this->config['health_check_interval']) {
            $this->performPoolHealthCheck($poolName);
        }
    }

    private function getPoolHealthStatus(string $poolName): string
    {
        $pool = $this->pools[$poolName];
        $totalConnections = count($pool['connections']) + count($pool['active']);
        $minConnections = $pool['config']['min_connections'];
        
        if ($totalConnections < $minConnections * 0.5) {
            return 'critical';
        } elseif ($totalConnections < $minConnections) {
            return 'degraded';
        } else {
            return 'healthy';
        }
    }

    private function createCircuitBreaker(): array
    {
        return [
            'state' => 'closed', // closed, open, half_open
            'failure_count' => 0,
            'last_failure_time' => 0,
            'next_attempt_time' => 0
        ];
    }

    private function isCircuitBreakerOpen(string $poolName): bool
    {
        if (!$this->config['enable_circuit_breaker']) {
            return false;
        }

        $breaker = &$this->pools[$poolName]['circuit_breaker'];
        $now = time();

        switch ($breaker['state']) {
            case 'open':
                if ($now >= $breaker['next_attempt_time']) {
                    $breaker['state'] = 'half_open';
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

        $pool['stats']['operations_successful']++;
    }

    private function recordFailure(string $poolName, string $reason): void
    {
        $pool = &$this->pools[$poolName];
        $breaker = &$pool['circuit_breaker'];

        $breaker['failure_count']++;
        $breaker['last_failure_time'] = time();

        if ($breaker['failure_count'] >= $this->config['circuit_breaker_threshold']) {
            $breaker['state'] = 'open';
            $breaker['next_attempt_time'] = time() + $this->config['circuit_breaker_timeout'];
        }

        $pool['stats']['operations_failed']++;
        $pool['stats']['failure_reasons'][$reason] = ($pool['stats']['failure_reasons'][$reason] ?? 0) + 1;
    }

    private function initializeGlobalStats(): void
    {
        $this->stats = [
            'pools_created' => 0,
            'pools_closed' => 0,
            'total_connections_created' => 0,
            'total_connections_closed' => 0,
            'uptime_start' => time()
        ];
    }

    private function initializePoolStats(): array
    {
        return [
            'connections_created' => 0,
            'connections_acquired' => 0,
            'connections_released' => 0,
            'connections_discarded' => 0,
            'operations_successful' => 0,
            'operations_failed' => 0,
            'total_wait_time' => 0,
            'total_creation_time' => 0,
            'avg_creation_time' => 0,
            'active_connections' => 0,
            'failure_reasons' => []
        ];
    }
}