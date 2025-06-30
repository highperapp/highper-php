#!/usr/bin/env php
<?php

declare(strict_types=1);

class QuickPerformanceTest
{
    public function runQuickTest(): void
    {
        echo "\n🚀 Quick Performance Comparison - HighPer Framework v1\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        $results = [];
        
        // Test HighPer Nano (minimal)
        echo "🎯 Testing HighPer Nano:\n";
        $results['HighPer Nano'] = $this->testServer('test-nano.php', 8082);
        
        // Test Workerman
        echo "\n🎯 Testing Workerman:\n";
        $results['Workerman'] = $this->testWorkermanQuick();
        
        $this->generateQuickReport($results);
    }

    private function testServer(string $script, int $port): array
    {
        echo "🚀 Starting server on port {$port}...\n";
        
        // Start server
        $cmd = "php -S localhost:{$port} {$script} > /dev/null 2>&1 &";
        shell_exec($cmd);
        sleep(2);
        
        // Test if server responds
        $testResponse = @file_get_contents("http://localhost:{$port}/");
        if (!$testResponse) {
            echo "❌ Server failed to start\n";
            return ['status' => 'FAILED'];
        }
        
        echo "✅ Server started successfully\n";
        
        // Quick test with wrk2
        $tests = [
            ['rate' => '1000', 'conn' => 100, 'threads' => 4],
            ['rate' => '5000', 'conn' => 500, 'threads' => 8],
            ['rate' => '10000', 'conn' => 1000, 'threads' => 12],
        ];
        
        $results = [];
        foreach ($tests as $test) {
            echo "⚡ Testing {$test['rate']} RPS...\n";
            
            $cmd = "wrk2 -t{$test['threads']} -c{$test['conn']} -d10s -R{$test['rate']} http://localhost:{$port}/ 2>&1";
            $output = shell_exec($cmd);
            
            if ($output && preg_match('/Requests\/sec:\s+([\d.]+)/', $output, $matches)) {
                $rps = (float) $matches[1];
                $results[] = $rps;
                echo "   ✅ Achieved: " . number_format($rps) . " RPS\n";
            } else {
                echo "   ❌ Test failed\n";
                break;
            }
        }
        
        // Kill server
        $pid = trim(shell_exec("pgrep -f 'php -S localhost:{$port}'"));
        if ($pid) shell_exec("kill {$pid}");
        
        return ['results' => $results, 'max_rps' => max($results ?: [0]), 'status' => 'COMPLETED'];
    }

    private function testWorkermanQuick(): array
    {
        // Create simple Workerman test
        $workermanTest = '<?php
require_once "vendor/autoload.php";
use Workerman\Worker;

$worker = new Worker("http://0.0.0.0:8080");
$worker->count = 2;

$worker->onMessage = function($connection, $data) {
    $response = \'{"message":"Hello","framework":"Workerman","rps_test":true}\';
    $connection->send("HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($response) . "\r\n\r\n" . $response);
};

Worker::runAll();';
        
        file_put_contents('/tmp/workerman_quick.php', $workermanTest);
        
        echo "🚀 Starting Workerman server...\n";
        $cmd = "cd " . __DIR__ . " && php /tmp/workerman_quick.php > /dev/null 2>&1 &";
        shell_exec($cmd);
        sleep(3);
        
        // Test if server responds
        $testResponse = @file_get_contents("http://localhost:8080/");
        if (!$testResponse) {
            echo "❌ Workerman server failed to start\n";
            return ['status' => 'FAILED'];
        }
        
        echo "✅ Workerman server started successfully\n";
        
        // Quick tests
        $tests = [
            ['rate' => '1000', 'conn' => 100],
            ['rate' => '5000', 'conn' => 500],
            ['rate' => '10000', 'conn' => 1000],
        ];
        
        $results = [];
        foreach ($tests as $test) {
            echo "⚡ Testing {$test['rate']} RPS...\n";
            
            $cmd = "wrk2 -t8 -c{$test['conn']} -d10s -R{$test['rate']} http://localhost:8080/ 2>&1";
            $output = shell_exec($cmd);
            
            if ($output && preg_match('/Requests\/sec:\s+([\d.]+)/', $output, $matches)) {
                $rps = (float) $matches[1];
                $results[] = $rps;
                echo "   ✅ Achieved: " . number_format($rps) . " RPS\n";
            } else {
                echo "   ❌ Test failed\n";
                break;
            }
        }
        
        // Kill Workerman
        shell_exec("pkill -f workerman_quick.php");
        
        return ['results' => $results, 'max_rps' => max($results ?: [0]), 'status' => 'COMPLETED'];
    }

    private function generateQuickReport(array $results): void
    {
        echo "\n\n🏆 QUICK PERFORMANCE COMPARISON RESULTS\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        
        printf("%-20s %-15s %-20s\n", "Framework", "Max RPS", "100K Target");
        echo str_repeat("─", 55) . "\n";
        
        foreach ($results as $framework => $result) {
            if ($result['status'] === 'FAILED') {
                printf("%-20s %-15s %-20s\n", $framework, "FAILED", "❌ Failed");
            } else {
                $maxRps = $result['max_rps'];
                $rpsFormatted = number_format($maxRps);
                $target = $maxRps >= 100000 ? "✅ Achieved" : "❌ Not Reached";
                printf("%-20s %-15s %-20s\n", $framework, $rpsFormatted, $target);
            }
        }
        
        echo "\n📊 COMPARISON ANALYSIS:\n";
        $successfulFrameworks = array_filter($results, fn($r) => $r['status'] === 'COMPLETED');
        
        if (count($successfulFrameworks) >= 2) {
            $frameworks = array_keys($successfulFrameworks);
            $rps1 = $successfulFrameworks[$frameworks[0]]['max_rps'];
            $rps2 = $successfulFrameworks[$frameworks[1]]['max_rps'];
            
            if ($rps1 > $rps2) {
                $improvement = round(($rps1 / $rps2 - 1) * 100, 1);
                echo "🏆 {$frameworks[0]} is {$improvement}% faster than {$frameworks[1]}\n";
            } else {
                $improvement = round(($rps2 / $rps1 - 1) * 100, 1);
                echo "🏆 {$frameworks[1]} is {$improvement}% faster than {$frameworks[0]}\n";
            }
        }
        
        $maxOverall = max(array_column($successfulFrameworks, 'max_rps'));
        
        echo "\n🎯 100K RPS TARGET ANALYSIS:\n";
        if ($maxOverall >= 100000) {
            echo "✅ 100K+ RPS target ACHIEVED!\n";
            echo "🚀 Best Performance: " . number_format($maxOverall) . " RPS\n";
        } else {
            echo "❌ 100K+ RPS target not reached in this test environment\n";
            echo "🚀 Best Performance: " . number_format($maxOverall) . " RPS\n";
            echo "💡 To reach 100K+ RPS consider:\n";
            echo "   • Rust FFI components (5-50x performance boost)\n";
            echo "   • Dedicated hardware with more CPU cores\n";
            echo "   • System optimization (ulimits, kernel parameters)\n";
            echo "   • Load balancing across multiple instances\n";
        }
        
        echo "\n📋 FRAMEWORK CHARACTERISTICS:\n";
        echo "• HighPer Nano: Ultra-minimal overhead, PHP built-in server\n";
        echo "• Workerman: Multi-process event-driven, pure PHP\n";
        echo "• Both: Pure PHP baseline without Rust FFI acceleration\n";
    }
}

if (php_sapi_name() === 'cli') {
    $test = new QuickPerformanceTest();
    $test->runQuickTest();
}