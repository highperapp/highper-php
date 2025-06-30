#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * HighPer Framework v1 vs Workerman Performance Comparison
 * 
 * Targets 100K+ RPS as requested
 * Uses wrk2 for accurate performance testing
 */

class FrameworkPerformanceComparison
{
    private string $wrk2Path;
    private array $results = [];
    
    // Progressive concurrency tests targeting 100K+ RPS
    private array $concurrencyTests = [
        ['connections' => 100, 'threads' => 4, 'duration' => '30s', 'rate' => '1000', 'label' => 'Baseline - 1K RPS'],
        ['connections' => 500, 'threads' => 8, 'duration' => '30s', 'rate' => '5000', 'label' => 'Light Load - 5K RPS'], 
        ['connections' => 1000, 'threads' => 12, 'duration' => '30s', 'rate' => '10000', 'label' => 'Medium Load - 10K RPS'],
        ['connections' => 2000, 'threads' => 16, 'duration' => '30s', 'rate' => '25000', 'label' => 'High Load - 25K RPS'],
        ['connections' => 5000, 'threads' => 20, 'duration' => '30s', 'rate' => '50000', 'label' => 'Very High - 50K RPS'],
        ['connections' => 10000, 'threads' => 24, 'duration' => '30s', 'rate' => '100000', 'label' => 'TARGET - 100K RPS'],
        ['connections' => 15000, 'threads' => 32, 'duration' => '20s', 'rate' => '150000', 'label' => 'Extreme - 150K RPS'],
        ['connections' => 20000, 'threads' => 40, 'duration' => '15s', 'rate' => '200000', 'label' => 'Maximum - 200K RPS'],
    ];

    public function __construct()
    {
        $this->wrk2Path = $this->findWrk2();
        if (!$this->wrk2Path) {
            throw new RuntimeException("wrk2 not found. Please install wrk2 for accurate RPS testing.");
        }
    }

    private function findWrk2(): ?string
    {
        $wrk2 = trim(shell_exec('which wrk2 2>/dev/null') ?: '');
        return $wrk2 ?: null;
    }

    public function runPerformanceComparison(): void
    {
        echo "\n🚀 HighPer Framework v1 vs Workerman Performance Comparison\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        echo "🔧 WRK2 found at: {$this->wrk2Path}\n";
        echo "🎯 Target: 100K+ RPS performance validation\n";
        echo "📊 Testing frameworks: HighPer Blueprint, HighPer Nano, Workerman\n\n";
        
        // Test each framework
        $frameworks = [
            'workerman' => ['script' => 'workerman-server.php', 'port' => 8080],
            'blueprint' => ['script' => 'blueprint-simple.php', 'port' => 8081], 
            'nano' => ['script' => 'nano-simple.php', 'port' => 8082]
        ];
        
        foreach ($frameworks as $name => $config) {
            echo "🎯 Testing {$name} Framework:\n";
            echo str_repeat("─", 80) . "\n";
            
            $this->results[$name] = $this->testFramework($name, $config['script'], $config['port']);
            echo "\n";
        }
        
        $this->generateComparisonReport();
        $this->generateTabulatedResults();
    }

    private function testFramework(string $name, string $script, int $port): array
    {
        $results = [];
        $scriptPath = __DIR__ . '/' . $script;
        
        echo "🚀 Starting {$name} server on port {$port}...\n";
        
        // Start server in background
        $cmd = "php {$scriptPath} > /tmp/{$name}_server.log 2>&1 &";
        shell_exec($cmd);
        
        // Get the process ID to kill later
        $pid = trim(shell_exec("pgrep -f '{$script}' | tail -1"));
        
        sleep(3); // Give server time to start
        
        // Test if server is responding
        $testResponse = @file_get_contents("http://localhost:{$port}/");
        if (!$testResponse) {
            echo "❌ Server failed to start on port {$port}\n";
            return ['status' => 'FAILED', 'error' => 'Server startup failed'];
        }
        
        echo "✅ Server started successfully\n";
        
        foreach ($this->concurrencyTests as $test) {
            echo "⚡ Testing {$test['label']}...\n";
            
            $cmd = sprintf(
                "%s -t%d -c%d -d%s -R%s --latency http://localhost:%d/ 2>&1",
                $this->wrk2Path,
                $test['threads'],
                $test['connections'], 
                $test['duration'],
                $test['rate'],
                $port
            );
            
            echo "   Running: wrk2 -t{$test['threads']} -c{$test['connections']} -d{$test['duration']} -R{$test['rate']}\n";
            
            $startTime = microtime(true);
            $output = shell_exec($cmd);
            $endTime = microtime(true);
            
            if ($output === null || empty(trim($output))) {
                echo "   ❌ Test failed or timed out\n";
                $results[$test['label']] = [
                    'status' => 'FAILED',
                    'error' => 'Command timeout or system overload',
                    'target_rps' => $test['rate'],
                    'connections' => $test['connections']
                ];
                continue;
            }
            
            $parsed = $this->parseWrk2Output($output);
            $parsed['test_duration'] = round($endTime - $startTime, 2);
            $parsed['target_rps'] = $test['rate'];
            $parsed['connections'] = $test['connections'];
            $parsed['threads'] = $test['threads'];
            
            $results[$test['label']] = $parsed;
            
            echo "   ✅ Result: {$parsed['requests_per_sec']} RPS (target: {$test['rate']}), Latency: {$parsed['avg_latency']}\n";
            
            // Stop if we can't meet the target
            if (isset($parsed['requests_per_sec']) && is_numeric($parsed['requests_per_sec'])) {
                $achieved = (float) $parsed['requests_per_sec'];
                $target = (float) $test['rate'];
                if ($achieved < $target * 0.5) { // If we achieve less than 50% of target
                    echo "   🚨 Performance degraded significantly, stopping further tests\n";
                    break;
                }
            }
            
            sleep(2); // Brief pause between tests
        }
        
        // Kill the server process
        if ($pid) {
            shell_exec("kill {$pid} 2>/dev/null");
        }
        
        sleep(1); // Let process cleanup
        
        return $results;
    }

    private function parseWrk2Output(string $output): array
    {
        $lines = explode("\n", $output);
        $result = [
            'requests_per_sec' => 'N/A',
            'avg_latency' => 'N/A', 
            'p99_latency' => 'N/A',
            'total_requests' => 'N/A',
            'transfer_rate' => 'N/A',
            'status' => 'COMPLETED'
        ];
        
        foreach ($lines as $line) {
            if (preg_match('/Requests\/sec:\s+([\d.]+)/', $line, $matches)) {
                $result['requests_per_sec'] = $matches[1];
            }
            if (preg_match('/Latency\s+([\d.]+\w+)\s+([\d.]+\w+)\s+([\d.]+\w+)/', $line, $matches)) {
                $result['avg_latency'] = $matches[1];
            }
            if (preg_match('/99%\s+([\d.]+\w+)/', $line, $matches)) {
                $result['p99_latency'] = $matches[1];
            }
            if (preg_match('/(\d+) requests in/', $line, $matches)) {
                $result['total_requests'] = $matches[1];
            }
            if (preg_match('/Transfer\/sec:\s+([\d.]+\w+)/', $line, $matches)) {
                $result['transfer_rate'] = $matches[1];
            }
        }
        
        return $result;
    }

    private function generateComparisonReport(): void
    {
        echo "\n\n🏆 FRAMEWORK PERFORMANCE COMPARISON RESULTS\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        
        foreach ($this->results as $framework => $results) {
            echo "\n📊 {$framework} Framework Results:\n";
            echo str_repeat("─", 60) . "\n";
            
            if (isset($results['status']) && $results['status'] === 'FAILED') {
                echo "❌ Framework failed to start: {$results['error']}\n";
                continue;
            }
            
            $maxRps = 0;
            $successfulTests = 0;
            
            foreach ($results as $testName => $result) {
                if ($result['status'] === 'FAILED') {
                    echo "❌ {$testName}: FAILED - {$result['error']}\n";
                } else {
                    echo "✅ {$testName}:\n";
                    echo "   • RPS: {$result['requests_per_sec']} (target: {$result['target_rps']})\n";
                    echo "   • Avg Latency: {$result['avg_latency']}\n";
                    echo "   • P99 Latency: {$result['p99_latency']}\n";
                    echo "   • Total Requests: {$result['total_requests']}\n";
                    
                    if (is_numeric($result['requests_per_sec'])) {
                        $rps = (float) $result['requests_per_sec'];
                        $maxRps = max($maxRps, $rps);
                        $successfulTests++;
                    }
                }
            }
            
            echo "\n📈 {$framework} Summary:\n";
            echo "   • Peak RPS: " . number_format($maxRps) . "\n";
            echo "   • Successful Tests: {$successfulTests}\n";
            echo "   • 100K RPS Target: " . ($maxRps >= 100000 ? "✅ ACHIEVED" : "❌ NOT REACHED") . "\n";
        }
    }

    private function generateTabulatedResults(): void
    {
        echo "\n\n📋 TABULATED PERFORMANCE COMPARISON\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        
        printf("%-20s %-15s %-12s %-12s %-12s %-15s %-10s\n", 
            "Test Level", "Framework", "Target RPS", "Actual RPS", "Connections", "Avg Latency", "Status");
        echo str_repeat("─", 120) . "\n";
        
        foreach ($this->results as $framework => $results) {
            if (isset($results['status']) && $results['status'] === 'FAILED') {
                printf("%-20s %-15s %-12s %-12s %-12s %-15s %-10s\n",
                    "SERVER STARTUP", $framework, "N/A", "N/A", "N/A", "N/A", "❌ FAILED");
                continue;
            }
            
            foreach ($results as $testName => $result) {
                $status = $result['status'] === 'FAILED' ? '❌ FAILED' : '✅ PASS';
                $rpsTarget = $result['target_rps'] ?? 'N/A';
                $rpsActual = $result['requests_per_sec'] ?? 'N/A';
                
                printf("%-20s %-15s %-12s %-12s %-12s %-15s %-10s\n",
                    substr($testName, 0, 19),
                    $framework,
                    is_numeric($rpsTarget) ? number_format($rpsTarget) : $rpsTarget,
                    is_numeric($rpsActual) ? number_format($rpsActual) : $rpsActual,
                    number_format($result['connections'] ?? 0),
                    $result['avg_latency'] ?? 'N/A',
                    $status
                );
            }
        }
        
        // Performance comparison summary
        echo "\n📊 PERFORMANCE SUMMARY:\n";
        echo str_repeat("─", 80) . "\n";
        
        $peakPerformance = [];
        foreach ($this->results as $framework => $results) {
            if (isset($results['status']) && $results['status'] === 'FAILED') {
                $peakPerformance[$framework] = 0;
                continue;
            }
            
            $maxRps = 0;
            foreach ($results as $result) {
                if (isset($result['requests_per_sec']) && is_numeric($result['requests_per_sec'])) {
                    $maxRps = max($maxRps, (float) $result['requests_per_sec']);
                }
            }
            $peakPerformance[$framework] = $maxRps;
        }
        
        arsort($peakPerformance);
        
        $rank = 1;
        foreach ($peakPerformance as $framework => $rps) {
            $rpsFormatted = number_format($rps);
            $target100k = $rps >= 100000 ? "✅ 100K+ ACHIEVED" : "❌ Below 100K";
            echo "{$rank}. {$framework}: {$rpsFormatted} RPS - {$target100k}\n";
            $rank++;
        }
        
        echo "\n🎯 100K RPS TARGET ANALYSIS:\n";
        $achieved100k = array_filter($peakPerformance, fn($rps) => $rps >= 100000);
        if (count($achieved100k) > 0) {
            echo "✅ " . count($achieved100k) . " framework(s) achieved 100K+ RPS target\n";
            foreach ($achieved100k as $framework => $rps) {
                echo "   • {$framework}: " . number_format($rps) . " RPS\n";
            }
        } else {
            echo "❌ No frameworks achieved 100K+ RPS target in this test environment\n";
            echo "💡 Consider: Better hardware, optimized configuration, or Rust FFI components\n";
        }
        
        echo "\n📈 CONCLUSION:\n";
        if (count($peakPerformance) > 1) {
            $frameworks = array_keys($peakPerformance);
            $winner = $frameworks[0];
            $winnerRps = $peakPerformance[$winner];
            echo "🏆 Best Performing: {$winner} with " . number_format($winnerRps) . " RPS\n";
            
            if (isset($peakPerformance['workerman'])) {
                foreach (['blueprint', 'nano'] as $highper) {
                    if (isset($peakPerformance[$highper])) {
                        $improvement = $peakPerformance[$highper] / $peakPerformance['workerman'];
                        $improvementPercent = round(($improvement - 1) * 100, 1);
                        $comparison = $improvement > 1 ? "faster" : "slower";
                        echo "📊 HighPer {$highper} vs Workerman: " . abs($improvementPercent) . "% {$comparison}\n";
                    }
                }
            }
        }
    }
}

// CLI execution
if (php_sapi_name() === 'cli') {
    try {
        $tester = new FrameworkPerformanceComparison();
        $tester->runPerformanceComparison();
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}