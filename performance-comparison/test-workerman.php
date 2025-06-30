<?php
// Simple Workerman test server
require_once __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;

// Create HTTP worker
$worker = new Worker('http://0.0.0.0:8080');
$worker->count = 4;

$worker->onMessage = function($connection, $data) {
    $response = json_encode([
        'message' => 'Hello World',
        'framework' => 'Workerman',
        'version' => '5.1',
        'timestamp' => microtime(true),
        'memory' => memory_get_usage(true)
    ]);
    
    $connection->send("HTTP/1.1 200 OK\r\n" .
                     "Content-Type: application/json\r\n" .
                     "Content-Length: " . strlen($response) . "\r\n" .
                     "Connection: keep-alive\r\n\r\n" .
                     $response);
};

echo "Workerman server starting on port 8080...\n";
Worker::runAll();