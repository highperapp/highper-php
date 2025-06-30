#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Phase 1.3 Validation Test
 * 
 * Tests AMPHTTPServerManager and ZeroDowntimeManager implementations
 */

require_once __DIR__ . '/templates/nano/vendor/autoload.php';
require_once __DIR__ . '/core/framework/src/Foundation/AMPHTTPServerManager.php';
require_once __DIR__ . '/core/framework/src/Foundation/ZeroDowntimeManager.php';

use HighPerApp\HighPer\Foundation\AMPHTTPServerManager;
use HighPerApp\HighPer\Foundation\ZeroDowntimeManager;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;

echo "🧪 Phase 1.3 Validation Test - AMPHTTPServerManager & ZeroDowntimeManager\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

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

// Test 1: AMPHTTPServerManager initialization
$totalTests++;
$passedTests += runTest("AMPHTTPServerManager initialization", function() {
    $manager = new AMPHTTPServerManager(['host' => '127.0.0.1', 'port' => 9001]);
    return $manager instanceof AMPHTTPServerManager;
});

// Test 2: Server mode detection
$totalTests++;
$passedTests += runTest("Server mode detection", function() {
    $manager = new AMPHTTPServerManager(['mode' => 'direct']);
    return $manager->getMode() === 'direct';
});

// Test 3: Auto mode detection
$totalTests++;
$passedTests += runTest("Auto mode detection", function() {
    $manager = new AMPHTTPServerManager(['mode' => 'auto']);
    $mode = $manager->getMode();
    return in_array($mode, ['direct', 'proxy']);
});

// Test 4: Server configuration retrieval
$totalTests++;
$passedTests += runTest("Server configuration retrieval", function() {
    $config = ['host' => '0.0.0.0', 'port' => 9002];
    $manager = new AMPHTTPServerManager($config);
    $retrievedConfig = $manager->getConfig();
    
    return $retrievedConfig['host'] === '0.0.0.0' && $retrievedConfig['port'] === 9002;
});

// Test 5: Server stats tracking
$totalTests++;
$passedTests += runTest("Server stats tracking", function() {
    $manager = new AMPHTTPServerManager();
    $stats = $manager->getStats();
    
    return isset($stats['mode']) && 
           isset($stats['server_starts']) && 
           isset($stats['current_status']);
});

// Test 6: Mode switching
$totalTests++;
$passedTests += runTest("Mode switching", function() {
    $manager = new AMPHTTPServerManager(['mode' => 'direct']);
    $originalMode = $manager->getMode();
    
    $manager->switchMode('proxy');
    $newMode = $manager->getMode();
    
    return $originalMode === 'direct' && $newMode === 'proxy';
});

// Test 7: Load optimization
$totalTests++;
$passedTests += runTest("Load optimization", function() {
    $manager = new AMPHTTPServerManager();
    $manager->optimizeForLoad(5000);
    
    $stats = $manager->getStats();
    return $stats['mode'] !== null;
});

// Test 8: ZeroDowntimeManager initialization
$totalTests++;
$passedTests += runTest("ZeroDowntimeManager initialization", function() {
    $manager = new ZeroDowntimeManager();
    return $manager instanceof ZeroDowntimeManager;
});

// Test 9: ZeroDowntime stats tracking
$totalTests++;
$passedTests += runTest("ZeroDowntime stats tracking", function() {
    $manager = new ZeroDowntimeManager();
    $stats = $manager->getStats();
    
    return isset($stats['deployments']) && 
           isset($stats['hot_reloads']) && 
           isset($stats['active_connections']);
});

// Test 10: Hot reload functionality
$totalTests++;
$passedTests += runTest("Hot reload functionality", function() {
    $manager = new ZeroDowntimeManager();
    $result = $manager->initiateHotReload([__FILE__]);
    
    $stats = $manager->getStats();
    return $result && $stats['hot_reloads'] > 0;
});

// Test 11: Graceful deployment simulation
$totalTests++;
$passedTests += runTest("Graceful deployment simulation", function() {
    $manager = new ZeroDowntimeManager(['graceful_timeout' => 1]);
    
    $deploymentSuccess = $manager->initiateGracefulDeployment(function() {
        // Simulate successful deployment
        usleep(100000); // 100ms
        return true;
    });
    
    $stats = $manager->getStats();
    return $deploymentSuccess && $stats['deployments'] > 0;
});

// Test 12: Connection tracking
$totalTests++;
$passedTests += runTest("Connection tracking", function() {
    $manager = new ZeroDowntimeManager();
    
    $initialCount = $manager->getActiveConnectionsCount();
    $manager->addActiveConnection('mock_connection_1');
    $manager->addActiveConnection('mock_connection_2');
    $afterAddCount = $manager->getActiveConnectionsCount();
    
    $manager->removeActiveConnection('mock_connection_1');
    $afterRemoveCount = $manager->getActiveConnectionsCount();
    
    return $initialCount === 0 && 
           $afterAddCount === 2 && 
           $afterRemoveCount === 1;
});

// Test 13: Deployment status tracking
$totalTests++;
$passedTests += runTest("Deployment status tracking", function() {
    $manager = new ZeroDowntimeManager();
    
    $initialStatus = $manager->isDeploymentInProgress();
    
    // Simulate checking during deployment (would be true during actual deployment)
    return $initialStatus === false;
});

// Test 14: Integration test - AMPHP + ZeroDowntime
$totalTests++;
$passedTests += runTest("AMPHP + ZeroDowntime integration", function() {
    $serverManager = new AMPHTTPServerManager(['host' => '127.0.0.1', 'port' => 9003]);
    $zeroDowntime = new ZeroDowntimeManager();
    
    // Test that both managers can coexist and provide stats
    $serverStats = $serverManager->getStats();
    $deploymentStats = $zeroDowntime->getStats();
    
    return isset($serverStats['mode']) && isset($deploymentStats['deployments']);
});

// Test 15: Configuration validation
$totalTests++;
$passedTests += runTest("Configuration validation", function() {
    $customConfig = [
        'host' => '0.0.0.0',
        'port' => 9004,
        'mode' => 'direct',
        'direct_access' => [
            'connection_limit' => 5000,
            'concurrency_limit' => 500
        ]
    ];
    
    $manager = new AMPHTTPServerManager($customConfig);
    $config = $manager->getConfig();
    
    return $config['direct_access']['connection_limit'] === 5000 &&
           $config['direct_access']['concurrency_limit'] === 500;
});

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "📊 Phase 1.3 Validation Results:\n";
echo "   Passed: {$passedTests}/{$totalTests} tests\n";
echo "   Success Rate: " . round(($passedTests / $totalTests) * 100, 1) . "%\n\n";

// Display component stats
echo "📈 Component Statistics:\n";

// Test AMPHTTPServerManager stats
$testServerManager = new AMPHTTPServerManager();
echo "AMPHTTPServerManager:\n";
$serverStats = $testServerManager->getStats();
foreach ($serverStats as $key => $value) {
    if (is_array($value)) {
        echo "   • {$key}: " . json_encode($value) . "\n";
    } else {
        echo "   • {$key}: {$value}\n";
    }
}

// Test ZeroDowntimeManager stats  
$testZeroDowntime = new ZeroDowntimeManager();
echo "\nZeroDowntimeManager:\n";
$deploymentStats = $testZeroDowntime->getStats();
foreach ($deploymentStats as $key => $value) {
    if (is_array($value)) {
        echo "   • {$key}: " . json_encode($value) . "\n";
    } else {
        echo "   • {$key}: {$value}\n";
    }
}

if ($passedTests === $totalTests) {
    echo "\n🎉 Phase 1.3 validation PASSED! Phase 1 implementation complete!\n";
    exit(0);
} else {
    echo "\n⚠️  Phase 1.3 validation completed with some failures\n";
    exit(1);
}