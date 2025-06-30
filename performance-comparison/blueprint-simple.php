<?php
// Simple Blueprint-style server for performance testing
// Using AMPHP v3 directly for fair comparison

require_once __DIR__ . '/../templates/blueprint/vendor/autoload.php';

use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\HttpStatus;
use Amp\Socket\InternetAddress;

// Simple route handler
$router = new Router();
$router->addRoute('GET', '/', function (Request $request): Response {
    $data = [
        'message' => 'Hello World',
        'framework' => 'HighPer Blueprint',
        'version' => '1.0',
        'timestamp' => time(),
        'memory' => memory_get_usage(true),
    ];
    
    return new Response(
        HttpStatus::OK,
        ['content-type' => 'application/json'],
        json_encode($data)
    );
});

// Create server
$server = new HttpServer(
    [new InternetAddress('0.0.0.0', 8080)],
    $router,
    new \Psr\Log\NullLogger()
);

echo "Blueprint-style server starting on port 8080...\n";
$server->start();

// Keep server running
Amp\async(function () {
    Amp\delay(PHP_INT_MAX);
})->await();