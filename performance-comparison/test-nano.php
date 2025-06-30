<?php
// Simple Nano-style test server using PHP built-in server
$response = json_encode([
    'message' => 'Hello World',
    'framework' => 'HighPer Nano',
    'version' => '1.0',
    'timestamp' => microtime(true),
    'memory' => memory_get_usage(true)
]);

header('Content-Type: application/json');
header('Server: HighPer-Nano/1.0');
echo $response;