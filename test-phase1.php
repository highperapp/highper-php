#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Phase 1.1 Validation Test
 * 
 * Tests ProcessManager and AsyncManager basic functionality
 * before running performance benchmarks.
 */

require_once __DIR__ . '/templates/nano/vendor/autoload.php';

// Manually include our new classes since they're not in autoloader yet
require_once __DIR__ . '/core/framework/src/Foundation/ProcessManager.php';
require_once __DIR__ . '/core/framework/src/Foundation/AsyncManager.php';

// For Application class, we'll create a minimal mock instead
class MockApplication implements \HighPerApp\HighPer\Contracts\ApplicationInterface {
    private array $config;
    private \HighPerApp\HighPer\Contracts\LoggerInterface $logger;
    private \HighPerApp\HighPer\Contracts\ContainerInterface $container;
    
    public function __construct(array $config = []) {
        $this->config = $config;
        $this->logger = new class implements \HighPerApp\HighPer\Contracts\LoggerInterface {
            public function emergency(string|\Stringable $message, array $context = []): void { echo "[EMERGENCY] $message\n"; }
            public function alert(string|\Stringable $message, array $context = []): void { echo "[ALERT] $message\n"; }
            public function critical(string|\Stringable $message, array $context = []): void { echo "[CRITICAL] $message\n"; }
            public function error(string|\Stringable $message, array $context = []): void { echo "[ERROR] $message\n"; }
            public function warning(string|\Stringable $message, array $context = []): void { echo "[WARNING] $message\n"; }
            public function notice(string|\Stringable $message, array $context = []): void { echo "[NOTICE] $message\n"; }
            public function info(string|\Stringable $message, array $context = []): void { echo "[INFO] $message\n"; }
            public function debug(string|\Stringable $message, array $context = []): void { echo "[DEBUG] $message\n"; }
            public function log($level, string|\Stringable $message, array $context = []): void { echo "[$level] $message\n"; }
        };
        $this->container = new class implements \HighPerApp\HighPer\Contracts\ContainerInterface {
            public function get(string $id): mixed { return new stdClass(); }
            public function has(string $id): bool { return true; }
            public function bind(string $abstract, $concrete = null): void {}
            public function singleton(string $abstract, $concrete = null): void {}
            public function instance(string $abstract, $instance): void {}
            public function make(string $abstract, array $parameters = []): mixed { return new stdClass(); }
        };
    }
    
    public function getLogger(): \HighPerApp\HighPer\Contracts\LoggerInterface { return $this->logger; }
    public function getContainer(): \HighPerApp\HighPer\Contracts\ContainerInterface { return $this->container; }
    public function getConfig(): \HighPerApp\HighPer\Contracts\ConfigManagerInterface { 
        return new class($this->config) implements \HighPerApp\HighPer\Contracts\ConfigManagerInterface {
            private array $config;
            public function __construct(array $config) { $this->config = $config; }
            public function get(string $key, $default = null): mixed { return $this->config[$key] ?? $default; }
            public function set(string $key, $value): void { $this->config[$key] = $value; }
            public function has(string $key): bool { return isset($this->config[$key]); }
            public function getAll(): array { return $this->config; }
            public function getNamespace(string $namespace): array { return $this->config[$namespace] ?? []; }
            public function loadFromFile(string $file): void {}
            public function loadEnvironment(): void {}
            public function getEnvironment(): string { return 'test'; }
        };
    }
    public function bootstrap(): void {}
    public function run(): void {}
    public function shutdown(): void {}
    public function getRouter(): \HighPerApp\HighPer\Contracts\RouterInterface { return new class implements \HighPerApp\HighPer\Contracts\RouterInterface {
        public function addRoute(string $method, string $path, $handler): void {}
        public function match(string $method, string $path): ?\HighPerApp\HighPer\Contracts\RouteMatchInterface { return null; }
        public function generate(string $name, array $parameters = []): string { return ''; }
    }; }
}

use HighPerApp\HighPer\Foundation\ProcessManager;
use HighPerApp\HighPer\Foundation\AsyncManager;

echo "🧪 HighPer v3 Phase 1.1 Validation Test\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

// Test 1: AsyncManager Basic Functionality
echo "\n📋 Test 1: AsyncManager Basic Functionality\n";

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

// Test 3: ProcessManager Configuration
echo "\n📋 Test 3: ProcessManager Configuration\n";

try {
    // Create minimal app config for ProcessManager
    $config = [
        'app' => ['name' => 'Test App'],
        'server' => ['workers' => 2] // Use 2 workers for testing
    ];
    
    $app = new MockApplication($config);
    $processManager = new ProcessManager($app);
    
    echo "✅ ProcessManager created successfully\n";
    echo "✅ Optimal worker count: " . $processManager->getWorkerCount() . "\n";
    echo "✅ ProcessManager running status: " . ($processManager->isRunning() ? 'true' : 'false') . "\n";
    
} catch (\Throwable $e) {
    echo "❌ ProcessManager test failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 4: Basic Performance Timing
echo "\n📋 Test 4: Basic Performance Timing\n";

try {
    $start = microtime(true);
    
    // Test async operation timing
    $asyncResult = AsyncManager::timeout(function() {
        // Simulate some work
        usleep(1000); // 1ms
        return "Async operation completed";
    }, 1.0)->await();
    
    $asyncTime = microtime(true) - $start;
    echo "✅ Async operation completed in: " . round($asyncTime * 1000, 2) . "ms\n";
    echo "✅ Result: {$asyncResult}\n";
    
} catch (\Throwable $e) {
    echo "❌ Performance timing test failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n🎉 All Phase 1.1 validation tests passed!\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "\n📊 Ready for wrk2 performance benchmarking!\n";
echo "\n🔄 Next steps:\n";
echo "   1. Start nano server: cd templates/nano && php server.php --port=8080\n";
echo "   2. Run wrk2 benchmark: wrk2 -t4 -c100 -d30s -R5000 --latency http://localhost:8080/ping\n";
echo "   3. Compare with baseline performance\n";
echo "\n";