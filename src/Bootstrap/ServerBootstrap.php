<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Bootstrap;

use HighPerApp\HighPer\Contracts\BootstrapInterface;
use HighPerApp\HighPer\Contracts\ApplicationInterface;
use HighPerApp\HighPer\Contracts\ServerInterface;
use HighPerApp\HighPer\Foundation\Server;
use HighPerApp\HighPer\Foundation\HealthChecker;
use HighPerApp\HighPer\Foundation\MonitoringManager;
use HighPerApp\HighPer\Performance\ConnectionPoolManager;
use HighPerApp\HighPer\Performance\MemoryManager;

/**
 * Server Bootstrap
 * 
 * Bootstraps the multi-protocol server with external package integration.
 * Maintains simplicity while leveraging existing standalone libraries.
 */
class ServerBootstrap implements BootstrapInterface
{
    private ?ServerInterface $server = null;

    public function bootstrap(ApplicationInterface $app): void
    {
        $logger = $app->getLogger();
        $config = $app->getConfig();
        $container = $app->getContainer();

        $logger->info('Bootstrapping server infrastructure');

        // Create and configure the server
        $this->server = new Server($app);
        
        // Register server in container
        $container->instance(ServerInterface::class, $this->server);
        $container->alias('server', ServerInterface::class);

        // Register performance optimization components
        $this->registerPerformanceComponents($app);

        // Load external server packages
        $this->loadExternalServerPackages($app);

        // Configure server based on environment
        $this->configureServer($app);

        $logger->info('Server bootstrap completed', [
            'protocols' => $this->server->getSupportedProtocols(),
            'mode' => $config->get('server.mode', 'single_port_multiplexing')
        ]);
    }

    public function getPriority(): int
    {
        return 100; // High priority - servers need to be ready early
    }

    public function canBootstrap(ApplicationInterface $app): bool
    {
        // Check if required dependencies are available
        $container = $app->getContainer();
        
        return $container->has('router') && 
               $container->has('logger') && 
               extension_loaded('sockets');
    }

    public function getDependencies(): array
    {
        return [
            'router',
            'logger',
            'config'
        ];
    }

    public function getConfig(): array
    {
        return [
            'server' => [
                'auto_start' => false,
                'protocols' => ['http'],
                'mode' => 'single_port_multiplexing'
            ]
        ];
    }

    public function shutdown(): void
    {
        if ($this->server && $this->server->isRunning()) {
            $this->server->stop();
        }
    }

    private function registerPerformanceComponents(ApplicationInterface $app): void
    {
        $container = $app->getContainer();
        $config = $app->getConfig();
        $logger = $app->getLogger();

        $logger->info('Registering performance optimization components');

        // Register Connection Pool Manager
        $poolConfig = $config->get('connection_pools', []);
        $connectionPoolManager = new ConnectionPoolManager($poolConfig, $logger);
        $container->instance(ConnectionPoolManager::class, $connectionPoolManager);
        $container->alias('connection_pools', ConnectionPoolManager::class);

        // Register Memory Manager  
        $memoryConfig = $config->get('memory', []);
        $memoryManager = new MemoryManager($memoryConfig, $logger);
        $container->instance(MemoryManager::class, $memoryManager);
        $container->alias('memory', MemoryManager::class);

        // Optimize memory for long-running process
        $memoryManager->optimizeForLongRunning();

        // Register Health Checker and Monitoring (already done in Server constructor)
        $healthChecker = $this->server->getHealthChecker();
        $monitoring = $this->server->getMonitoring();
        
        $container->instance(HealthChecker::class, $healthChecker);
        $container->instance(MonitoringManager::class, $monitoring);
        $container->alias('health', HealthChecker::class);
        $container->alias('monitoring', MonitoringManager::class);

        // Setup periodic metrics collection
        if ($config->get('monitoring.enable_auto_collection', true)) {
            $interval = $config->get('monitoring.collection_interval', 60);
            \Revolt\EventLoop::repeat($interval, function() use ($monitoring, $logger) {
                try {
                    $monitoring->collectMetrics();
                } catch (\Throwable $e) {
                    $logger->error('Metrics collection error', [
                        'error' => $e->getMessage()
                    ]);
                }
            });
        }

        $logger->info('Performance components registered and optimized');
    }

    private function loadExternalServerPackages(ApplicationInterface $app): void
    {
        $container = $app->getContainer();
        $logger = $app->getLogger();

        // Load WebSocket server package if available
        if (class_exists('\\EaseAppPHP\\HighPer\\WebSockets\\WebSocketServerHandler')) {
            try {
                $wsHandler = new \HighPerApp\HighPer\WebSockets\WebSocketServerHandler();
                $container->instance('websocket.server', $wsHandler);
                $logger->debug('WebSocket server package loaded');
            } catch (\Throwable $e) {
                $logger->warning('Failed to load WebSocket server', ['error' => $e->getMessage()]);
            }
        }

        // Load TCP server and client package if available
        if (class_exists('\\HighPerApp\\HighPer\\TCP\\TCPServiceProvider')) {
            try {
                $tcpProvider = new \HighPerApp\HighPer\TCP\TCPServiceProvider(
                    $container, 
                    $app->getConfig()->get('tcp', [])
                );
                $tcpProvider->register();
                $tcpProvider->boot();
                
                $container->instance('tcp.provider', $tcpProvider);
                $logger->info('TCP server and client package loaded', [
                    'server_available' => $tcpProvider->isServerAvailable(),
                    'client_available' => $tcpProvider->isClientAvailable()
                ]);
            } catch (\Throwable $e) {
                $logger->warning('Failed to load TCP package', ['error' => $e->getMessage()]);
            }
        }

        // Load gRPC server package if available
        if (class_exists('\\EaseAppPHP\\HighPer\\Grpc\\GRPCServer')) {
            try {
                $grpcServer = new \HighPerApp\HighPer\Grpc\GRPCServer();
                $container->instance('grpc.server', $grpcServer);
                $logger->debug('gRPC server package loaded');
            } catch (\Throwable $e) {
                $logger->warning('Failed to load gRPC server', ['error' => $e->getMessage()]);
            }
        }

        // Load real-time protocols package if available
        if (class_exists('\\EaseAppPHP\\HighPer\\Realtime\\Http3\\Http3Server')) {
            try {
                $http3Server = new \HighPerApp\HighPer\Realtime\Http3\Http3Server();
                $container->instance('http3.server', $http3Server);
                $logger->debug('HTTP/3 server package loaded');
            } catch (\Throwable $e) {
                $logger->warning('Failed to load HTTP/3 server', ['error' => $e->getMessage()]);
            }
        }
    }

    private function configureServer(ApplicationInterface $app): void
    {
        $config = $app->getConfig();
        $logger = $app->getLogger();

        // Configure based on environment
        $environment = $config->getEnvironment();
        
        $serverConfig = [];
        
        switch ($environment) {
            case 'production':
                $serverConfig = [
                    'max_connections' => 100000,
                    'connection_timeout' => 60,
                    'request_timeout' => 30,
                    'enable_compression' => true,
                    'enable_keep_alive' => true,
                    'protocols' => ['http', 'websocket', 'grpc']
                ];
                break;
                
            case 'development':
                $serverConfig = [
                    'max_connections' => 1000,
                    'connection_timeout' => 30,
                    'request_timeout' => 10,
                    'enable_compression' => false,
                    'enable_keep_alive' => true,
                    'protocols' => ['http', 'websocket']
                ];
                break;
                
            case 'testing':
                $serverConfig = [
                    'max_connections' => 100,
                    'connection_timeout' => 10,
                    'request_timeout' => 5,
                    'enable_compression' => false,
                    'enable_keep_alive' => false,
                    'protocols' => ['http']
                ];
                break;
        }

        // Override with user configuration
        $userConfig = $config->getNamespace('server');
        $finalConfig = array_merge($serverConfig, $userConfig);
        
        $this->server->setConfig($finalConfig);

        $logger->info('Server configured for environment', [
            'environment' => $environment,
            'config' => $finalConfig
        ]);
    }
}