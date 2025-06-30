<?php

declare(strict_types=1);

/**
 * HighPer Framework v3 - Complete Test Suite Runner
 * 
 * Runs all unit tests and integration tests for all components:
 * - Framework core
 * - Blueprint template  
 * - Nano template
 * - All standalone libraries
 */

echo "🚀 HighPer Framework v3 - Complete Test Suite\n";
echo "=============================================\n\n";

$testResults = [];
$totalTests = 0;
$totalPassed = 0;

// Test execution function
function runTest(string $testFile, string $component): array
{
    if (!file_exists($testFile)) {
        echo "⚠️ Test file not found: {$testFile}\n";
        return ['total' => 0, 'passed' => 0, 'component' => $component, 'status' => 'missing'];
    }
    
    echo "🧪 Running {$component} tests...\n";
    
    ob_start();
    $exitCode = 0;
    
    try {
        include $testFile;
    } catch (Exception $e) {
        echo "❌ Error running {$component}: " . $e->getMessage() . "\n";
        $exitCode = 1;
    }
    
    $output = ob_get_clean();
    
    // Parse results from output (looking for success rate)
    preg_match('/Success Rate: (\d+(?:\.\d+)?)%/', $output, $matches);
    $successRate = $matches[1] ?? 0;
    
    preg_match('/Total Tests: (\d+)/', $output, $matches);
    $total = (int)($matches[1] ?? 0);
    
    preg_match('/Passed: (\d+)/', $output, $matches);  
    $passed = (int)($matches[1] ?? 0);
    
    $status = ($successRate >= 80) ? 'passed' : 'failed';
    
    echo $output;
    echo "\n" . str_repeat("=", 50) . "\n\n";
    
    return [
        'total' => $total,
        'passed' => $passed,
        'success_rate' => $successRate,
        'component' => $component,
        'status' => $status
    ];
}

echo "📋 Running Framework Tests...\n";
echo str_repeat("─", 30) . "\n";

// Framework Tests
$frameworkTests = [
    '/core/framework/tests/Unit/Phase1ComponentsTest.php' => 'Framework Phase 1 Components',
    '/core/framework/tests/Unit/Phase2And3ComponentsTest.php' => 'Framework Phase 2&3 Components',
    '/core/framework/tests/Integration/FrameworkIntegrationTest.php' => 'Framework Integration',
    '/core/framework/tests/Integration/MemoryLeakDetectionTest.php' => 'Framework Memory Leak Detection'
];

foreach ($frameworkTests as $testFile => $component) {
    $result = runTest(__DIR__ . $testFile, $component);
    $testResults[] = $result;
    $totalTests += $result['total'];
    $totalPassed += $result['passed'];
}

echo "📋 Running Template Tests...\n";
echo str_repeat("─", 30) . "\n";

// Template Tests  
$templateTests = [
    '/templates/blueprint/tests/Unit/EnterpriseBootstrapTest.php' => 'Blueprint Enterprise Bootstrap',
    '/templates/blueprint/tests/Integration/BlueprintIntegrationTest.php' => 'Blueprint Integration',
    '/templates/nano/tests/Unit/MinimalBootstrapTest.php' => 'Nano Minimal Bootstrap', 
    '/templates/nano/tests/Integration/NanoIntegrationTest.php' => 'Nano Integration'
];

foreach ($templateTests as $testFile => $component) {
    $result = runTest(__DIR__ . $testFile, $component);
    $testResults[] = $result;
    $totalTests += $result['total'];
    $totalPassed += $result['passed'];
}

echo "📋 Running Library Tests...\n";
echo str_repeat("─", 30) . "\n";

// Library Tests (example with DI Container)
$libraryTests = [
    '/libraries/di-container/tests/Unit/ContainerTest.php' => 'DI Container Unit',
    '/libraries/di-container/tests/Integration/DIContainerIntegrationTest.php' => 'DI Container Integration',
    // Add other libraries as they're implemented...
];

foreach ($libraryTests as $testFile => $component) {
    $result = runTest(__DIR__ . $testFile, $component);
    $testResults[] = $result;
    $totalTests += $result['total'];
    $totalPassed += $result['passed'];
}

// Generate Final Report
echo "🏆 FINAL TEST SUITE REPORT\n";
echo "=========================\n\n";

$overallSuccessRate = $totalTests > 0 ? round(($totalPassed / $totalTests) * 100, 1) : 0;

echo "📊 Overall Summary:\n";
echo "  • Total Components Tested: " . count($testResults) . "\n";
echo "  • Total Tests Executed: {$totalTests}\n";
echo "  • Total Tests Passed: {$totalPassed}\n";
echo "  • Total Tests Failed: " . ($totalTests - $totalPassed) . "\n";
echo "  • Overall Success Rate: {$overallSuccessRate}%\n\n";

echo "📋 Component Results:\n";
foreach ($testResults as $result) {
    $status = $result['status'] === 'passed' ? '✅' : ($result['status'] === 'missing' ? '⚠️' : '❌');
    $rate = $result['success_rate'] ?? 0;
    echo "  {$status} {$result['component']}: {$result['passed']}/{$result['total']} ({$rate}%)\n";
}

echo "\n🎯 Test Suite Assessment:\n";
if ($overallSuccessRate >= 90) {
    echo "  🎉 EXCELLENT - Test suite shows exceptional quality\n";
    $exitCode = 0;
} elseif ($overallSuccessRate >= 80) {
    echo "  ✅ GOOD - Test suite shows good quality\n";
    $exitCode = 0;
} elseif ($overallSuccessRate >= 70) {
    echo "  ⚠️ ACCEPTABLE - Test suite needs improvement\n";
    $exitCode = 1;
} else {
    echo "  ❌ NEEDS WORK - Test suite requires significant improvement\n";
    $exitCode = 1;
}

echo "\n📦 Repository Commit Readiness:\n";
foreach ($testResults as $result) {
    if ($result['status'] === 'passed' && $result['success_rate'] >= 80) {
        echo "  ✅ {$result['component']} - Ready for commit\n";
    } elseif ($result['status'] === 'missing') {
        echo "  ⚠️ {$result['component']} - Tests missing\n";
    } else {
        echo "  ❌ {$result['component']} - Needs fixes before commit\n";
    }
}

echo "\n🚀 Next Steps:\n";
echo "  1. Review failed tests and fix issues\n";
echo "  2. Commit passing components to respective repositories\n";
echo "  3. Set up CI/CD pipelines for automated testing\n";
echo "  4. Create deployment documentation\n";

exit($exitCode);