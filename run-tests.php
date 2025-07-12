<?php

declare(strict_types=1);

/**
 * HighPer Framework Test Runner
 * 
 * Comprehensive test runner for all test suites including unit, integration,
 * performance, and concurrency tests.
 */

require_once __DIR__ . '/vendor/autoload.php';

class TestRunner
{
    private array $testSuites = [
        'unit' => [
            'description' => 'Unit Tests - Core component functionality',
            'path' => 'tests/Unit',
            'pattern' => '*Test.php'
        ],
        'integration' => [
            'description' => 'Integration Tests - Cross-component functionality',
            'path' => 'tests/Integration',
            'pattern' => '*Test.php'
        ],
        'performance' => [
            'description' => 'Performance Tests - C10M and optimization validation',
            'path' => 'tests/Performance',
            'pattern' => '*Test.php'
        ],
        'concurrency' => [
            'description' => 'Concurrency Tests - Multi-process safety',
            'path' => 'tests/Concurrency',
            'pattern' => '*Test.php'
        ],
        'reliability' => [
            'description' => 'Reliability Tests - Five nines and fault tolerance',
            'path' => 'tests/Reliability',
            'pattern' => '*Test.php'
        ],
        'observability' => [
            'description' => 'Observability Tests - Monitoring and metrics',
            'path' => 'tests/Observability',
            'pattern' => '*Test.php'
        ]
    ];

    private array $options = [];

    public function __construct(array $argv)
    {
        $this->parseArguments($argv);
    }

    public function run(): int
    {
        $this->printHeader();
        
        if (isset($this->options['help'])) {
            $this->printHelp();
            return 0;
        }

        if (isset($this->options['system-check'])) {
            return $this->runSystemCheck();
        }

        $suitesToRun = $this->options['suite'] ?? array_keys($this->testSuites);
        if (is_string($suitesToRun)) {
            $suitesToRun = [$suitesToRun];
        }

        $totalResults = ['passed' => 0, 'failed' => 0, 'skipped' => 0];
        $overallSuccess = true;

        foreach ($suitesToRun as $suite) {
            if (!isset($this->testSuites[$suite])) {
                echo "❌ Unknown test suite: {$suite}\n";
                $overallSuccess = false;
                continue;
            }

            $results = $this->runTestSuite($suite);
            $totalResults['passed'] += $results['passed'];
            $totalResults['failed'] += $results['failed'];
            $totalResults['skipped'] += $results['skipped'];

            if ($results['failed'] > 0) {
                $overallSuccess = false;
            }
        }

        $this->printSummary($totalResults, $overallSuccess);
        
        return $overallSuccess ? 0 : 1;
    }

    private function parseArguments(array $argv): void
    {
        for ($i = 1; $i < count($argv); $i++) {
            $arg = $argv[$i];

            if ($arg === '--help' || $arg === '-h') {
                $this->options['help'] = true;
            } elseif ($arg === '--system-check') {
                $this->options['system-check'] = true;
            } elseif ($arg === '--verbose' || $arg === '-v') {
                $this->options['verbose'] = true;
            } elseif ($arg === '--coverage') {
                $this->options['coverage'] = true;
            } elseif (strpos($arg, '--suite=') === 0) {
                $suite = substr($arg, 8);
                $this->options['suite'] = explode(',', $suite);
            } elseif (strpos($arg, '--filter=') === 0) {
                $this->options['filter'] = substr($arg, 9);
            }
        }
    }

    private function printHeader(): void
    {
        echo "┌────────────────────────────────────────────────────────────────────┐\n";
        echo "│                    HighPer Framework Test Suite                │\n";
        echo "│              Hybrid Multi-Process + Async Architecture            │\n";
        echo "└────────────────────────────────────────────────────────────────────┘\n\n";
    }

    private function printHelp(): void
    {
        echo "Usage: php run-tests.php [options]\n\n";
        echo "Options:\n";
        echo "  --help, -h          Show this help message\n";
        echo "  --system-check      Run system capability check\n";
        echo "  --verbose, -v       Enable verbose output\n";
        echo "  --coverage          Generate code coverage report\n";
        echo "  --suite=SUITE       Run specific test suite(s), comma-separated\n";
        echo "  --filter=PATTERN    Run tests matching pattern\n\n";
        
        echo "Available test suites:\n";
        foreach ($this->testSuites as $name => $suite) {
            echo "  " . str_pad($name, 12) . " {$suite['description']}\n";
        }
        
        echo "\nExamples:\n";
        echo "  php run-tests.php                           # Run all tests\n";
        echo "  php run-tests.php --suite=unit              # Run only unit tests\n";
        echo "  php run-tests.php --suite=unit,integration  # Run unit and integration tests\n";
        echo "  php run-tests.php --filter=ProcessManager   # Run tests matching ProcessManager\n";
        echo "  php run-tests.php --system-check            # Check system capabilities\n";
    }

    private function runSystemCheck(): int
    {
        echo "🔍 System Capability Check\n";
        echo "══════════════════════════════════════════════════════════════════════\n\n";

        $capabilities = [
            'PHP Version' => PHP_VERSION,
            'CPU Cores' => (int) shell_exec('nproc') ?: 'Unknown',
            'Memory Limit' => ini_get('memory_limit'),
            'Max Execution Time' => ini_get('max_execution_time'),
        ];

        $extensions = [
            'pcntl' => function_exists('pcntl_fork'),
            'posix' => function_exists('posix_kill'),
            'uv' => extension_loaded('uv'),
            'ffi' => extension_loaded('ffi'),
            'opcache' => extension_loaded('opcache'),
            'json' => extension_loaded('json'),
        ];

        $classes = [
            'RevoltPHP' => class_exists('\\Revolt\\EventLoop'),
            'AMPHP Socket' => class_exists('\\Amp\\Socket\\Socket'),
            'ProcessManager' => class_exists('\\HighPerApp\\HighPer\\Foundation\\ProcessManager'),
            'HybridEventLoop' => class_exists('\\HighPerApp\\HighPer\\Foundation\\HybridEventLoop'),
            'ArchitectureValidator' => class_exists('\\HighPerApp\\HighPer\\Foundation\\ArchitectureValidator'),
        ];

        echo "📊 System Information:\n";
        foreach ($capabilities as $name => $value) {
            echo "   {$name}: {$value}\n";
        }

        echo "\n🔧 PHP Extensions:\n";
        foreach ($extensions as $name => $available) {
            $status = $available ? '✅ Available' : '❌ Missing';
            echo "   {$name}: {$status}\n";
        }

        echo "\n📦 Framework Classes:\n";
        foreach ($classes as $name => $available) {
            $status = $available ? '✅ Available' : '❌ Missing';
            echo "   {$name}: {$status}\n";
        }

        $criticalMissing = !$extensions['pcntl'] || !$extensions['posix'] || !$classes['ProcessManager'];
        
        if ($criticalMissing) {
            echo "\n❌ Critical components missing! Framework may not function properly.\n";
            return 1;
        }

        echo "\n✅ System check passed! Framework ready for testing.\n";
        return 0;
    }

    private function runTestSuite(string $suiteName): array
    {
        $suite = $this->testSuites[$suiteName];
        
        echo "🧪 Running {$suite['description']}\n";
        echo "────────────────────────────────────────────────────────────────\n";

        $testPath = __DIR__ . '/' . $suite['path'];
        
        if (!is_dir($testPath)) {
            echo "⚠️  Test directory not found: {$testPath}\n";
            return ['passed' => 0, 'failed' => 1, 'skipped' => 0];
        }

        $testFiles = glob($testPath . '/' . $suite['pattern']);
        
        if (empty($testFiles)) {
            echo "⚠️  No test files found in: {$testPath}\n";
            return ['passed' => 0, 'failed' => 0, 'skipped' => 1];
        }

        $phpunitArgs = $this->buildPhpunitArgs($testFiles);
        $command = "vendor/bin/phpunit {$phpunitArgs}";
        
        if (isset($this->options['verbose'])) {
            echo "Command: {$command}\n";
        }

        $startTime = microtime(true);
        
        ob_start();
        $returnCode = 0;
        passthru($command, $returnCode);
        $output = ob_get_clean();
        
        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);

        echo $output;
        
        $results = $this->parsePhpunitOutput($output);
        
        if ($returnCode === 0) {
            echo "✅ {$suiteName} tests completed in {$duration}s\n";
        } else {
            echo "❌ {$suiteName} tests failed in {$duration}s\n";
        }
        
        echo "\n";
        
        return $results;
    }

    private function buildPhpunitArgs(array $testFiles): string
    {
        $args = [];
        
        if (isset($this->options['coverage'])) {
            $args[] = '--coverage-html coverage/';
            $args[] = '--coverage-text';
        }
        
        if (isset($this->options['verbose'])) {
            $args[] = '--verbose';
        }
        
        if (isset($this->options['filter'])) {
            $args[] = '--filter=' . escapeshellarg($this->options['filter']);
        }
        
        $args[] = '--colors=always';
        $args[] = '--testdox';
        
        // Add test files
        $args = array_merge($args, array_map('escapeshellarg', $testFiles));
        
        return implode(' ', $args);
    }

    private function parsePhpunitOutput(string $output): array
    {
        $results = ['passed' => 0, 'failed' => 0, 'skipped' => 0];
        
        // Parse PHPUnit output for test results
        if (preg_match('/OK \((\d+) tests?, (\d+) assertions?\)/', $output, $matches)) {
            $results['passed'] = (int) $matches[1];
        } elseif (preg_match('/Tests: (\d+), Assertions: (\d+), Failures: (\d+)/', $output, $matches)) {
            $results['passed'] = (int) $matches[1] - (int) $matches[3];
            $results['failed'] = (int) $matches[3];
        } elseif (preg_match('/Tests: (\d+), Assertions: (\d+), Skipped: (\d+)/', $output, $matches)) {
            $results['passed'] = (int) $matches[1] - (int) $matches[3];
            $results['skipped'] = (int) $matches[3];
        }
        
        return $results;
    }

    private function printSummary(array $results, bool $success): void
    {
        echo "📋 Test Summary\n";
        echo "══════════════════════════════════════════════════════════════════════\n";
        
        $total = $results['passed'] + $results['failed'] + $results['skipped'];
        
        echo "Total Tests: {$total}\n";
        echo "✅ Passed: {$results['passed']}\n";
        echo "❌ Failed: {$results['failed']}\n";
        echo "⏭️  Skipped: {$results['skipped']}\n";
        
        if ($results['passed'] > 0) {
            $passRate = round(($results['passed'] / $total) * 100, 1);
            echo "📊 Pass Rate: {$passRate}%\n";
        }
        
        echo "\n";
        
        if ($success) {
            echo "🎉 All tests passed! HighPer Framework is ready for production.\n";
        } else {
            echo "💥 Some tests failed. Please review the output above.\n";
        }
    }
}

// Run the test suite
$runner = new TestRunner($argv);
exit($runner->run());