#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Simple Performance Test for HighPer Framework v1 vs Workerman
 */

class SimplePerformanceTest
{
    private string $wrk2Path;
    private array $results = [];
    
    // Progressive tests to find maximum RPS
    private array $tests = [
        ['connections' => 100, 'threads' => 4, 'duration' => '30s', 'rate' => '1000'],
        ['connections' => 500, 'threads' => 8, 'duration' => '30s', 'rate' => '5000'],
        ['connections' => 1000, 'threads' => 12, 'duration' => '30s', 'rate' => '10000'],
        ['connections' => 2000, 'threads' => 16, 'duration' => '30s', 'rate' => '25000'],
        ['connections' => 5000, 'threads' => 20, 'duration' => '30s', 'rate' => '50000'],
    ];

    public function __construct()
    {
        $this->wrk2Path = trim(shell_exec('which wrk2 2>/dev/null') ?: '');
        if (!$this->wrk2Path) {
            throw new RuntimeException("wrk2 not found");
        }
    }

    public function runTest(): void
    {
        echo "\n🚀 Framework Performance Comparison\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        $frameworks = [
            'Blueprint' => ['script' => 'test-blueprint.php', 'port' => 8081],
            'Nano' => ['script' => 'test-nano.php', 'port' => 8082],
        ];
        
        foreach ($frameworks as $name => $config) {
            echo "🎯 Testing HighPer {$name}:\n";
            echo str_repeat("─", 60) . "\n";
            
            $this->results[$name] = $this->testFramework($name, $config['script'], $config['port']);
            echo "\n";
        }
        
        $this->testWorkerman();
        $this->generateReport();
    }

    private function testFramework(string $name, string $script, int $port): array
    {
        $results = [];
        
        // Start PHP built-in server
        echo "🚀 Starting {$name} server on port {$port}...\n";
        $cmd = "php -S localhost:{$port} {$script} > /tmp/{$name}_server.log 2>&1 &";
        shell_exec($cmd);
        
        sleep(2); // Give server time to start
        
        // Test if server responds
        $testResponse = @file_get_contents("http://localhost:{$port}/");
        if (!$testResponse) {
            echo "❌ Server failed to start\n";
            return ['status' => 'FAILED'];
        }
        
        echo "✅ Server started successfully\n";
        
        $maxRps = 0;
        foreach ($this->tests as $test) {
            echo "⚡ Testing {$test['rate']} RPS with {$test['connections']} connections...\n";
            
            $cmd = sprintf(
                "%s -t%d -c%d -d%s -R%s --latency http://localhost:%d/ 2>&1",
                $this->wrk2Path,
                $test['threads'],
                $test['connections'],
                $test['duration'],
                $test['rate'],
                $port
            );
            
            $output = shell_exec($cmd);
            
            if ($output && preg_match('/Requests\/sec:\s+([\d.]+)/', $output, $matches)) {
                $rps = (float) $matches[1];
                $maxRps = max($maxRps, $rps);
                echo "   ✅ Achieved: " . number_format($rps) . " RPS\n";
                
                if (preg_match('/Latency\s+([\d.]+\w+)/', $output, $latencyMatches)) {
                    echo "   📊 Latency: {$latencyMatches[1]}\n";
                }
            } else {
                echo "   ❌ Test failed\n";
                break;
            }
            
            sleep(1);
        }
        
        // Kill server
        $pid = trim(shell_exec("pgrep -f 'php -S localhost:{$port}'"));
        if ($pid) {
            shell_exec("kill {$pid}");
        }
        
        return ['max_rps' => $maxRps, 'status' => 'COMPLETED'];
    }

    private function testWorkerman(): void
    {
        echo "🎯 Testing Workerman:\n";
        echo str_repeat("─", 60) . "\n";
        
        echo "🚀 Starting Workerman server on port 8080...\n";
        $cmd = "php test-workerman.php > /tmp/workerman_server.log 2>&1 &";
        shell_exec($cmd);
        
        sleep(3); // Workerman needs more time to start
        
        // Test if server responds
        $testResponse = @file_get_contents("http://localhost:8080/");
        if (!$testResponse) {
            echo "❌ Workerman server failed to start\n";
            $this->results['Workerman'] = ['status' => 'FAILED'];
            return;
        }
        
        echo "✅ Workerman server started successfully\n";
        
        $maxRps = 0;
        foreach ($this->tests as $test) {
            echo "⚡ Testing {$test['rate']} RPS with {$test['connections']} connections...\n";
            
            $cmd = sprintf(
                "%s -t%d -c%d -d%s -R%s --latency http://localhost:8080/ 2>&1",
                $this->wrk2Path,
                $test['threads'],
                $test['connections'],
                $test['duration'],
                $test['rate']
            );
            
            $output = shell_exec($cmd);
            
            if ($output && preg_match('/Requests\/sec:\s+([\d.]+)/', $output, $matches)) {
                $rps = (float) $matches[1];
                $maxRps = max($maxRps, $rps);
                echo "   ✅ Achieved: " . number_format($rps) . " RPS\n";
            } else {
                echo "   ❌ Test failed\n";
                break;
            }
            
            sleep(1);
        }
        
        // Kill Workerman processes
        shell_exec("pkill -f 'test-workerman.php'");
        
        $this->results['Workerman'] = ['max_rps' => $maxRps, 'status' => 'COMPLETED'];
    }

    private function generateReport(): void
    {
        echo "\n\n🏆 PERFORMANCE COMPARISON RESULTS\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        
        printf("%-15s %-15s %-20s\n", "Framework", "Max RPS", "Status");
        echo str_repeat("─", 50) . "\n";
        
        $sortedResults = $this->results;
        uasort($sortedResults, fn($a, $b) => ($b['max_rps'] ?? 0) <=> ($a['max_rps'] ?? 0));
        
        foreach ($sortedResults as $framework => $result) {
            if ($result['status'] === 'FAILED') {
                printf("%-15s %-15s %-20s\n", $framework, "FAILED", "❌ Server Error");
            } else {
                $rps = number_format($result['max_rps']);
                $status = $result['max_rps'] >= 100000 ? "✅ 100K+ Target" : "⚠️  Below 100K";
                printf("%-15s %-15s %-20s\n", $framework, $rps, $status);
            }
        }
        
        echo "\n📊 ANALYSIS:\n";
        $successful = array_filter($this->results, fn($r) => $r['status'] === 'COMPLETED');
        
        if (count($successful) > 0) {
            $best = array_keys($successful)[0];
            $bestRps = max(array_column($successful, 'max_rps'));
            echo "🏆 Best Performance: {$best} with " . number_format($bestRps) . " RPS\n";
            
            if ($bestRps >= 100000) {
                echo "✅ 100K+ RPS target ACHIEVED!\n";
            } else {
                echo "❌ 100K+ RPS target not reached\n";
                echo "💡 Suggestions: Use Rust FFI components, optimize system settings, or use dedicated hardware\n";
            }
        } else {
            echo "❌ All frameworks failed to start properly\n";
        }
    }
}

// Run the test
if (php_sapi_name() === 'cli') {
    try {
        $test = new SimplePerformanceTest();
        $test->runTest();
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}