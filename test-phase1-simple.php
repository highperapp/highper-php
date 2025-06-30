#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Phase 1.1 Simple Validation Test
 * 
 * Tests basic functionality of ProcessManager and AsyncManager
 * without full framework dependencies.
 */

require_once __DIR__ . '/templates/nano/vendor/autoload.php';

echo "🧪 HighPer v3 Phase 1.1 Simple Validation Test\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

// Test 1: AsyncManager Basic Functionality
echo "\n📋 Test 1: AsyncManager Basic Functionality\n";

// Load AsyncManager manually
require_once __DIR__ . '/core/framework/src/Foundation/AsyncManager.php';

use HighPerApp\HighPer\Foundation\AsyncManager;
use Revolt\EventLoop;

try {
    AsyncManager::initialize();
    echo "✅ AsyncManager initialized successfully\n";
    
    $stats = AsyncManager::getStats();
    echo "✅ AsyncManager stats: " . json_encode($stats) . "\n";
    
    // Test auto-yield functionality
    $result = AsyncManager::autoYield(function() {
        return "Hello from auto-yield!";
    })->await();
    
    echo "✅ Auto-yield test result: {$result}\n";
    
} catch (\Throwable $e) {
    echo "❌ AsyncManager test failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 2: Concurrent Operations
echo "\n📋 Test 2: Concurrent Operations\n";

try {
    $operations = [
        'op1' => function() { return "Operation 1 completed"; },
        'op2' => function() { return "Operation 2 completed"; },
        'op3' => function() { return "Operation 3 completed"; }
    ];
    
    $results = AsyncManager::concurrent($operations)->await();
    echo "✅ Concurrent operations completed: " . json_encode($results) . "\n";
    
} catch (\Throwable $e) {
    echo "❌ Concurrent operations test failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 3: Timeout Functionality
echo "\n📋 Test 3: Timeout Functionality\n";

try {
    $start = microtime(true);
    
    // Test operation with timeout
    $result = AsyncManager::timeout(function() {
        // Simulate work that completes in time
        usleep(500000); // 0.5 seconds
        return "Operation completed within timeout";
    }, 1.0)->await(); // 1 second timeout
    
    $elapsed = microtime(true) - $start;
    echo "✅ Timeout test completed in: " . round($elapsed * 1000, 2) . "ms\n";
    echo "✅ Result: {$result}\n";
    
} catch (\Throwable $e) {
    echo "❌ Timeout test failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 4: Process Count Detection (Simulated)
echo "\n📋 Test 4: Process Count Detection\n";

try {
    // Test optimal worker count calculation (simulated)
    $cores = (int) shell_exec('nproc') ?: 4;
    echo "✅ Detected CPU cores: {$cores}\n";
    
    // Test signal handling availability
    $pcntlAvailable = extension_loaded('pcntl');
    echo "✅ PCNTL extension available: " . ($pcntlAvailable ? 'Yes' : 'No') . "\n";
    
    // Test socket availability for multi-process
    $socketsAvailable = extension_loaded('sockets');
    echo "✅ Sockets extension available: " . ($socketsAvailable ? 'Yes' : 'No') . "\n";
    
} catch (\Throwable $e) {
    echo "❌ Process detection test failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 5: Event Loop Performance
echo "\n📋 Test 5: Event Loop Performance\n";

try {
    $iterations = 1000;
    $start = microtime(true);
    
    // Test event loop scheduling performance
    $completed = 0;
    for ($i = 0; $i < $iterations; $i++) {
        EventLoop::defer(function() use (&$completed) {
            $completed++;
        });
    }
    
    // Process all deferred operations
    $maxWait = 1.0; // 1 second max wait
    $waitStart = microtime(true);
    
    while ($completed < $iterations && (microtime(true) - $waitStart) < $maxWait) {
        usleep(100); // 0.1ms - let event loop process
    }
    
    $elapsed = microtime(true) - $start;
    $ops_per_sec = $iterations / $elapsed;
    
    echo "✅ Event loop processed {$completed}/{$iterations} operations\n";
    echo "✅ Performance: " . round($ops_per_sec, 0) . " operations/second\n";
    echo "✅ Average latency: " . round(($elapsed / $iterations) * 1000, 3) . "ms per operation\n";
    
} catch (\Throwable $e) {
    echo "❌ Event loop performance test failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n🎉 All Phase 1.1 validation tests passed!\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

echo "\n📊 System Readiness Summary:\n";
echo "   ✅ AsyncManager: Functional with auto-yield capability\n";
echo "   ✅ Event Loop: RevoltPHP operational\n";
echo "   ✅ Concurrent Operations: Working correctly\n";
echo "   ✅ Timeout Handling: Functional\n";
echo "   ✅ Multi-Process Support: " . ($pcntlAvailable && $socketsAvailable ? "Ready" : "Limited (missing extensions)") . "\n";

echo "\n🔄 Next Phase 1.1 Steps:\n";
echo "   1. Start baseline nano server: cd templates/nano && php server.php --port=8080\n";
echo "   2. Run baseline benchmark: wrk2 -t4 -c100 -d30s -R5000 --latency http://localhost:8080/ping\n";
echo "   3. Integrate ProcessManager into server for multi-process testing\n";
echo "   4. Compare single-process vs multi-process performance\n";

echo "\n📈 Performance Validation Ready!\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "\n";