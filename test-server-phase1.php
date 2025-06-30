#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Phase 1.1 Test Server
 * 
 * Simple HTTP server for testing ProcessManager and AsyncManager performance
 */

require_once __DIR__ . '/templates/nano/vendor/autoload.php';
require_once __DIR__ . '/core/framework/src/Foundation/AsyncManager.php';

use HighPerApp\HighPer\Foundation\AsyncManager;
use Revolt\EventLoop;
use Amp\Http\Server\SocketHttpServer;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Socket\InternetAddress;
use function Amp\Socket\listen;
use Psr\Log\NullLogger;

echo "🚀 Starting HighPer v3 Phase 1.1 Test Server\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

// Parse command line arguments
$port = 8090;
$host = '0.0.0.0';

foreach ($argv as $arg) {
    if (strpos($arg, '--port=') === 0) {
        $port = (int) substr($arg, 7);
    }
    if (strpos($arg, '--host=') === 0) {
        $host = substr($arg, 7);
    }
}

// Initialize AsyncManager
AsyncManager::initialize();
echo "✅ AsyncManager initialized\n";

// Request counter for performance tracking
$requestCount = 0;
$startTime = time();

// Create request handler with AsyncManager
$requestHandler = new ClosureRequestHandler(function (Request $request) use (&$requestCount, $startTime): Response {
    $requestCount++;
    
    $path = $request->getUri()->getPath();
    $method = $request->getMethod();
    
    // Handle different endpoints
    return match ($path) {
        '/ping' => new Response(200, ['Content-Type' => 'text/plain'], 'pong'),
        
        '/health' => new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'status' => 'healthy',
            'service' => 'highper-v3-phase1',
            'timestamp' => date('c'),
            'uptime' => time() - $startTime,
            'requests' => $requestCount,
            'memory' => round(memory_get_usage(true) / 1024 / 1024, 2) . 'MB',
            'async_stats' => AsyncManager::getStats()
        ])),
        
        '/async-test' => AsyncManager::autoYield(function() use ($requestCount) {
            // Simulate async work
            usleep(1000); // 1ms async operation
            
            return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'message' => 'Async operation completed',
                'request_id' => $requestCount,
                'timestamp' => microtime(true),
                'async_manager' => 'enabled'
            ]));
        })->await(),
        
        '/concurrent-test' => AsyncManager::concurrent([
            'operation1' => function() { usleep(500); return 'Task 1 completed'; },
            'operation2' => function() { usleep(500); return 'Task 2 completed'; },
            'operation3' => function() { usleep(500); return 'Task 3 completed'; }
        ])->then(function($results) use ($requestCount) {
            return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'message' => 'Concurrent operations completed',
                'request_id' => $requestCount,
                'results' => $results,
                'timestamp' => microtime(true)
            ]));
        })->await(),
        
        '/' => new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'service' => 'HighPer v3 Phase 1.1 Test Server',
            'version' => '3.0.0-dev',
            'timestamp' => date('c'),
            'uptime' => time() - $startTime,
            'requests' => $requestCount,
            'endpoints' => [
                'GET /' => 'Service info',
                'GET /ping' => 'Simple ping (baseline)',
                'GET /health' => 'Health check with stats',
                'GET /async-test' => 'AsyncManager test',
                'GET /concurrent-test' => 'Concurrent operations test'
            ],
            'features' => [
                'async_manager' => true,
                'auto_yield' => true,
                'concurrent_ops' => true,
                'process_manager' => 'ready_for_integration'
            ]
        ])),
        
        default => new Response(404, ['Content-Type' => 'application/json'], json_encode([
            'error' => 'Not Found',
            'path' => $path,
            'method' => $method
        ]))
    };
});

// Start server
try {
    // Create logger
    $logger = new NullLogger();
    
    // Create HTTP server for direct access
    $httpServer = SocketHttpServer::createForDirectAccess($logger);
    
    // Add server socket binding
    $httpServer->expose(new InternetAddress($host, $port));
    
    echo "✅ Server configuration:\n";
    echo "   Host: {$host}\n";
    echo "   Port: {$port}\n";
    echo "   Process ID: " . getmypid() . "\n";
    echo "   AsyncManager: Enabled\n";
    echo "\n";
    echo "📍 Available endpoints:\n";
    echo "   GET  http://{$host}:{$port}/         - Service info\n";
    echo "   GET  http://{$host}:{$port}/ping     - Simple ping (for wrk2 baseline)\n";
    echo "   GET  http://{$host}:{$port}/health   - Health check with stats\n";
    echo "   GET  http://{$host}:{$port}/async-test - AsyncManager test\n";
    echo "   GET  http://{$host}:{$port}/concurrent-test - Concurrent operations\n";
    echo "\n";
    echo "🎯 Phase 1.1 test server ready! Press Ctrl+C to stop\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "\n💡 Run wrk2 benchmark:\n";
    echo "   wrk2 -t4 -c100 -d30s -R5000 --latency http://{$host}:{$port}/ping\n";
    echo "\n";
    
    // Setup graceful shutdown
    if (extension_loaded('pcntl')) {
        pcntl_signal(SIGTERM, function() use ($httpServer) {
            echo "\n🛑 Received SIGTERM, shutting down gracefully...\n";
            $httpServer->stop();
            exit(0);
        });
        
        pcntl_signal(SIGINT, function() use ($httpServer) {
            echo "\n🛑 Received SIGINT, shutting down gracefully...\n";
            $httpServer->stop();
            exit(0);
        });
    }
    
    $httpServer->start($requestHandler, new \Amp\Http\Server\DefaultErrorHandler());
    EventLoop::run();
    
} catch (\Throwable $e) {
    echo "❌ Server error: " . $e->getMessage() . "\n";
    if (isset($argv) && in_array('--debug', $argv)) {
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    }
    exit(1);
}