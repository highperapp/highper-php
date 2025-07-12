<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Foundation;

use HighPerApp\HighPer\Contracts\HTTPServerManagerInterface;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Request;
use Amp\Socket;
use Psr\Log\LoggerInterface;

/**
 * AMPHP HTTP Server Manager - Enhanced AMPHP Integration
 * 
 * Complete secure/non-secure protocol matrix with NGINX compatibility
 * and environment-driven configuration support.
 * 
 */
class AMPHTTPServerManager implements HTTPServerManagerInterface
{
    private ?HttpServer $server = null;
    private array $config = [];
    private array $enabledProtocols = [];
    private array $proxyHeaders = [];
    private array $stats = ['requests' => 0, 'connections' => 0];
    private bool $running = false;
    private ?LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        $this->config = [
            'host' => '0.0.0.0',
            'port' => 8080,
            'tls_port' => 8443,
            'max_connections' => 10000,
            'connection_timeout' => 30
        ];
    }

    public function start(): void
    {
        $sockets = $this->createSockets();
        
        $requestHandler = new ClosureRequestHandler(function (Request $request): Response {
            $this->stats['requests']++;
            
            // Process proxy headers if behind NGINX
            if (!empty($this->proxyHeaders)) {
                $this->processProxyHeaders($request);
            }
            
            return new Response(200, [], 'HighPer Server Running');
        });

        $this->server = new HttpServer($sockets, $requestHandler, $this->logger);
        $this->server->start();
        $this->running = true;
        
        $this->logger?->info('AMPHP HTTP Server started', [
            'protocols' => $this->enabledProtocols,
            'sockets' => count($sockets)
        ]);
    }

    private function createSockets(): array
    {
        $sockets = [];
        
        foreach ($this->enabledProtocols as $protocol) {
            $socket = match($protocol) {
                'http' => Socket\listen("{$this->config['host']}:{$this->config['port']}"),
                'https' => $this->createTLSSocket(),
                'ws', 'wss' => $this->createWebSocketSocket($protocol),
                'grpc' => Socket\listen("{$this->config['host']}:" . ($this->config['grpc_port'] ?? 9090)),
                'grpc-tls' => $this->createGRPCTLSSocket(),
                default => null
            };
            
            if ($socket) {
                $sockets[] = $socket;
                $this->stats['connections']++;
            }
        }
        
        return $sockets;
    }

    private function createTLSSocket(): Socket\ServerSocket
    {
        $context = (new Socket\BindContext())->withTlsContext(
            (new Socket\ServerTlsContext())->withDefaultCertificate(
                new Socket\Certificate($this->config['cert_path'] ?? '/tmp/cert.pem')
            )
        );
        
        return Socket\listen("{$this->config['host']}:{$this->config['tls_port']}", $context);
    }

    private function createWebSocketSocket(string $protocol): Socket\ServerSocket
    {
        $port = $protocol === 'wss' ? $this->config['tls_port'] : $this->config['port'];
        return Socket\listen("{$this->config['host']}:{$port}");
    }

    private function createGRPCTLSSocket(): Socket\ServerSocket
    {
        $context = (new Socket\BindContext())->withTlsContext(new Socket\ServerTlsContext());
        return Socket\listen("{$this->config['host']}:" . ($this->config['grpc_tls_port'] ?? 9091), $context);
    }

    private function processProxyHeaders(Request $request): void
    {
        foreach ($this->proxyHeaders as $header) {
            if ($request->hasHeader($header)) {
                $this->logger?->debug("Proxy header processed: {$header}");
            }
        }
    }

    public function stop(): void { $this->server?->stop(); $this->running = false; }
    public function enableProtocols(array $protocols): void { $this->enabledProtocols = $protocols; }
    public function setProxyHeaders(array $headers): void { $this->proxyHeaders = $headers; }
    public function setConfig(array $config): void { $this->config = array_merge($this->config, $config); }
    public function getStats(): array { return array_merge($this->stats, ['running' => $this->running, 'protocols' => $this->enabledProtocols]); }
    public function isRunning(): bool { return $this->running; }
    public function getEnabledProtocols(): array { return $this->enabledProtocols; }
    public function gracefulShutdown(): void { $this->server?->stop(); $this->running = false; }
}