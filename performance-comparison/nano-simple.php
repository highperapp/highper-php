<?php
// Simple Nano-style server for performance testing
// Minimal overhead implementation

require_once __DIR__ . '/vendor/autoload.php';

use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\HttpStatus;
use Amp\Socket\InternetAddress;

// Ultra-minimal handler
$handler = function (Request $request): Response {
    // Minimal JSON response for benchmarking
    return new Response(
        HttpStatus::OK,
        ['content-type' => 'application/json'],
        '{"message":"Hello World","framework":"HighPer Nano","version":"1.0","timestamp":' . time() . ',"memory":' . memory_get_usage(true) . '}'
    );
};

// Create minimal server
$server = new HttpServer(
    [new InternetAddress('0.0.0.0', 8080)],
    $handler,
    new \Psr\Log\NullLogger()
);

echo "Nano-style server starting on port 8080...\n";
$server->start();

// Keep server running with minimal overhead
Amp\async(function () {
    Amp\delay(PHP_INT_MAX);
})->await();