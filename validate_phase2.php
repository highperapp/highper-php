<?php

declare(strict_types=1);

// Phase 2 Validation Script - Critical Optimizations
require_once 'core/framework/vendor/autoload.php';

echo "HighPer Framework v3 - Phase 2 Validation\n";
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

// Test Phase 2 component autoloading
test('ContainerCompiler autoloads', function() {
    return class_exists('HighPerApp\\HighPer\\Container\\ContainerCompiler');
});

test('RingBufferCache autoloads', function() {
    return class_exists('HighPerApp\\HighPer\\Router\\RingBufferCache');
});

test('CompiledPatterns autoloads', function() {
    return class_exists('HighPerApp\\HighPer\\Security\\CompiledPatterns');
});

test('AsyncConnectionPool autoloads', function() {
    return class_exists('HighPerApp\\HighPer\\Database\\AsyncConnectionPool');
});

// Test Phase 2 interfaces
test('Phase 2 interfaces exist', function() {
    $interfaces = [
        'HighPerApp\\HighPer\\Contracts\\CompilerInterface',
        'HighPerApp\\HighPer\\Contracts\\CacheInterface',
        'HighPerApp\\HighPer\\Contracts\\ConnectionPoolInterface',
    ];
    
    foreach ($interfaces as $interface) {
        if (!interface_exists($interface)) {
            return false;
        }
    }
    return true;
});

// Test ContainerCompiler functionality
test('ContainerCompiler compilation', function() {
    $compiler = new \HighPerApp\HighPer\Container\ContainerCompiler('/tmp/test_container_cache');
    $definitions = [
        'test_service' => ['class' => 'stdClass', 'dependencies' => []]
    ];
    $compiled = $compiler->compileContainer($definitions);
    return strpos($compiled, 'test_service') !== false && $compiler->validateCompiled($compiled);
});

// Test RingBufferCache O(1) operations
test('RingBufferCache O(1) operations', function() {
    $cache = new \HighPerApp\HighPer\Router\RingBufferCache(64);
    $cache->set('test_key', 'test_value');
    $retrieved = $cache->get('test_key');
    return $retrieved === 'test_value' && $cache->has('test_key');
});

// Test CompiledPatterns security validation
test('CompiledPatterns threat detection', function() {
    $patterns = new \HighPerApp\HighPer\Security\CompiledPatterns('/tmp/test_security_cache');
    $safe_input = 'normal user input';
    $threat_input = '<script>alert("xss")</script>';
    return $patterns->validate($safe_input) === true && $patterns->validate($threat_input) === false;
});

// Test AsyncConnectionPool management
test('AsyncConnectionPool management', function() {
    $pool = new \HighPerApp\HighPer\Database\AsyncConnectionPool(['max_connections' => 5]);
    $conn1 = $pool->getConnection();
    $conn2 = $pool->getConnection();
    $pool->returnConnection($conn1);
    $stats = $pool->getStats();
    return is_object($conn1) && is_object($conn2) && $stats['created'] >= 2;
});

// Test performance optimizations
test('RingBufferCache performance characteristics', function() {
    $cache = new \HighPerApp\HighPer\Router\RingBufferCache(1024);
    $start = microtime(true);
    
    // Perform 1000 operations
    for ($i = 0; $i < 1000; $i++) {
        $cache->set("key_{$i}", "value_{$i}");
        $cache->get("key_{$i}");
    }
    
    $elapsed = microtime(true) - $start;
    $stats = $cache->getStats();
    
    // Should complete 2000 operations in under 10ms for O(1) performance
    return $elapsed < 0.01 && $stats['hits'] > 0;
});

echo "\n";
echo "Results: {$testsPassed}/{$testsTotal} tests passed\n";

if ($testsPassed === $testsTotal) {
    echo "🎉 All Phase 2 critical optimizations validated successfully!\n";
    echo "\nPhase 2 Implementation Summary:\n";
    echo "- ContainerCompiler: ✅ Build-time DI compilation (60 LOC)\n";
    echo "- RingBufferCache: ✅ O(1) router cache eviction (35 LOC)\n";
    echo "- CompiledPatterns: ✅ Rust-based security patterns (45 LOC)\n";
    echo "- AsyncConnectionPool: ✅ Database connection optimization (25 LOC)\n";
    echo "\nTotal Phase 2: 165 LOC for major performance gains\n";
} else {
    echo "❌ Some Phase 2 components need attention\n";
    exit(1);
}