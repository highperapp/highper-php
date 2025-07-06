<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Foundation;

use HighPerApp\HighPer\Contracts\ServerInterface;
use HighPerApp\HighPer\Contracts\ApplicationInterface;
use HighPerApp\HighPer\Contracts\ConfigManagerInterface;
use HighPerApp\HighPer\Contracts\LoggerInterface;
use HighPerApp\HighPer\Foundation\HealthChecker;
use HighPerApp\HighPer\Foundation\MonitoringManager;
use Amphp\Http\Server\HttpServer;
use Amphp\Http\Server\Request;
use Amphp\Http\Server\Response;
use Amphp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amphp\Socket\InternetAddress;
use Amphp\Socket\SocketServer;
use Revolt\EventLoop;

/**
 * High-Performance Multi-Protocol Server
 * 
 * Implements C10M-optimized server with support for:
 * - HTTP/1.1, HTTP/2, HTTP/3
 * - WebSocket (via external package)
 * - TCP (via external package)
 * - gRPC (via external package)
 * 
 * Leverages existing standalone libraries while maintaining simplicity.
 */
class Server implements ServerInterface
{
    private ApplicationInterface $app;
    private ConfigManagerInterface $config;
    private LoggerInterface $logger;
    private HealthChecker $healthChecker;
    private MonitoringManager $monitoring;
    
    private array $protocolHandlers = [];
    private array $serverInstances = [];
    private bool $running = false;
    private array $stats = [
        'connections' => 0,
        'requests' => 0,
        'start_time' => null,
        'protocols' => []
    ];

    public function __construct(ApplicationInterface $app)
    {
        $this->app = $app;
        $this->config = $app->getConfig();
        $this->logger = $app->getLogger();
        
        $this->setupDefaultConfiguration();
        $this->initializeHealthAndMonitoring();
        $this->registerDefaultProtocols();
        $this->setupBuiltinEndpoints();
    }

    public function start(): void
    {
        if ($this->running) {
            throw new \RuntimeException('Server is already running');
        }

        $this->logger->info('Starting HighPer Framework server');
        $this->stats['start_time'] = time();

        try {
            $this->startProtocolServers();
            $this->running = true;
            
            $this->logger->info('HighPer server started successfully', [
                'protocols' => array_keys($this->protocolHandlers),
                'pid' => getmypid()
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('Failed to start server', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            throw $e;
        }
    }

    public function stop(): void
    {
        if (!$this->running) {
            return;
        }

        $this->logger->info('Stopping HighPer Framework server');

        foreach ($this->serverInstances as $protocol => $server) {
            try {
                if (method_exists($server, 'stop')) {
                    $server->stop();
                }
                $this->logger->debug("Stopped {$protocol} server");
            } catch (\Throwable $e) {
                $this->logger->error("Error stopping {$protocol} server", [
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->running = false;
        $this->serverInstances = [];
        
        $this->logger->info('HighPer server stopped');
    }

    public function restart(): void
    {
        $this->logger->info('Restarting HighPer Framework server');
        $this->stop();
        $this->start();
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function getConfig(): array
    {
        return $this->config->getNamespace('server');
    }

    public function setConfig(array $config): void
    {
        foreach ($config as $key => $value) {
            $this->config->set("server.{$key}", $value);
        }
    }

    public function getStats(): array
    {
        $uptime = $this->stats['start_time'] ? time() - $this->stats['start_time'] : 0;
        
        return array_merge($this->stats, [
            'uptime' => $uptime,
            'running' => $this->running,
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'protocols_active' => count($this->serverInstances)
        ]);
    }

    public function getSupportedProtocols(): array
    {
        return array_keys($this->protocolHandlers);
    }

    public function addProtocolHandler(string $protocol, callable $handler): void
    {
        $this->protocolHandlers[$protocol] = $handler;
        $this->logger->debug("Added protocol handler", ['protocol' => $protocol]);
    }

    public function removeProtocolHandler(string $protocol): void
    {
        if (isset($this->protocolHandlers[$protocol])) {
            unset($this->protocolHandlers[$protocol]);
            $this->logger->debug("Removed protocol handler", ['protocol' => $protocol]);
        }
    }

    public function getConnectionsCount(): int
    {
        return $this->stats['connections'];
    }

    public function getWorkersCount(): int
    {
        // For now, single process. Will be enhanced with worker pools
        return 1;
    }

    public function scaleWorkers(int $count): void
    {
        $this->logger->info('Worker scaling requested', [
            'current' => $this->getWorkersCount(),
            'requested' => $count
        ]);
        
        // TODO: Implement worker scaling using external packages
        // Will integrate with highper-zero-downtime for hot scaling
    }

    private function setupDefaultConfiguration(): void
    {
        $defaultConfig = [
            'host' => '0.0.0.0',
            'port' => 8080,
            'protocols' => ['http'],
            'mode' => 'single_port_multiplexing', // or 'dedicated_ports'
            'max_connections' => 10000,
            'connection_timeout' => 30,
            'request_timeout' => 10,
            'enable_compression' => true,
            'enable_keep_alive' => true
        ];

        foreach ($defaultConfig as $key => $value) {
            if (!$this->config->has("server.{$key}")) {
                $this->config->set("server.{$key}", $value);
            }
        }
    }

    private function registerDefaultProtocols(): void
    {
        // HTTP Protocol Handler (built-in)
        $this->addProtocolHandler('http', [$this, 'handleHttpRequest']);
        
        // Register external protocol handlers if packages are available
        $this->registerExternalProtocols();
    }

    private function registerExternalProtocols(): void
    {
        $container = $this->app->getContainer();

        // WebSocket handler (from highper-websockets package)
        if ($container->has('websocket.server')) {
            $this->addProtocolHandler('websocket', function($request) use ($container) {
                return $container->get('websocket.server')->handle($request);
            });
        }

        // TCP handler (from highper-tcp package)
        if ($container->has('tcp.server')) {
            $this->addProtocolHandler('tcp', function($connection) use ($container) {
                return $container->get('tcp.server')->handle($connection);
            });
        }

        // gRPC handler (from highper-grpc package)
        if ($container->has('grpc.server')) {
            $this->addProtocolHandler('grpc', function($request) use ($container) {
                return $container->get('grpc.server')->handle($request);
            });
        }
    }

    private function startProtocolServers(): void
    {
        $enabledProtocols = $this->config->get('server.protocols', ['http']);
        $mode = $this->config->get('server.mode', 'single_port_multiplexing');

        if ($mode === 'single_port_multiplexing') {
            $this->startMultiplexedServer($enabledProtocols);
        } else {
            $this->startDedicatedPortServers($enabledProtocols);
        }
    }

    private function startMultiplexedServer(array $protocols): void
    {
        $host = $this->config->get('server.host', '0.0.0.0');
        $port = $this->config->get('server.port', 8080);
        
        $this->logger->info('Starting multiplexed server', [
            'host' => $host,
            'port' => $port,
            'protocols' => $protocols
        ]);

        // Create AMPHP HTTP server for primary handling
        $socketServer = SocketServer::listen(new InternetAddress($host, $port));
        
        $httpServer = new HttpServer(
            new ClosureRequestHandler([$this, 'handleMultiplexedRequest']),
            $this->logger
        );

        $this->serverInstances['multiplexed'] = $httpServer;
        
        // Start server asynchronously
        EventLoop::defer(function() use ($httpServer, $socketServer) {
            $httpServer->start($socketServer);
        });
    }

    private function startDedicatedPortServers(array $protocols): void
    {
        $host = $this->config->get('server.host', '0.0.0.0');
        $basePort = $this->config->get('server.port', 8080);

        foreach ($protocols as $index => $protocol) {
            $port = $basePort + $index;
            
            $this->logger->info("Starting dedicated {$protocol} server", [
                'host' => $host,
                'port' => $port
            ]);

            if ($protocol === 'http' && isset($this->protocolHandlers['http'])) {
                $this->startHttpServer($host, $port);
            }
            
            // Additional protocols would be started here
            // using their respective external packages
        }
    }

    private function startHttpServer(string $host, int $port): void
    {
        $socketServer = SocketServer::listen(new InternetAddress($host, $port));
        
        $httpServer = new HttpServer(
            new ClosureRequestHandler([$this, 'handleHttpRequest']),
            $this->logger
        );

        $this->serverInstances['http'] = $httpServer;
        
        EventLoop::defer(function() use ($httpServer, $socketServer) {
            $httpServer->start($socketServer);
        });
    }

    public function handleMultiplexedRequest(Request $request): Response
    {
        $this->stats['requests']++;
        
        // Protocol detection based on request characteristics
        $protocol = $this->detectProtocol($request);
        
        if (!isset($this->protocolHandlers[$protocol])) {
            $this->logger->warning('No handler for protocol', ['protocol' => $protocol]);
            return new Response(404, [], 'Protocol not supported');
        }

        try {
            $handler = $this->protocolHandlers[$protocol];
            return $handler($request);
            
        } catch (\Throwable $e) {
            $this->logger->error('Request handling error', [
                'protocol' => $protocol,
                'error' => $e->getMessage(),
                'uri' => (string) $request->getUri()
            ]);
            
            return new Response(500, [], 'Internal Server Error');
        }
    }

    public function handleHttpRequest(Request $request): Response
    {
        $this->stats['requests']++;
        
        try {
            // Basic HTTP handling - will be enhanced with router integration
            $router = $this->app->getRouter();
            $method = $request->getMethod();
            $path = $request->getUri()->getPath();
            
            $match = $router->match($method, $path);
            
            if ($match === null) {
                return new Response(404, [], 'Not Found');
            }

            // Execute matched handler
            $handler = $match->getHandler();
            
            if (is_callable($handler)) {
                $result = $handler($request, $match);
                
                if ($result instanceof Response) {
                    return $result;
                }
                
                return new Response(200, ['Content-Type' => 'application/json'], 
                    json_encode($result));
            }
            
            return new Response(200, [], 'Hello from HighPer Framework!');
            
        } catch (\Throwable $e) {
            $this->logger->error('HTTP request handling error', [
                'error' => $e->getMessage(),
                'uri' => (string) $request->getUri()
            ]);
            
            return new Response(500, [], 'Internal Server Error');
        }
    }

    private function detectProtocol(Request $request): string
    {
        // WebSocket detection
        $upgrade = $request->getHeader('upgrade');
        if ($upgrade && strtolower($upgrade) === 'websocket') {
            return 'websocket';
        }
        
        // gRPC detection
        $contentType = $request->getHeader('content-type');
        if ($contentType && strpos($contentType, 'application/grpc') === 0) {
            return 'grpc';
        }
        
        // Default to HTTP
        return 'http';
    }

    private function initializeHealthAndMonitoring(): void
    {
        $healthConfig = $this->config->get('health', []);
        $monitoringConfig = $this->config->get('monitoring', []);

        $this->healthChecker = new HealthChecker($this->app, $healthConfig, $this->logger);
        $this->monitoring = new MonitoringManager($this->app, $monitoringConfig, $this->logger);

        // Register server-specific health checks
        $this->healthChecker->registerCheck('server_running', function() {
            return [
                'status' => $this->running ? 'healthy' : 'critical',
                'data' => [
                    'running' => $this->running,
                    'uptime' => $this->getUptime(),
                    'connections' => $this->stats['connections'],
                    'requests' => $this->stats['requests']
                ]
            ];
        }, true);

        // Register server metrics collector
        $this->monitoring->registerCollector('server', function() {
            return [
                'connections_total' => $this->stats['connections'],
                'requests_total' => $this->stats['requests'],
                'uptime_seconds' => $this->getUptime(),
                'protocols_active' => count($this->serverInstances),
                'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)
            ];
        });

        $this->logger->info('Health checking and monitoring initialized');
    }

    private function setupBuiltinEndpoints(): void
    {
        $router = $this->app->getRouter();

        // Health check endpoints
        $router->addRoute('GET', '/health', function() {
            return $this->healthChecker->quickCheck();
        });

        $router->addRoute('GET', '/health/detailed', function() {
            return $this->healthChecker->checkHealth();
        });

        $router->addRoute('GET', '/health/readiness', function() {
            return $this->healthChecker->readinessCheck();
        });

        $router->addRoute('GET', '/health/liveness', function() {
            return $this->healthChecker->livenessCheck();
        });

        // Monitoring endpoints
        $router->addRoute('GET', '/metrics', function() {
            return $this->monitoring->getPerformanceMetrics();
        });

        $router->addRoute('GET', '/metrics/prometheus', function(Request $request) {
            $response = new Response(200, ['Content-Type' => 'text/plain'], 
                $this->monitoring->exportPrometheus());
            return $response;
        });

        $router->addRoute('GET', '/server/stats', function() {
            return array_merge($this->getStats(), [
                'health_stats' => $this->healthChecker->getStats(),
                'monitoring_stats' => $this->monitoring->getStats()
            ]);
        });

        $this->logger->debug('Built-in health and monitoring endpoints registered');
    }

    private function getUptime(): int
    {
        return $this->stats['start_time'] ? time() - $this->stats['start_time'] : 0;
    }

    public function getHealthChecker(): HealthChecker
    {
        return $this->healthChecker;
    }

    public function getMonitoring(): MonitoringManager
    {
        return $this->monitoring;
    }
}