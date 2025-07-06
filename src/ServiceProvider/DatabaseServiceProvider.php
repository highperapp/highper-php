<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\ServiceProvider;

use HighPerApp\HighPer\Contracts\ServiceProviderInterface;
use HighPerApp\HighPer\Contracts\ContainerInterface;
use HighPerApp\HighPer\Contracts\LoggerInterface;

/**
 * Database Service Provider for HighPer Framework
 * 
 * Integrates the standalone highperapp/database library into HighPer Framework
 * providing sophisticated async/sync connection pooling for C10M performance.
 * 
 * Features from Database Library:
 * - Advanced connection pooling (sync & async)
 * - Circuit breaker patterns for five nines reliability
 * - Event sourcing and CQRS support
 * - MySQL and PostgreSQL adapters with health monitoring
 * - SSL support and connection validation
 */
class DatabaseServiceProvider implements ServiceProviderInterface
{
    private array $config = [];
    private bool $databaseAvailable = false;
    private array $registeredServices = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'auto_detect' => true,
            'enable_pooling' => true,
            'enable_event_sourcing' => false,
            'enable_cqrs' => false,
            'lazy_loading' => true,
            'health_monitoring' => true,
            'circuit_breaker' => true
        ], $config);

        $this->detectDatabaseAvailability();
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

        if (!$this->databaseAvailable) {
            if ($this->config['auto_detect']) {
                $this->registerNullDatabaseServices($this->container);
            }
            return;
        }

        $this->registerConnectionPooling($this->container);
        $this->registerDatabaseManagers($this->container);
        $this->registerQueryBuilders($this->container);
        $this->registerEventSourcing($this->container);
        $this->registerHealthMonitoring($this->container);
    }

    public function boot(): void
    {
        if ($this->container === null) {
            throw new \RuntimeException('Container must be set before calling boot()');
        }

        if (!$this->databaseAvailable) {
            $logger = $this->getLogger($this->container);
            $logger?->info('HighPer Database services not available', [
                'reason' => 'highperapp/database package not installed',
                'suggestion' => 'Run: composer require highperapp/database'
            ]);
            return;
        }

        $this->initializeConnectionPools($this->container);
        $this->startHealthMonitoring($this->container);
        $this->registerDatabaseMiddleware($this->container);
    }

    private function detectDatabaseAvailability(): void
    {
        // Check if highperapp/database is installed
        $this->databaseAvailable = class_exists('\\HighPerApp\\HighPer\\Database\\DatabaseManager');
        
        if ($this->databaseAvailable) {
            $this->registeredServices[] = 'database_core';
        }
    }

    private function registerConnectionPooling(ContainerInterface $container): void
    {
        // Async Connection Pool (primary for high performance)
        $container->singleton('database.pool.async', function() {
            $config = $this->getPoolConfiguration('async');
            return new \HighPerApp\HighPer\Database\Pool\AsyncConnectionPool($config);
        });

        // Sync Connection Pool (fallback/compatibility)
        $container->singleton('database.pool.sync', function() {
            $config = $this->getPoolConfiguration('sync');
            return new \HighPerApp\HighPer\Database\Pool\SyncConnectionPool($config);
        });

        // Pool Manager (unified interface)
        $container->singleton('database.pool_manager', function() use ($container) {
            $asyncPool = $container->get('database.pool.async');
            $syncPool = $container->get('database.pool.sync');
            $config = $this->getPoolManagerConfiguration();
            
            return new \HighPerApp\HighPer\Database\Pool\PoolManager(
                $asyncPool, 
                $syncPool, 
                $config
            );
        });

        $this->registeredServices[] = 'connection_pooling';
    }

    private function registerDatabaseManagers(ContainerInterface $container): void
    {
        // Primary Database Manager
        $container->singleton('database.manager', function() use ($container) {
            $poolManager = $container->get('database.pool_manager');
            $config = $this->getDatabaseConfiguration();
            $logger = $this->getLogger($container);
            
            return new \HighPerApp\HighPer\Database\DatabaseManager(
                $poolManager,
                $config,
                $logger
            );
        });

        // MySQL Connection Factory
        $container->singleton('database.mysql_factory', function() {
            $config = $this->getMySQLConfiguration();
            return new \HighPerApp\HighPer\Database\Connectors\MySQLConnector($config);
        });

        // PostgreSQL Connection Factory
        $container->singleton('database.postgresql_factory', function() {
            $config = $this->getPostgreSQLConfiguration();
            return new \HighPerApp\HighPer\Database\Connectors\PostgreSQLConnector($config);
        });

        $this->registeredServices[] = 'database_managers';
    }

    private function registerQueryBuilders(ContainerInterface $container): void
    {
        // Query Builder
        $container->singleton('database.query_builder', function() use ($container) {
            $manager = $container->get('database.manager');
            return new \HighPerApp\HighPer\Database\Query\QueryBuilder($manager);
        });

        // Schema Builder
        $container->singleton('database.schema_builder', function() use ($container) {
            $manager = $container->get('database.manager');
            return new \HighPerApp\HighPer\Database\Schema\SchemaBuilder($manager);
        });

        $this->registeredServices[] = 'query_builders';
    }

    private function registerEventSourcing(ContainerInterface $container): void
    {
        if (!$this->config['enable_event_sourcing']) {
            return;
        }

        // Event Store
        $container->singleton('database.event_store', function() use ($container) {
            $manager = $container->get('database.manager');
            $config = $this->getEventSourcingConfiguration();
            return new \HighPerApp\HighPer\Database\EventSourcing\EventStore($manager, $config);
        });

        // Snapshot Store
        $container->singleton('database.snapshot_store', function() use ($container) {
            $manager = $container->get('database.manager');
            $config = $this->getSnapshotConfiguration();
            return new \HighPerApp\HighPer\Database\EventSourcing\SnapshotStore($manager, $config);
        });

        // CQRS Command Bus (if enabled)
        if ($this->config['enable_cqrs']) {
            $container->singleton('database.command_bus', function() {
                $config = $this->getCQRSConfiguration();
                return new \HighPerApp\HighPer\Database\CQRS\CommandBus($config);
            });

            $container->singleton('database.query_bus', function() {
                $config = $this->getCQRSConfiguration();
                return new \HighPerApp\HighPer\Database\CQRS\QueryBus($config);
            });
        }

        $this->registeredServices[] = 'event_sourcing';
    }

    private function registerHealthMonitoring(ContainerInterface $container): void
    {
        if (!$this->config['health_monitoring']) {
            return;
        }

        $container->singleton('database.health_monitor', function() use ($container) {
            $poolManager = $container->get('database.pool_manager');
            $config = $this->getHealthMonitoringConfiguration();
            $logger = $this->getLogger($container);
            
            return new \HighPerApp\HighPer\Database\Monitoring\HealthMonitor(
                $poolManager,
                $config,
                $logger
            );
        });

        $this->registeredServices[] = 'health_monitoring';
    }

    private function registerNullDatabaseServices(ContainerInterface $container): void
    {
        $nullDatabase = new class {
            public function __call($method, $args) {
                throw new \RuntimeException(
                    'Database operations not available. Install highperapp/database package.'
                );
            }
        };

        $container->singleton('database.manager', fn() => $nullDatabase);
        $container->singleton('database.pool_manager', fn() => $nullDatabase);

        $this->registeredServices[] = 'null_database_services';
    }

    private function initializeConnectionPools(ContainerInterface $container): void
    {
        if (!$this->config['enable_pooling']) {
            return;
        }

        try {
            $poolManager = $container->get('database.pool_manager');
            
            // Initialize MySQL pools if configured
            if ($this->isMySQLConfigured()) {
                $mysqlFactory = $container->get('database.mysql_factory');
                $poolManager->createPool('mysql', [$mysqlFactory, 'create'], $this->getMySQLPoolConfig());
            }

            // Initialize PostgreSQL pools if configured
            if ($this->isPostgreSQLConfigured()) {
                $postgresqlFactory = $container->get('database.postgresql_factory');
                $poolManager->createPool('postgresql', [$postgresqlFactory, 'create'], $this->getPostgreSQLPoolConfig());
            }

            $logger = $this->getLogger($container);
            $logger?->info('Database connection pools initialized successfully');

        } catch (\Throwable $e) {
            $logger = $this->getLogger($container);
            $logger?->error('Failed to initialize database connection pools', [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function startHealthMonitoring(ContainerInterface $container): void
    {
        if (!$this->config['health_monitoring']) {
            return;
        }

        try {
            $healthMonitor = $container->get('database.health_monitor');
            $healthMonitor->start();
            
            $logger = $this->getLogger($container);
            $logger?->info('Database health monitoring started');
        } catch (\Throwable $e) {
            $logger = $this->getLogger($container);
            $logger?->warning('Failed to start database health monitoring', [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function registerDatabaseMiddleware(ContainerInterface $container): void
    {
        if (!$container->has('http.server')) {
            return;
        }

        // Database Transaction Middleware
        $container->singleton('middleware.database_transaction', function() use ($container) {
            return new class($container) {
                private ContainerInterface $container;

                public function __construct(ContainerInterface $container)
                {
                    $this->container = $container;
                }

                public function __invoke($request, $handler)
                {
                    $manager = $this->container->get('database.manager');
                    
                    try {
                        $manager->beginTransaction();
                        $response = $handler($request);
                        $manager->commit();
                        return $response;
                    } catch (\Throwable $e) {
                        $manager->rollback();
                        throw $e;
                    }
                }
            };
        });

        $this->registeredServices[] = 'database_middleware';
    }

    private function getPoolConfiguration(string $type): array
    {
        $baseConfig = [
            'min_connections' => (int) ($_ENV['DB_POOL_MIN'] ?? 5),
            'max_connections' => (int) ($_ENV['DB_POOL_MAX'] ?? 100),
            'max_idle_time' => (int) ($_ENV['DB_POOL_MAX_IDLE'] ?? 300),
            'max_lifetime' => (int) ($_ENV['DB_POOL_MAX_LIFETIME'] ?? 3600),
            'health_check_interval' => (int) ($_ENV['DB_POOL_HEALTH_INTERVAL'] ?? 30),
            'connection_timeout' => (int) ($_ENV['DB_CONNECTION_TIMEOUT'] ?? 10),
            'enable_circuit_breaker' => (bool) ($_ENV['DB_CIRCUIT_BREAKER'] ?? $this->config['circuit_breaker'])
        ];

        if ($type === 'async') {
            $baseConfig['async_enabled'] = true;
            $baseConfig['max_connections'] = (int) ($_ENV['DB_ASYNC_POOL_MAX'] ?? 200);
        }

        return $baseConfig;
    }

    private function getPoolManagerConfiguration(): array
    {
        return [
            'prefer_async' => (bool) ($_ENV['DB_PREFER_ASYNC'] ?? true),
            'fallback_to_sync' => (bool) ($_ENV['DB_FALLBACK_SYNC'] ?? true),
            'pool_balancing' => $_ENV['DB_POOL_BALANCING'] ?? 'round_robin',
            'enable_monitoring' => $this->config['health_monitoring']
        ];
    }

    private function getDatabaseConfiguration(): array
    {
        return [
            'default_connection' => $_ENV['DB_CONNECTION'] ?? 'mysql',
            'connections' => [
                'mysql' => $this->getMySQLConfiguration(),
                'postgresql' => $this->getPostgreSQLConfiguration()
            ],
            'migrations' => [
                'table' => $_ENV['DB_MIGRATIONS_TABLE'] ?? 'migrations',
                'path' => $_ENV['DB_MIGRATIONS_PATH'] ?? 'database/migrations'
            ]
        ];
    }

    private function getMySQLConfiguration(): array
    {
        return [
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'port' => (int) ($_ENV['DB_PORT'] ?? 3306),
            'database' => $_ENV['DB_DATABASE'] ?? '',
            'username' => $_ENV['DB_USERNAME'] ?? '',
            'password' => $_ENV['DB_PASSWORD'] ?? '',
            'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
            'collation' => $_ENV['DB_COLLATION'] ?? 'utf8mb4_unicode_ci',
            'ssl' => [
                'enabled' => (bool) ($_ENV['DB_SSL_ENABLED'] ?? false),
                'ca' => $_ENV['DB_SSL_CA'] ?? null,
                'cert' => $_ENV['DB_SSL_CERT'] ?? null,
                'key' => $_ENV['DB_SSL_KEY'] ?? null
            ]
        ];
    }

    private function getPostgreSQLConfiguration(): array
    {
        return [
            'host' => $_ENV['PGSQL_HOST'] ?? 'localhost',
            'port' => (int) ($_ENV['PGSQL_PORT'] ?? 5432),
            'database' => $_ENV['PGSQL_DATABASE'] ?? '',
            'username' => $_ENV['PGSQL_USERNAME'] ?? '',
            'password' => $_ENV['PGSQL_PASSWORD'] ?? '',
            'charset' => $_ENV['PGSQL_CHARSET'] ?? 'utf8',
            'ssl' => [
                'enabled' => (bool) ($_ENV['PGSQL_SSL_ENABLED'] ?? false),
                'mode' => $_ENV['PGSQL_SSL_MODE'] ?? 'prefer'
            ]
        ];
    }

    private function getEventSourcingConfiguration(): array
    {
        return [
            'events_table' => $_ENV['ES_EVENTS_TABLE'] ?? 'events',
            'snapshots_table' => $_ENV['ES_SNAPSHOTS_TABLE'] ?? 'snapshots',
            'snapshot_frequency' => (int) ($_ENV['ES_SNAPSHOT_FREQUENCY'] ?? 100)
        ];
    }

    private function getSnapshotConfiguration(): array
    {
        return [
            'table' => $_ENV['ES_SNAPSHOTS_TABLE'] ?? 'snapshots',
            'compression' => (bool) ($_ENV['ES_SNAPSHOT_COMPRESSION'] ?? true)
        ];
    }

    private function getCQRSConfiguration(): array
    {
        return [
            'command_bus_middleware' => [],
            'query_bus_middleware' => [],
            'enable_async_commands' => (bool) ($_ENV['CQRS_ASYNC_COMMANDS'] ?? true)
        ];
    }

    private function getHealthMonitoringConfiguration(): array
    {
        return [
            'check_interval' => (int) ($_ENV['DB_HEALTH_CHECK_INTERVAL'] ?? 30),
            'failure_threshold' => (int) ($_ENV['DB_HEALTH_FAILURE_THRESHOLD'] ?? 3),
            'recovery_timeout' => (int) ($_ENV['DB_HEALTH_RECOVERY_TIMEOUT'] ?? 60)
        ];
    }

    private function getMySQLPoolConfig(): array
    {
        return array_merge($this->getPoolConfiguration('async'), [
            'connection_type' => 'mysql',
            'ssl_enabled' => (bool) ($_ENV['DB_SSL_ENABLED'] ?? false)
        ]);
    }

    private function getPostgreSQLPoolConfig(): array
    {
        return array_merge($this->getPoolConfiguration('async'), [
            'connection_type' => 'postgresql',
            'ssl_enabled' => (bool) ($_ENV['PGSQL_SSL_ENABLED'] ?? false)
        ]);
    }

    private function isMySQLConfigured(): bool
    {
        return !empty($_ENV['DB_HOST']) && !empty($_ENV['DB_DATABASE']);
    }

    private function isPostgreSQLConfigured(): bool
    {
        return !empty($_ENV['PGSQL_HOST']) && !empty($_ENV['PGSQL_DATABASE']);
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
            'database.manager',
            'database.pool_manager'
        ];

        if ($this->databaseAvailable) {
            $services = array_merge($services, [
                'database.pool.async',
                'database.pool.sync',
                'database.query_builder',
                'database.schema_builder',
                'database.mysql_factory',
                'database.postgresql_factory',
                'middleware.database_transaction'
            ]);

            if ($this->config['enable_event_sourcing']) {
                $services = array_merge($services, [
                    'database.event_store',
                    'database.snapshot_store'
                ]);

                if ($this->config['enable_cqrs']) {
                    $services = array_merge($services, [
                        'database.command_bus',
                        'database.query_bus'
                    ]);
                }
            }

            if ($this->config['health_monitoring']) {
                $services[] = 'database.health_monitor';
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

    public function isDatabaseAvailable(): bool
    {
        return $this->databaseAvailable;
    }

    public function getConfiguration(): array
    {
        return [
            'database_available' => $this->databaseAvailable,
            'registered_services' => $this->registeredServices,
            'configuration' => $this->config
        ];
    }
}