<?php
// Simple Blueprint-style test server using PHP built-in server
$response = json_encode([
    'message' => 'Hello World',
    'framework' => 'HighPer Blueprint',
    'version' => '1.0',
    'timestamp' => microtime(true),
    'memory' => memory_get_usage(true)
]);

header('Content-Type: application/json');
header('Server: HighPer-Blueprint/1.0');
echo $response;