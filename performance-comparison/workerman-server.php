<?php
require_once __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

// Create an HTTP worker
$http_worker = new Worker('http://0.0.0.0:8080');

// Set worker processes for high concurrency
$http_worker->count = 4;

// Handle HTTP requests
$http_worker->onMessage = function($connection, Request $request) {
    // Simple JSON response for benchmarking
    $data = [
        'message' => 'Hello World',
        'framework' => 'Workerman',
        'version' => '5.1',
        'timestamp' => time(),
        'memory' => memory_get_usage(true),
    ];
    
    $response = new Response(200, [
        'Content-Type' => 'application/json',
        'Server' => 'Workerman/5.1'
    ], json_encode($data));
    
    $connection->send($response);
};

// Run the worker
Worker::runAll();