#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Phase 1.1+ Performance Test using WRK2 - HighPer Framework v3
 * 
 * Adapted from wrk-extreme-concurrency-test.php for focused Phase 1 testing
 * Tests Blueprint vs Nano vs Phase 1.1 Enhanced Server
 * 
 * Target: Measure improvements from ProcessManager, AsyncManager, AdaptiveSerializer
 */

class WrkPhase1Test
{
    private string $wrkPath;
    private array $results = [];
    
    // Phase 1 focused test configurations
    private array $concurrencyTests = [
        ['connections' => 10, 'threads' => 2, 'duration' => '10s', 'rate' => 100, 'label' => 'Baseline (10c/2t/100rps)'],
        ['connections' => 50, 'threads' => 4, 'duration' => '15s', 'rate' => 500, 'label' => 'Light Load (50c/4t/500rps)'],
        ['connections' => 100, 'threads' => 4, 'duration' => '15s', 'rate' => 1000, 'label' => 'Medium Load (100c/4t/1krps)'],
        ['connections' => 200, 'threads' => 6, 'duration' => '20s', 'rate' => 2000, 'label' => 'Heavy Load (200c/6t/2krps)'],
        ['connections' => 500, 'threads' => 8, 'duration' => '30s', 'rate' => 5000, 'label' => 'Stress Test (500c/8t/5krps)'],
    ];

    public function __construct()
    {
        $this->wrkPath = $this->findWrk2();
        if (!$this->wrkPath) {
            throw new RuntimeException("wrk2 not found. Please install wrk2 for performance testing.");
        }
    }

    private function findWrk2(): ?string
    {
        // Check for wrk2 first (preferred)
        $wrk2 = trim(shell_exec('which wrk2 2>/dev/null') ?: '');
        if ($wrk2) {
            return $wrk2;
        }
        
        // Fallback to wrk
        $wrk = trim(shell_exec('which wrk 2>/dev/null') ?: '');
        return $wrk ?: null;
    }

    public function runPhase1Test(): void
    {
        echo "\n🚀 Phase 1.1+ Performance Test - HighPer Framework v3\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

        echo "🔧 WRK tool: {$this->wrkPath}\n";
        echo "📊 Testing: Blueprint (baseline) vs Nano (minimal) vs Phase1.1 (enhanced)\n";
        echo "📋 Components: ProcessManager, AsyncManager, AdaptiveSerializer, RustFFI\n";
        echo "⚡ Goal: Measure performance improvements from v3 enhancements\n\n";

        // Test configurations
        $testConfigs = [
            ['name' => 'blueprint', 'port' => 8082, 'server' => 'blueprint-baseline'],
            ['name' => 'nano', 'port' => 8083, 'server' => 'nano-baseline'],
            ['name' => 'phase1-enhanced', 'port' => 8084, 'server' => 'phase1-enhanced'],
        ];

        foreach ($testConfigs as $config) {
            echo "🎯 Testing {$config['name']} on port {$config['port']}:\n";
            echo str_repeat("─", 80) . "\n";
            
            $this->results[$config['name']] = $this->testServerConfiguration($config);
            echo "\n";
        }

        $this->generatePhase1Report();
        $this->generateComparisonTable();
    }

    private function testServerConfiguration(array $config): array
    {
        $results = [];
        
        // Start the appropriate server
        $serverPid = $this->startServer($config);
        
        if (!$serverPid) {
            echo "❌ Failed to start {$config['name']} server\n";
            return ['error' => 'Server start failed'];
        }
        
        sleep(3); // Allow server to start
        
        foreach ($this->concurrencyTests as $test) {
            echo "⚡ {$test['label']}...\n";
            
            // Use wrk2 with rate limiting for consistent testing
            if (str_contains($this->wrkPath, 'wrk2')) {
                $cmd = sprintf(
                    "%s -t%d -c%d -d%s -R%d --latency http://localhost:%d/ping 2>&1",
                    $this->wrkPath,
                    $test['threads'],
                    $test['connections'],
                    $test['duration'],
                    $test['rate'],
                    $config['port']
                );
            } else {
                // Fallback to regular wrk
                $cmd = sprintf(
                    "%s -t%d -c%d -d%s --latency http://localhost:%d/ping 2>&1",
                    $this->wrkPath,
                    $test['threads'],
                    $test['connections'],
                    $test['duration'],
                    $config['port']
                );
            }
            
            $startTime = microtime(true);
            $output = shell_exec($cmd);
            $endTime = microtime(true);
            
            if ($output === null || empty(trim($output))) {
                echo "   ❌ Test failed or timed out\n";
                $results[$test['label']] = [
                    'status' => 'FAILED',
                    'error' => 'Command timeout',
                    'duration' => $endTime - $startTime
                ];
                continue;
            }
            
            $parsed = $this->parseWrkOutput($output);
            $parsed['test_duration'] = $endTime - $startTime;
            $parsed['config'] = $test;
            
            $results[$test['label']] = $parsed;
            
            echo "   ✅ {$parsed['requests_per_sec']} RPS, {$parsed['avg_latency']} latency\n";
            
            sleep(2); // Brief pause between tests
        }
        
        // Stop server
        $this->stopServer($serverPid);
        
        return $results;
    }
    
    private function startServer(array $config): ?int
    {
        switch ($config['server']) {
            case 'blueprint-baseline':
                return $this->startBlueprintServer($config['port']);
            case 'nano-baseline':
                return $this->startNanoServer($config['port']);
            case 'phase1-enhanced':
                return $this->startPhase1Server($config['port']);
            default:
                return null;
        }
    }
    
    private function startBlueprintServer(int $port): ?int
    {
        $cmd = "cd " . __DIR__ . "/templates/blueprint && php bin/serve --port={$port} > /dev/null 2>&1 & echo $!";
        $pid = trim(shell_exec($cmd) ?: '');
        return $pid ? (int)$pid : null;
    }
    
    private function startNanoServer(int $port): ?int
    {
        $cmd = "cd " . __DIR__ . "/templates/nano && php server.php --port={$port} > /dev/null 2>&1 & echo $!";
        $pid = trim(shell_exec($cmd) ?: '');
        return $pid ? (int)$pid : null;
    }
    
    private function startPhase1Server(int $port): ?int
    {
        $cmd = "cd " . __DIR__ . " && php test-server-phase1.php --port={$port} > /dev/null 2>&1 & echo $!";
        $pid = trim(shell_exec($cmd) ?: '');
        return $pid ? (int)$pid : null;
    }
    
    private function stopServer(?int $pid): void
    {
        if ($pid) {
            shell_exec("kill {$pid} 2>/dev/null");
            sleep(1);
        }
    }

    private function parseWrkOutput(string $output): array
    {
        $lines = explode("\n", $output);
        $result = [
            'requests_per_sec' => 'N/A',
            'avg_latency' => 'N/A',
            'p99_latency' => 'N/A',
            'total_requests' => 'N/A',
            'total_data' => 'N/A',
            'status' => 'COMPLETED'
        ];
        
        foreach ($lines as $line) {
            if (preg_match('/Requests\/sec:\s+([\d.]+)/', $line, $matches)) {
                $result['requests_per_sec'] = $matches[1];
            }
            if (preg_match('/Latency\s+([\d.]+\w+)/', $line, $matches)) {
                $result['avg_latency'] = $matches[1];
            }
            if (preg_match('/99.000%\s+([\d.]+\w+)/', $line, $matches)) {
                $result['p99_latency'] = $matches[1];
            }
            if (preg_match('/(\d+) requests in/', $line, $matches)) {
                $result['total_requests'] = $matches[1];
            }
            if (preg_match('/Transfer\/sec:\s+([\d.]+\w+)/', $line, $matches)) {
                $result['total_data'] = $matches[1];
            }
        }
        
        return $result;
    }

    private function generatePhase1Report(): void
    {
        echo "\n\n🏆 PHASE 1.1+ PERFORMANCE TEST RESULTS\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        
        foreach ($this->results as $server => $results) {
            echo "\n📊 {$server} Results:\n";
            echo str_repeat("─", 60) . "\n";
            
            if (isset($results['error'])) {
                echo "❌ Error: {$results['error']}\n";
                continue;
            }
            
            foreach ($results as $testName => $result) {
                if ($result['status'] === 'FAILED') {
                    echo "❌ {$testName}: FAILED - {$result['error']}\n";
                } else {
                    echo "✅ {$testName}:\n";
                    echo "   • RPS: {$result['requests_per_sec']}\n";
                    echo "   • Avg Latency: {$result['avg_latency']}\n";
                    echo "   • P99 Latency: {$result['p99_latency']}\n";
                    echo "   • Total Requests: {$result['total_requests']}\n";
                }
            }
        }
    }

    private function generateComparisonTable(): void
    {
        echo "\n\n📋 PERFORMANCE COMPARISON TABLE\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        
        printf("%-25s %-15s %-12s %-15s %-15s %-10s\n", 
            "Test", "Server", "RPS", "Avg Latency", "P99 Latency", "Status");
        echo str_repeat("─", 110) . "\n";
        
        // Get baseline RPS for improvement calculation
        $baselineRPS = 0;
        if (isset($this->results['blueprint']['Baseline (10c/2t/100rps)']['requests_per_sec'])) {
            $baselineRPS = (float)$this->results['blueprint']['Baseline (10c/2t/100rps)']['requests_per_sec'];
        }
        
        foreach ($this->results as $server => $results) {
            if (isset($results['error'])) {
                printf("%-25s %-15s %-12s %-15s %-15s %-10s\n",
                    "All Tests", $server, "ERROR", "ERROR", "ERROR", "❌ FAILED");
                continue;
            }
            
            foreach ($results as $testName => $result) {
                $status = $result['status'] === 'FAILED' ? '❌ FAILED' : '✅ PASS';
                
                // Calculate improvement percentage
                $improvement = '';
                if ($baselineRPS > 0 && is_numeric($result['requests_per_sec'])) {
                    $currentRPS = (float)$result['requests_per_sec'];
                    $improvementPct = (($currentRPS - $baselineRPS) / $baselineRPS) * 100;
                    $improvement = $improvementPct > 0 ? sprintf(" (+%.1f%%)", $improvementPct) : '';
                }
                
                printf("%-25s %-15s %-12s %-15s %-15s %-10s\n",
                    $testName,
                    $server,
                    $result['requests_per_sec'] . $improvement,
                    $result['avg_latency'],
                    $result['p99_latency'],
                    $status
                );
            }
        }
        
        echo "\n📈 Phase 1.1+ Enhancement Analysis:\n";
        echo "• ProcessManager: Multi-process worker architecture\n";
        echo "• AsyncManager: Auto-yield transparent async patterns\n";
        echo "• AdaptiveSerializer: JSON/MessagePack + Rust FFI fallbacks\n";
        echo "• RustFFIManager: Performance optimization layer\n";
        echo "• Testing Tool: " . basename($this->wrkPath) . "\n";
        echo "• Framework: HighPer v3 with AMPHP v3 + RevoltPHP\n";
    }
}

// CLI execution
if (php_sapi_name() === 'cli') {
    try {
        $tester = new WrkPhase1Test();
        $tester->runPhase1Test();
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}