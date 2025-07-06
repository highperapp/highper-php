<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Performance;

use HighPerApp\HighPer\Contracts\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Connection Pool Manager
 * 
 * Manages connection pools for different protocols to achieve C10M performance.
 * Leverages existing connection pooling from standalone libraries.
 */
class ConnectionPoolManager
{
    private array $pools = [];
    private array $config;
    private LoggerInterface $logger;
    private array $stats = [
        'pools_created' => 0,
        'connections_total' => 0,
        'connections_active' => 0,
        'connections_idle' => 0,
        'pool_hits' => 0,
        'pool_misses' => 0
    ];

    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->config = array_merge([
            'max_connections_per_pool' => 1000,
            'idle_timeout' => 300, // 5 minutes
            'connection_timeout' => 30,
            'enable_reuse' => true,
            'pool_size_increment' => 10
        ], $config);
        
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Get or create a connection pool for a specific protocol
     */
    public function getPool(string $protocol, array $poolConfig = []): ConnectionPool
    {
        if (!isset($this->pools[$protocol])) {
            $this->pools[$protocol] = $this->createPool($protocol, $poolConfig);
            $this->stats['pools_created']++;
            
            $this->logger->debug("Created connection pool for protocol: {$protocol}");
        }

        return $this->pools[$protocol];
    }

    /**
     * Get a connection from the appropriate pool
     */
    public function getConnection(string $protocol, array $connectionConfig = []): ?object
    {
        $pool = $this->getPool($protocol);
        $connection = $pool->acquire($connectionConfig);
        
        if ($connection) {
            $this->stats['pool_hits']++;
            $this->stats['connections_active']++;
        } else {
            $this->stats['pool_misses']++;
        }

        return $connection;
    }

    /**
     * Return a connection to its pool
     */
    public function releaseConnection(string $protocol, object $connection): void
    {
        if (isset($this->pools[$protocol])) {
            $this->pools[$protocol]->release($connection);
            $this->stats['connections_active']--;
            $this->stats['connections_idle']++;
            
            $this->logger->debug("Connection released to {$protocol} pool");
        }
    }

    /**
     * Get pool statistics
     */
    public function getStats(): array
    {
        $poolStats = [];
        foreach ($this->pools as $protocol => $pool) {
            $poolStats[$protocol] = $pool->getStats();
        }

        return array_merge($this->stats, [
            'total_pools' => count($this->pools),
            'pool_details' => $poolStats
        ]);
    }

    /**
     * Cleanup idle connections across all pools
     */
    public function cleanup(): void
    {
        $cleaned = 0;
        
        foreach ($this->pools as $protocol => $pool) {
            $before = $pool->getActiveCount();
            $pool->cleanup();
            $after = $pool->getActiveCount();
            $cleaned += ($before - $after);
        }

        if ($cleaned > 0) {
            $this->logger->info("Cleaned up {$cleaned} idle connections");
        }
    }

    /**
     * Shutdown all pools gracefully
     */
    public function shutdown(): void
    {
        $this->logger->info('Shutting down connection pools');
        
        foreach ($this->pools as $protocol => $pool) {
            $pool->shutdown();
            $this->logger->debug("Shutdown {$protocol} connection pool");
        }
        
        $this->pools = [];
    }

    private function createPool(string $protocol, array $poolConfig): ConnectionPool
    {
        $config = array_merge($this->config, $poolConfig);
        
        // Use protocol-specific pool implementations from standalone libraries if available
        switch ($protocol) {
            case 'database':
                return $this->createDatabasePool($config);
            case 'http':
                return $this->createHttpPool($config);
            case 'websocket':
                return $this->createWebSocketPool($config);
            default:
                return new GenericConnectionPool($protocol, $config, $this->logger);
        }
    }

    private function createDatabasePool(array $config): ConnectionPool
    {
        // Leverage highper-database connection pooling if available
        if (class_exists('\\EaseAppPHP\\HighPer\\Database\\ConnectionPool')) {
            return new DatabaseConnectionPoolAdapter($config, $this->logger);
        }
        
        return new GenericConnectionPool('database', $config, $this->logger);
    }

    private function createHttpPool(array $config): ConnectionPool
    {
        // Leverage existing HTTP connection pooling
        return new HttpConnectionPool($config, $this->logger);
    }

    private function createWebSocketPool(array $config): ConnectionPool
    {
        // Use WebSocket-specific pooling if available
        if (class_exists('\\EaseAppPHP\\HighPer\\WebSockets\\ConnectionPool')) {
            return new WebSocketConnectionPoolAdapter($config, $this->logger);
        }
        
        return new GenericConnectionPool('websocket', $config, $this->logger);
    }
}

/**
 * Generic Connection Pool Interface
 */
abstract class ConnectionPool
{
    protected array $config;
    protected LoggerInterface $logger;
    protected array $connections = [];
    protected array $activeConnections = [];
    protected array $stats = [
        'created' => 0,
        'acquired' => 0,
        'released' => 0,
        'cleaned' => 0,
        'errors' => 0
    ];

    public function __construct(array $config, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    abstract public function acquire(array $connectionConfig = []): ?object;
    abstract public function release(object $connection): void;
    abstract protected function createConnection(array $config = []): object;
    abstract protected function isConnectionValid(object $connection): bool;

    public function getStats(): array
    {
        return array_merge($this->stats, [
            'pool_size' => count($this->connections),
            'active_connections' => count($this->activeConnections),
            'idle_connections' => count($this->connections) - count($this->activeConnections)
        ]);
    }

    public function getActiveCount(): int
    {
        return count($this->activeConnections);
    }

    public function cleanup(): void
    {
        $cleaned = 0;
        $idleTimeout = $this->config['idle_timeout'] ?? 300;
        $now = time();

        foreach ($this->connections as $id => $connectionData) {
            if (!in_array($id, $this->activeConnections)) {
                $idleTime = $now - $connectionData['last_used'];
                if ($idleTime > $idleTimeout) {
                    $this->closeConnection($connectionData['connection']);
                    unset($this->connections[$id]);
                    $cleaned++;
                }
            }
        }

        $this->stats['cleaned'] += $cleaned;
    }

    public function shutdown(): void
    {
        foreach ($this->connections as $connectionData) {
            $this->closeConnection($connectionData['connection']);
        }
        
        $this->connections = [];
        $this->activeConnections = [];
    }

    protected function closeConnection(object $connection): void
    {
        // Override in specific implementations
        if (method_exists($connection, 'close')) {
            $connection->close();
        }
    }
}

/**
 * Generic Connection Pool Implementation
 */
class GenericConnectionPool extends ConnectionPool
{
    private string $protocol;

    public function __construct(string $protocol, array $config, LoggerInterface $logger)
    {
        parent::__construct($config, $logger);
        $this->protocol = $protocol;
    }

    public function acquire(array $connectionConfig = []): ?object
    {
        // Look for available connection
        foreach ($this->connections as $id => $connectionData) {
            if (!in_array($id, $this->activeConnections) && 
                $this->isConnectionValid($connectionData['connection'])) {
                
                $this->activeConnections[] = $id;
                $this->connections[$id]['last_used'] = time();
                $this->stats['acquired']++;
                
                return $connectionData['connection'];
            }
        }

        // Create new connection if pool not full
        $maxConnections = $this->config['max_connections_per_pool'] ?? 1000;
        if (count($this->connections) < $maxConnections) {
            return $this->createNewConnection($connectionConfig);
        }

        return null; // Pool exhausted
    }

    public function release(object $connection): void
    {
        $connectionId = spl_object_id($connection);
        
        $key = array_search($connectionId, $this->activeConnections);
        if ($key !== false) {
            unset($this->activeConnections[$key]);
            $this->activeConnections = array_values($this->activeConnections);
            
            if (isset($this->connections[$connectionId])) {
                $this->connections[$connectionId]['last_used'] = time();
            }
            
            $this->stats['released']++;
        }
    }

    protected function createConnection(array $config = []): object
    {
        // Generic connection object
        return new class {
            public $created_at;
            public $config;
            
            public function __construct() {
                $this->created_at = time();
            }
            
            public function close() {
                // Generic close implementation
            }
        };
    }

    protected function isConnectionValid(object $connection): bool
    {
        // Basic validation - can be overridden
        return true;
    }

    private function createNewConnection(array $connectionConfig): object
    {
        try {
            $connection = $this->createConnection($connectionConfig);
            $connectionId = spl_object_id($connection);
            
            $this->connections[$connectionId] = [
                'connection' => $connection,
                'created_at' => time(),
                'last_used' => time()
            ];
            
            $this->activeConnections[] = $connectionId;
            $this->stats['created']++;
            $this->stats['acquired']++;
            
            return $connection;
            
        } catch (\Throwable $e) {
            $this->logger->error("Failed to create {$this->protocol} connection", [
                'error' => $e->getMessage()
            ]);
            $this->stats['errors']++;
            return null;
        }
    }
}

/**
 * HTTP Connection Pool
 */
class HttpConnectionPool extends GenericConnectionPool
{
    public function __construct(array $config, LoggerInterface $logger)
    {
        parent::__construct('http', $config, $logger);
    }

    protected function createConnection(array $config = []): object
    {
        // Create HTTP-specific connection
        return new class($config) {
            private array $config;
            public $created_at;
            public $last_used;
            
            public function __construct(array $config) {
                $this->config = $config;
                $this->created_at = time();
                $this->last_used = time();
            }
            
            public function isKeepAlive(): bool {
                return $this->config['keep_alive'] ?? true;
            }
            
            public function close() {
                // HTTP connection cleanup
            }
        };
    }

    protected function isConnectionValid(object $connection): bool
    {
        // Check if HTTP connection is still valid
        return method_exists($connection, 'isKeepAlive') && $connection->isKeepAlive();
    }
}

/**
 * Database Connection Pool Adapter
 * Adapts the existing highper-database connection pool
 */
class DatabaseConnectionPoolAdapter extends ConnectionPool
{
    private object $databasePool;

    public function __construct(array $config, LoggerInterface $logger)
    {
        parent::__construct($config, $logger);
        
        // Initialize the actual database connection pool
        if (class_exists('\\EaseAppPHP\\HighPer\\Database\\ConnectionPool')) {
            $this->databasePool = new \HighPerApp\HighPer\Database\ConnectionPool($config);
        }
    }

    public function acquire(array $connectionConfig = []): ?object
    {
        if (isset($this->databasePool)) {
            try {
                return $this->databasePool->getConnection();
            } catch (\Throwable $e) {
                $this->logger->error('Database connection acquisition failed', [
                    'error' => $e->getMessage()
                ]);
                return null;
            }
        }
        
        return $this->createConnection($connectionConfig);
    }

    public function release(object $connection): void
    {
        if (isset($this->databasePool) && method_exists($this->databasePool, 'releaseConnection')) {
            $this->databasePool->releaseConnection($connection);
        }
    }

    protected function createConnection(array $config = []): object
    {
        // Fallback generic database connection
        return new \stdClass();
    }

    protected function isConnectionValid(object $connection): bool
    {
        return true;
    }
}

/**
 * WebSocket Connection Pool Adapter
 */
class WebSocketConnectionPoolAdapter extends ConnectionPool
{
    public function acquire(array $connectionConfig = []): ?object
    {
        // Use existing WebSocket connection pooling if available
        return $this->createConnection($connectionConfig);
    }

    public function release(object $connection): void
    {
        // WebSocket-specific release logic
    }

    protected function createConnection(array $config = []): object
    {
        return new \stdClass();
    }

    protected function isConnectionValid(object $connection): bool
    {
        return true;
    }
}