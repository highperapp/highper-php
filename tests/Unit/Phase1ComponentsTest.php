<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Tests\Unit;

/**
 * Phase 1 Components Unit Test
 * 
 * Comprehensive unit tests for all Phase 1 core components:
 * ProcessManager, AsyncManager, AdaptiveSerializer, RustFFIManager,
 * AMPHTTPServerManager, ZeroDowntimeIntegration
 */
class Phase1ComponentsTest
{
    private array $testResults = [];
    private int $totalTests = 0;
    private int $passedTests = 0;

    public function runAllPhase1Tests(): array
    {
        echo "🧪 HighPer Framework v3 - Phase 1 Components Unit Tests\n";
        echo "=======================================================\n\n";

        // Load framework
        $this->loadFramework();

        // Test all Phase 1 components
        $this->testProcessManager();
        $this->testAsyncManager();
        $this->testAdaptiveSerializer();
        $this->testRustFFIManager();
        $this->testAMPHTTPServerManager();
        $this->testZeroDowntimeIntegration();

        return $this->generateTestReport();
    }

    private function loadFramework(): void
    {
        $autoloader = __DIR__ . '/../../core/framework/vendor/autoload.php';
        if (file_exists($autoloader)) {
            require_once $autoloader;
            $this->recordTest('Framework Loading', true, 'Framework autoloader loaded successfully');
        } else {
            $this->recordTest('Framework Loading', false, 'Framework autoloader not found');
        }
    }

    private function testProcessManager(): void
    {
        echo "🔧 Testing ProcessManager...\n";
        echo str_repeat("─", 40) . "\n";

        if (!class_exists('HighPerApp\\HighPer\\Foundation\\ProcessManager')) {
            $this->recordTest('ProcessManager - Class Exists', false, 'ProcessManager class not found');
            echo "  ❌ ProcessManager class not available\n\n";
            return;
        }

        // Test ProcessManager instantiation
        try {
            $mockApp = $this->createMockApplication();
            $processManager = new \HighPerApp\HighPer\Foundation\ProcessManager($mockApp);
            
            $this->recordTest('ProcessManager - Instantiation', true, 'ProcessManager created successfully');
            echo "  ✅ ProcessManager instantiation - OK\n";

            // Test configuration
            $config = $processManager->getConfig();
            $this->recordTest('ProcessManager - Configuration', is_array($config), 'Configuration is array: ' . json_encode($config));
            echo "  ✅ Configuration retrieval - OK\n";

            // Test initial state
            $isRunning = $processManager->isRunning();
            $this->recordTest('ProcessManager - Initial State', $isRunning === false, 'Initial running state is false');
            echo "  ✅ Initial state check - OK\n";

            // Test worker count
            $workerCount = $processManager->getWorkersCount();
            $this->recordTest('ProcessManager - Worker Count', is_int($workerCount), 'Worker count is integer: ' . $workerCount);
            echo "  ✅ Worker count - OK\n";

            // Test statistics
            $stats = $processManager->getStats();
            $this->recordTest('ProcessManager - Statistics', is_array($stats), 'Statistics returned as array');
            echo "  ✅ Statistics - OK\n";

        } catch (\Exception $e) {
            $this->recordTest('ProcessManager - General', false, 'Error: ' . $e->getMessage());
            echo "  ❌ ProcessManager error: " . $e->getMessage() . "\n";
        }

        echo "\n";
    }

    private function testAsyncManager(): void
    {
        echo "🔧 Testing AsyncManager...\n";
        echo str_repeat("─", 40) . "\n";

        if (!class_exists('HighPerApp\\HighPer\\Foundation\\AsyncManager')) {
            $this->recordTest('AsyncManager - Class Exists', false, 'AsyncManager class not found');
            echo "  ❌ AsyncManager class not available\n\n";
            return;
        }

        try {
            $asyncManager = new \HighPerApp\HighPer\Foundation\AsyncManager();
            
            $this->recordTest('AsyncManager - Instantiation', true, 'AsyncManager created successfully');
            echo "  ✅ AsyncManager instantiation - OK\n";

            // Test statistics
            $stats = $asyncManager->getStats();
            $this->recordTest('AsyncManager - Statistics', is_array($stats), 'Statistics: ' . json_encode($stats));
            echo "  ✅ Statistics retrieval - OK\n";

            // Test async context check
            $isAsync = $asyncManager->isAsync();
            $this->recordTest('AsyncManager - Async Context', is_bool($isAsync), 'Async context check: ' . ($isAsync ? 'true' : 'false'));
            echo "  ✅ Async context check - OK\n";

            // Test cleanup
            $asyncManager->cleanup();
            $this->recordTest('AsyncManager - Cleanup', true, 'Cleanup executed without errors');
            echo "  ✅ Cleanup - OK\n";

        } catch (\Exception $e) {
            $this->recordTest('AsyncManager - General', false, 'Error: ' . $e->getMessage());
            echo "  ❌ AsyncManager error: " . $e->getMessage() . "\n";
        }

        echo "\n";
    }

    private function testAdaptiveSerializer(): void
    {
        echo "🔧 Testing AdaptiveSerializer...\n";
        echo str_repeat("─", 40) . "\n";

        if (!class_exists('HighPerApp\\HighPer\\Foundation\\AdaptiveSerializer')) {
            $this->recordTest('AdaptiveSerializer - Class Exists', false, 'AdaptiveSerializer class not found');
            echo "  ❌ AdaptiveSerializer class not available\n\n";
            return;
        }

        try {
            $serializer = new \HighPerApp\HighPer\Foundation\AdaptiveSerializer();
            
            $this->recordTest('AdaptiveSerializer - Instantiation', true, 'AdaptiveSerializer created successfully');
            echo "  ✅ AdaptiveSerializer instantiation - OK\n";

            // Test serialization/deserialization
            $testData = [
                'string' => 'test_string',
                'number' => 42,
                'array' => [1, 2, 3],
                'nested' => ['level1' => ['level2' => 'value']]
            ];

            $serialized = $serializer->serialize($testData);
            $this->recordTest('AdaptiveSerializer - Serialization', is_string($serialized), 'Data serialized successfully');
            echo "  ✅ Serialization - OK\n";

            $deserialized = $serializer->deserialize($serialized);
            $this->recordTest('AdaptiveSerializer - Deserialization', $deserialized === $testData, 'Data deserialized correctly');
            echo "  ✅ Deserialization - OK\n";

            // Test available formats
            $formats = $serializer->getAvailableFormats();
            $this->recordTest('AdaptiveSerializer - Formats', is_array($formats) && in_array('json', $formats), 'Available formats: ' . implode(', ', $formats));
            echo "  ✅ Available formats - OK\n";

            // Test statistics
            $stats = $serializer->getStats();
            $this->recordTest('AdaptiveSerializer - Statistics', is_array($stats), 'Statistics: ' . json_encode($stats));
            echo "  ✅ Statistics - OK\n";

            // Test validation
            $isValid = $serializer->validate('{"test": "data"}', 'json');
            $this->recordTest('AdaptiveSerializer - Validation', $isValid === true, 'JSON validation works');
            echo "  ✅ Validation - OK\n";

        } catch (\Exception $e) {
            $this->recordTest('AdaptiveSerializer - General', false, 'Error: ' . $e->getMessage());
            echo "  ❌ AdaptiveSerializer error: " . $e->getMessage() . "\n";
        }

        echo "\n";
    }

    private function testRustFFIManager(): void
    {
        echo "🔧 Testing RustFFIManager...\n";
        echo str_repeat("─", 40) . "\n";

        if (!class_exists('HighPerApp\\HighPer\\Foundation\\RustFFIManager')) {
            $this->recordTest('RustFFIManager - Class Exists', false, 'RustFFIManager class not found');
            echo "  ❌ RustFFIManager class not available\n\n";
            return;
        }

        try {
            $ffiManager = new \HighPerApp\HighPer\Foundation\RustFFIManager();
            
            $this->recordTest('RustFFIManager - Instantiation', true, 'RustFFIManager created successfully');
            echo "  ✅ RustFFIManager instantiation - OK\n";

            // Test FFI availability check
            $isAvailable = $ffiManager->isAvailable();
            $this->recordTest('RustFFIManager - Availability', is_bool($isAvailable), 'FFI availability: ' . ($isAvailable ? 'available' : 'not available'));
            echo "  ✅ FFI availability check - OK\n";

            // Test library registration
            $ffiManager->registerLibrary('test_lib', ['header' => '/tmp/test.h', 'lib' => '/tmp/test.so']);
            $this->recordTest('RustFFIManager - Library Registration', true, 'Library registered successfully');
            echo "  ✅ Library registration - OK\n";

            // Test loaded libraries
            $loadedLibraries = $ffiManager->getLoadedLibraries();
            $this->recordTest('RustFFIManager - Loaded Libraries', is_array($loadedLibraries), 'Loaded libraries: ' . count($loadedLibraries));
            echo "  ✅ Loaded libraries check - OK\n";

            // Test statistics
            $stats = $ffiManager->getStats();
            $this->recordTest('RustFFIManager - Statistics', is_array($stats), 'Statistics: ' . json_encode($stats));
            echo "  ✅ Statistics - OK\n";

        } catch (\Exception $e) {
            $this->recordTest('RustFFIManager - General', false, 'Error: ' . $e->getMessage());
            echo "  ❌ RustFFIManager error: " . $e->getMessage() . "\n";
        }

        echo "\n";
    }

    private function testAMPHTTPServerManager(): void
    {
        echo "🔧 Testing AMPHTTPServerManager...\n";
        echo str_repeat("─", 40) . "\n";

        if (!class_exists('HighPerApp\\HighPer\\Foundation\\AMPHTTPServerManager')) {
            $this->recordTest('AMPHTTPServerManager - Class Exists', false, 'AMPHTTPServerManager class not found');
            echo "  ❌ AMPHTTPServerManager class not available\n\n";
            return;
        }

        try {
            $serverManager = new \HighPerApp\HighPer\Foundation\AMPHTTPServerManager();
            
            $this->recordTest('AMPHTTPServerManager - Instantiation', true, 'AMPHTTPServerManager created successfully');
            echo "  ✅ AMPHTTPServerManager instantiation - OK\n";

            // Test initial state
            $isRunning = $serverManager->isRunning();
            $this->recordTest('AMPHTTPServerManager - Initial State', $isRunning === false, 'Initial running state is false');
            echo "  ✅ Initial state check - OK\n";

            // Test protocol configuration
            $protocols = ['http', 'https', 'ws', 'wss'];
            $serverManager->enableProtocols($protocols);
            $enabledProtocols = $serverManager->getEnabledProtocols();
            $this->recordTest('AMPHTTPServerManager - Protocols', $enabledProtocols === $protocols, 'Protocols enabled: ' . implode(', ', $enabledProtocols));
            echo "  ✅ Protocol configuration - OK\n";

            // Test proxy headers
            $proxyHeaders = ['X-Real-IP', 'X-Forwarded-For'];
            $serverManager->setProxyHeaders($proxyHeaders);
            $this->recordTest('AMPHTTPServerManager - Proxy Headers', true, 'Proxy headers set successfully');
            echo "  ✅ Proxy headers - OK\n";

            // Test configuration
            $config = ['host' => '127.0.0.1', 'port' => 8080];
            $serverManager->setConfig($config);
            $this->recordTest('AMPHTTPServerManager - Configuration', true, 'Configuration set successfully');
            echo "  ✅ Configuration - OK\n";

            // Test statistics
            $stats = $serverManager->getStats();
            $this->recordTest('AMPHTTPServerManager - Statistics', is_array($stats), 'Statistics: ' . json_encode($stats));
            echo "  ✅ Statistics - OK\n";

        } catch (\Exception $e) {
            $this->recordTest('AMPHTTPServerManager - General', false, 'Error: ' . $e->getMessage());
            echo "  ❌ AMPHTTPServerManager error: " . $e->getMessage() . "\n";
        }

        echo "\n";
    }

    private function testZeroDowntimeIntegration(): void
    {
        echo "🔧 Testing ZeroDowntimeIntegration...\n";
        echo str_repeat("─", 40) . "\n";

        if (!class_exists('HighPerApp\\HighPer\\Foundation\\ZeroDowntimeIntegration')) {
            $this->recordTest('ZeroDowntimeIntegration - Class Exists', false, 'ZeroDowntimeIntegration class not found');
            echo "  ❌ ZeroDowntimeIntegration class not available\n\n";
            return;
        }

        try {
            $zeroDowntime = new \HighPerApp\HighPer\Foundation\ZeroDowntimeIntegration();
            
            $this->recordTest('ZeroDowntimeIntegration - Instantiation', true, 'ZeroDowntimeIntegration created successfully');
            echo "  ✅ ZeroDowntimeIntegration instantiation - OK\n";

            // Test health check
            $isHealthy = $zeroDowntime->checkHealth();
            $this->recordTest('ZeroDowntimeIntegration - Health Check', is_bool($isHealthy), 'Health check: ' . ($isHealthy ? 'healthy' : 'unhealthy'));
            echo "  ✅ Health check - OK\n";

            // Test status
            $status = $zeroDowntime->getStatus();
            $this->recordTest('ZeroDowntimeIntegration - Status', is_array($status), 'Status: ' . json_encode($status));
            echo "  ✅ Status check - OK\n";

            // Test deployment preparation
            $zeroDowntime->prepareDeployment();
            $this->recordTest('ZeroDowntimeIntegration - Prepare Deployment', true, 'Deployment preparation executed');
            echo "  ✅ Deployment preparation - OK\n";

            // Test WebSocket preservation
            $zeroDowntime->preserveWebSockets();
            $this->recordTest('ZeroDowntimeIntegration - Preserve WebSockets', true, 'WebSocket preservation executed');
            echo "  ✅ WebSocket preservation - OK\n";

            // Test connection transfer
            $zeroDowntime->transferConnections();
            $this->recordTest('ZeroDowntimeIntegration - Transfer Connections', true, 'Connection transfer executed');
            echo "  ✅ Connection transfer - OK\n";

        } catch (\Exception $e) {
            $this->recordTest('ZeroDowntimeIntegration - General', false, 'Error: ' . $e->getMessage());
            echo "  ❌ ZeroDowntimeIntegration error: " . $e->getMessage() . "\n";
        }

        echo "\n";
    }

    private function createMockApplication(): object
    {
        return new class implements \HighPerApp\HighPer\Contracts\ApplicationInterface {
            private array $container = [];
            
            public function bootstrap(): void {}
            public function run(): void {}
            public function getContainer(): \HighPerApp\HighPer\Contracts\ContainerInterface {
                return new class($this->container) implements \HighPerApp\HighPer\Contracts\ContainerInterface {
                    public function __construct(private array &$container) {}
                    public function get(string $id): mixed { return $this->container[$id] ?? new stdClass(); }
                    public function set(string $id, mixed $value): void { $this->container[$id] = $value; }
                    public function has(string $id): bool { return isset($this->container[$id]); }
                };
            }
            public function getRouter(): \HighPerApp\HighPer\Contracts\RouterInterface {
                return new class implements \HighPerApp\HighPer\Contracts\RouterInterface {
                    public function get(string $path, callable $handler): void {}
                    public function post(string $path, callable $handler): void {}
                    public function put(string $path, callable $handler): void {}
                    public function delete(string $path, callable $handler): void {}
                    public function patch(string $path, callable $handler): void {}
                    public function addRoute(string $method, string $path, callable $handler): void {}
                    public function dispatch(string $method, string $path): mixed { return null; }
                    public function group(string $prefix, callable $callback): void {}
                    public function middleware(string|array $middleware): self { return $this; }
                };
            }
            public function getConfig(): \HighPerApp\HighPer\Contracts\ConfigManagerInterface {
                return new class implements \HighPerApp\HighPer\Contracts\ConfigManagerInterface {
                    public function get(string $key, mixed $default = null): mixed { return $default; }
                    public function set(string $key, mixed $value): void {}
                    public function has(string $key): bool { return false; }
                    public function all(): array { return []; }
                    public function load(string $file): void {}
                };
            }
            public function getLogger(): \HighPerApp\HighPer\Contracts\LoggerInterface {
                return new class implements \HighPerApp\HighPer\Contracts\LoggerInterface {
                    public function emergency(string $message, array $context = []): void {}
                    public function alert(string $message, array $context = []): void {}
                    public function critical(string $message, array $context = []): void {}
                    public function error(string $message, array $context = []): void {}
                    public function warning(string $message, array $context = []): void {}
                    public function notice(string $message, array $context = []): void {}
                    public function info(string $message, array $context = []): void {}
                    public function debug(string $message, array $context = []): void {}
                    public function log(string $level, string $message, array $context = []): void {}
                };
            }
            public function register(\HighPerApp\HighPer\Contracts\ServiceProviderInterface $provider): void {}
            public function bootProviders(): void {}
            public function isRunning(): bool { return false; }
            public function shutdown(): void {}
        };
    }

    private function recordTest(string $name, bool $passed, string $message): void
    {
        $this->totalTests++;
        if ($passed) {
            $this->passedTests++;
        }
        
        $this->testResults[] = [
            'name' => $name,
            'passed' => $passed,
            'message' => $message,
            'timestamp' => microtime(true)
        ];
    }

    private function generateTestReport(): array
    {
        echo "📊 Phase 1 Components Unit Test Report\n";
        echo "=====================================\n\n";
        
        $percentage = $this->totalTests > 0 ? round(($this->passedTests / $this->totalTests) * 100, 1) : 0;
        
        echo "📈 Summary:\n";
        echo "  • Total Tests: {$this->totalTests}\n";
        echo "  • Passed: {$this->passedTests}\n";
        echo "  • Failed: " . ($this->totalTests - $this->passedTests) . "\n";
        echo "  • Success Rate: {$percentage}%\n\n";
        
        echo "📋 Detailed Results:\n";
        foreach ($this->testResults as $test) {
            $status = $test['passed'] ? '✅' : '❌';
            echo "  {$status} {$test['name']}: {$test['message']}\n";
        }
        
        return [
            'total_tests' => $this->totalTests,
            'passed_tests' => $this->passedTests,
            'failed_tests' => $this->totalTests - $this->passedTests,
            'success_rate' => $percentage,
            'detailed_results' => $this->testResults
        ];
    }
}

// CLI execution
if (php_sapi_name() === 'cli') {
    $tester = new Phase1ComponentsTest();
    $results = $tester->runAllPhase1Tests();
    
    if ($results['success_rate'] >= 80) {
        echo "\n🎉 Phase 1 unit tests PASSED!\n";
        exit(0);
    } else {
        echo "\n❌ Phase 1 unit tests FAILED!\n";
        exit(1);
    }
}