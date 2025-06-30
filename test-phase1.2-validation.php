#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Phase 1.2 Validation Test
 * 
 * Tests AdaptiveSerializer and RustFFIManager implementations
 */

require_once __DIR__ . '/templates/nano/vendor/autoload.php';
require_once __DIR__ . '/core/framework/src/Foundation/AdaptiveSerializer.php';
require_once __DIR__ . '/core/framework/src/Foundation/RustFFIManager.php';

use HighPerApp\HighPer\Foundation\AdaptiveSerializer;
use HighPerApp\HighPer\Foundation\RustFFIManager;

echo "🧪 Phase 1.2 Validation Test - AdaptiveSerializer & RustFFIManager\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// Test data samples
$testData = [
    'simple' => 'hello',
    'number' => 12345,
    'array' => [1, 2, 3, 'test'],
    'complex' => [
        'user' => ['id' => 1, 'name' => 'John'],
        'posts' => [
            ['id' => 1, 'title' => 'First Post', 'content' => 'Content here'],
            ['id' => 2, 'title' => 'Second Post', 'content' => 'More content']
        ],
        'metadata' => ['created' => date('c'), 'version' => '1.0']
    ]
];

function runTest(string $name, callable $test): bool
{
    try {
        echo "🔍 Testing {$name}... ";
        $result = $test();
        if ($result) {
            echo "✅ PASS\n";
            return true;
        } else {
            echo "❌ FAIL\n";
            return false;
        }
    } catch (\Throwable $e) {
        echo "❌ ERROR: " . $e->getMessage() . "\n";
        return false;
    }
}

$passedTests = 0;
$totalTests = 0;

// Test 1: AdaptiveSerializer initialization
$totalTests++;
$passedTests += runTest("AdaptiveSerializer initialization", function() {
    AdaptiveSerializer::initialize();
    return true;
});

// Test 2: Basic serialization/deserialization
$totalTests++;
$passedTests += runTest("Basic serialization/deserialization", function() use ($testData) {
    foreach ($testData as $key => $data) {
        $serialized = AdaptiveSerializer::serialize($data);
        $deserialized = AdaptiveSerializer::deserialize($serialized);
        
        if ($data !== $deserialized) {
            throw new \Exception("Data mismatch for {$key}");
        }
    }
    return true;
});

// Test 3: AdaptiveSerializer stats
$totalTests++;
$passedTests += runTest("AdaptiveSerializer stats tracking", function() {
    $stats = AdaptiveSerializer::getStats();
    return isset($stats['serializations']) && $stats['serializations'] > 0;
});

// Test 4: Available formats detection
$totalTests++;
$passedTests += runTest("Available formats detection", function() {
    $formats = AdaptiveSerializer::getAvailableFormats();
    return in_array('json', $formats) && is_array($formats);
});

// Test 5: RustFFIManager initialization
$totalTests++;
$passedTests += runTest("RustFFIManager initialization", function() {
    RustFFIManager::initialize();
    return true;
});

// Test 6: RustFFI availability check
$totalTests++;
$passedTests += runTest("RustFFI availability check", function() {
    $available = RustFFIManager::isAvailable();
    // Should return false since we don't have Rust library installed
    return $available === false;
});

// Test 7: RustFFI fallback serialization
$totalTests++;
$passedTests += runTest("RustFFI fallback serialization", function() use ($testData) {
    $data = $testData['complex'];
    $serialized = RustFFIManager::serialize($data);
    $deserialized = RustFFIManager::deserialize($serialized);
    
    return $data === $deserialized;
});

// Test 8: RustFFI JSON validation
$totalTests++;
$passedTests += runTest("RustFFI JSON validation", function() {
    $validJson = '{"test": "value"}';
    $invalidJson = '{"test": invalid}';
    
    $valid = RustFFIManager::validateJson($validJson);
    $invalid = RustFFIManager::validateJson($invalidJson);
    
    return $valid === true && $invalid === false;
});

// Test 9: RustFFI hash function
$totalTests++;
$passedTests += runTest("RustFFI hash function", function() {
    $data = "test data for hashing";
    $hash = RustFFIManager::hashData($data);
    
    return is_string($hash) && strlen($hash) > 0;
});

// Test 10: RustFFI performance benchmark
$totalTests++;
$passedTests += runTest("RustFFI performance benchmark", function() {
    $benchmark = RustFFIManager::benchmarkPerformance();
    
    return isset($benchmark['php_time']) && 
           isset($benchmark['rust_time']) && 
           isset($benchmark['rust_available']);
});

// Test 11: AdaptiveSerializer performance mode
$totalTests++;
$passedTests += runTest("AdaptiveSerializer performance mode", function() {
    AdaptiveSerializer::setPerformanceMode('json');
    $stats = AdaptiveSerializer::getStats();
    return $stats['perf_mode'] === 'json';
});

// Test 12: Integration test - large data serialization
$totalTests++;
$passedTests += runTest("Large data serialization performance", function() {
    // Generate large dataset
    $largeData = [];
    for ($i = 0; $i < 1000; $i++) {
        $largeData[] = [
            'id' => $i,
            'name' => "User {$i}",
            'email' => "user{$i}@example.com",
            'data' => array_fill(0, 10, "data_item_{$i}")
        ];
    }
    
    $start = microtime(true);
    $serialized = AdaptiveSerializer::serialize($largeData);
    $deserialized = AdaptiveSerializer::deserialize($serialized);
    $end = microtime(true);
    
    $duration = $end - $start;
    echo sprintf(" (%.3fs)", $duration);
    
    return $largeData === $deserialized && $duration < 1.0; // Should complete in under 1 second
});

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "📊 Phase 1.2 Validation Results:\n";
echo "   Passed: {$passedTests}/{$totalTests} tests\n";
echo "   Success Rate: " . round(($passedTests / $totalTests) * 100, 1) . "%\n\n";

// Display component stats
echo "📈 Component Statistics:\n";
echo "AdaptiveSerializer:\n";
$serializerStats = AdaptiveSerializer::getStats();
foreach ($serializerStats as $key => $value) {
    echo "   • {$key}: {$value}\n";
}

echo "\nRustFFIManager:\n";
$ffiStats = RustFFIManager::getStats();
foreach ($ffiStats as $key => $value) {
    echo "   • {$key}: {$value}\n";
}

echo "\nAvailable Formats: " . implode(', ', AdaptiveSerializer::getAvailableFormats()) . "\n";

if ($passedTests === $totalTests) {
    echo "\n🎉 Phase 1.2 validation PASSED! Ready for Phase 1.3\n";
    exit(0);
} else {
    echo "\n⚠️  Phase 1.2 validation completed with some failures\n";
    exit(1);
}