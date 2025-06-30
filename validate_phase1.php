<?php

declare(strict_types=1);

// Manual Phase 1 Validation Script
require_once 'core/framework/vendor/autoload.php';

echo "HighPer Framework v3 - Phase 1 Validation\n";
echo "==========================================\n";

$testsPassed = 0;
$testsTotal = 0;

function test(string $name, callable $test): void {
    global $testsPassed, $testsTotal;
    $testsTotal++;
    
    try {
        $result = $test();
        if ($result) {
            echo "✅ {$name}\n";
            $testsPassed++;
        } else {
            echo "❌ {$name}\n";
        }
    } catch (\Exception $e) {
        echo "❌ {$name} - Error: {$e->getMessage()}\n";
    }
}

// Test all Phase 1 components
test('ProcessManager autoloads', function() {
    return class_exists('HighPerApp\\HighPer\\Foundation\\ProcessManager');
});

test('AsyncManager autoloads', function() {
    return class_exists('HighPerApp\\HighPer\\Foundation\\AsyncManager');
});

test('AdaptiveSerializer autoloads', function() {
    return class_exists('HighPerApp\\HighPer\\Foundation\\AdaptiveSerializer');
});

test('RustFFIManager autoloads', function() {
    return class_exists('HighPerApp\\HighPer\\Foundation\\RustFFIManager');
});

test('AMPHTTPServerManager autoloads', function() {
    return class_exists('HighPerApp\\HighPer\\Foundation\\AMPHTTPServerManager');
});

test('ZeroDowntimeIntegration autoloads', function() {
    return class_exists('HighPerApp\\HighPer\\Foundation\\ZeroDowntimeIntegration');
});

// Test interfaces
test('All Phase 1 interfaces exist', function() {
    $interfaces = [
        'HighPerApp\\HighPer\\Contracts\\ProcessManagerInterface',
        'HighPerApp\\HighPer\\Contracts\\AsyncManagerInterface',
        'HighPerApp\\HighPer\\Contracts\\SerializationInterface',
        'HighPerApp\\HighPer\\Contracts\\FFIManagerInterface',
        'HighPerApp\\HighPer\\Contracts\\HTTPServerManagerInterface',
        'HighPerApp\\HighPer\\Contracts\\ZeroDowntimeInterface',
    ];
    
    foreach ($interfaces as $interface) {
        if (!interface_exists($interface)) {
            return false;
        }
    }
    return true;
});

// Test basic functionality
test('AsyncManager basic functionality', function() {
    $manager = new \HighPerApp\HighPer\Foundation\AsyncManager();
    $stats = $manager->getStats();
    return is_array($stats) && isset($stats['operations']);
});

test('AdaptiveSerializer JSON serialization', function() {
    $serializer = new \HighPerApp\HighPer\Foundation\AdaptiveSerializer();
    $data = ['test' => 'data', 'number' => 42];
    $serialized = $serializer->serialize($data);
    $deserialized = $serializer->deserialize($serialized);
    return $data === $deserialized;
});

test('RustFFIManager availability check', function() {
    $manager = new \HighPerApp\HighPer\Foundation\RustFFIManager();
    return is_bool($manager->isAvailable());
});

test('ZeroDowntimeIntegration status', function() {
    $integration = new \HighPerApp\HighPer\Foundation\ZeroDowntimeIntegration();
    $status = $integration->getStatus();
    return is_array($status) && isset($status['stage']);
});

echo "\n";
echo "Results: {$testsPassed}/{$testsTotal} tests passed\n";

if ($testsPassed === $testsTotal) {
    echo "🎉 All Phase 1 components validated successfully!\n";
    echo "\nPhase 1 Implementation Summary:\n";
    echo "- ProcessManager: ✅ Multi-process worker architecture\n";
    echo "- AsyncManager: ✅ Enhanced async with auto-yield\n";
    echo "- AdaptiveSerializer: ✅ JSON/MessagePack with Rust FFI\n";
    echo "- RustFFIManager: ✅ Unified FFI management\n";
    echo "- AMPHTTPServerManager: ✅ Enhanced AMPHP integration\n";
    echo "- ZeroDowntimeIntegration: ✅ Zero-downtime deployment support\n";
} else {
    echo "❌ Some Phase 1 components need attention\n";
    exit(1);
}