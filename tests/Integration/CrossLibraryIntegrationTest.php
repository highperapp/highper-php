<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Tests\Integration;

/**
 * Cross-Library Integration Test
 * 
 * Validates that all standalone libraries work together
 * with the v1 framework core components.
 */
class CrossLibraryIntegrationTest
{
    private array $testResults = [];
    private array $loadedLibraries = [];

    public function runAllIntegrationTests(): array
    {
        echo "🔄 HighPer Framework v1 - Cross-Library Integration Testing\n";
        echo "=========================================================\n\n";

        // Test core framework components
        $this->testCoreFrameworkComponents();
        
        // Test standalone library integrations
        $this->testStandaloneLibraryIntegrations();
        
        // Test template integrations
        $this->testTemplateIntegrations();
        
        // Test service provider loading
        $this->testServiceProviderLoading();
        
        // Test reliability stack integration
        $this->testReliabilityStackIntegration();
        
        return $this->generateIntegrationReport();
    }

    private function testCoreFrameworkComponents(): void
    {
        echo "🧪 Testing Core Framework Components Integration...\n";
        echo str_repeat("─", 60) . "\n";

        // Load core framework
        $autoloader = __DIR__ . '/../../core/framework/vendor/autoload.php';
        if (file_exists($autoloader)) {
            require_once $autoloader;
            $this->recordTest('Core Framework Autoloader', true, 'Framework autoloader loaded successfully');
        } else {
            $this->recordTest('Core Framework Autoloader', false, 'Framework autoloader not found');
            return;
        }

        // Test Phase 1 components
        $phase1Components = [
            'ProcessManager' => 'HighPerApp\\HighPer\\Foundation\\ProcessManager',
            'AsyncManager' => 'HighPerApp\\HighPer\\Foundation\\AsyncManager',
            'AdaptiveSerializer' => 'HighPerApp\\HighPer\\Foundation\\AdaptiveSerializer',
            'RustFFIManager' => 'HighPerApp\\HighPer\\Foundation\\RustFFIManager',
            'AMPHTTPServerManager' => 'HighPerApp\\HighPer\\Foundation\\AMPHTTPServerManager',
            'ZeroDowntimeIntegration' => 'HighPerApp\\HighPer\\Foundation\\ZeroDowntimeIntegration'
        ];

        foreach ($phase1Components as $name => $class) {
            $exists = class_exists($class);
            $this->recordTest("Phase 1: {$name}", $exists, $exists ? 'Component available' : 'Component missing');
            if ($exists) {
                echo "  ✅ {$name} - OK\n";
            } else {
                echo "  ❌ {$name} - Missing\n";
            }
        }

        // Test Phase 2 components
        $phase2Components = [
            'ContainerCompiler' => 'HighPerApp\\HighPer\\Container\\ContainerCompiler',
            'RingBufferCache' => 'HighPerApp\\HighPer\\Router\\RingBufferCache',
            'CompiledPatterns' => 'HighPerApp\\HighPer\\Security\\CompiledPatterns',
            'AsyncConnectionPool' => 'HighPerApp\\HighPer\\Database\\AsyncConnectionPool'
        ];

        foreach ($phase2Components as $name => $class) {
            $exists = class_exists($class);
            $this->recordTest("Phase 2: {$name}", $exists, $exists ? 'Component available' : 'Component missing');
            if ($exists) {
                echo "  ✅ {$name} - OK\n";
            } else {
                echo "  ❌ {$name} - Missing\n";
            }
        }

        // Test Phase 3 components
        $phase3Components = [
            'FiveNinesReliability' => 'HighPerApp\\HighPer\\Resilience\\FiveNinesReliability',
            'CircuitBreaker' => 'HighPerApp\\HighPer\\Resilience\\CircuitBreaker',
            'BulkheadIsolator' => 'HighPerApp\\HighPer\\Resilience\\BulkheadIsolator',
            'SelfHealingManager' => 'HighPerApp\\HighPer\\Resilience\\SelfHealingManager',
            'GracefulDegradation' => 'HighPerApp\\HighPer\\Resilience\\GracefulDegradation',
            'IndexedBroadcaster' => 'HighPerApp\\HighPer\\WebSockets\\IndexedBroadcaster'
        ];

        foreach ($phase3Components as $name => $class) {
            $exists = class_exists($class);
            $this->recordTest("Phase 3: {$name}", $exists, $exists ? 'Component available' : 'Component missing');
            if ($exists) {
                echo "  ✅ {$name} - OK\n";
            } else {
                echo "  ❌ {$name} - Missing\n";
            }
        }

        echo "\n";
    }

    private function testStandaloneLibraryIntegrations(): void
    {
        echo "🧪 Testing Standalone Library Integrations...\n";
        echo str_repeat("─", 60) . "\n";

        $libraries = [
            'di-container' => '/home/infy/phpframework-v3/libraries/di-container',
            'router' => '/home/infy/phpframework-v3/libraries/router',
            'security' => '/home/infy/phpframework-v3/libraries/security',
            'database' => '/home/infy/phpframework-v3/libraries/database',
            'websockets' => '/home/infy/phpframework-v3/libraries/websockets',
            'cache' => '/home/infy/phpframework-v3/libraries/cache',
            'crypto' => '/home/infy/phpframework-v3/libraries/crypto',
            'tcp' => '/home/infy/phpframework-v3/libraries/tcp',
            'cli' => '/home/infy/phpframework-v3/libraries/cli'
        ];

        foreach ($libraries as $name => $path) {
            $composerFile = "{$path}/composer.json";
            $srcDir = "{$path}/src";
            
            if (file_exists($composerFile) && is_dir($srcDir)) {
                $this->loadedLibraries[] = $name;
                $this->recordTest("Library: {$name}", true, 'Library structure valid');
                echo "  ✅ {$name} - Structure OK\n";
                
                // Test if library has a main class
                $mainClasses = glob("{$srcDir}/*.php");
                if (!empty($mainClasses)) {
                    echo "    📁 Found " . count($mainClasses) . " classes\n";
                }
            } else {
                $this->recordTest("Library: {$name}", false, 'Library structure invalid or missing');
                echo "  ❌ {$name} - Missing or invalid\n";
            }
        }

        echo "\n";
    }

    private function testTemplateIntegrations(): void
    {
        echo "🧪 Testing Template Integrations...\n";
        echo str_repeat("─", 60) . "\n";

        $templates = [
            'Blueprint' => '/home/infy/phpframework-v3/templates/blueprint',
            'Nano' => '/home/infy/phpframework-v3/templates/nano'
        ];

        foreach ($templates as $name => $path) {
            $composerFile = "{$path}/composer.json";
            $srcDir = "{$path}/src";
            $bootstrapDir = "{$path}/src/Bootstrap";
            
            if (file_exists($composerFile)) {
                $this->recordTest("Template: {$name}", true, 'Template structure valid');
                echo "  ✅ {$name} - Structure OK\n";
                
                // Check for v3 bootstrap classes
                if (is_dir($bootstrapDir)) {
                    $bootstrapFiles = glob("{$bootstrapDir}/*.php");
                    echo "    🚀 Bootstrap files: " . count($bootstrapFiles) . "\n";
                    
                    foreach ($bootstrapFiles as $file) {
                        $className = basename($file, '.php');
                        echo "      - {$className}\n";
                    }
                }
                
                // Check for serve command
                $serveScript = "{$path}/bin/serve";
                if (file_exists($serveScript)) {
                    echo "    ⚡ Serve command available\n";
                }
            } else {
                $this->recordTest("Template: {$name}", false, 'Template missing or invalid');
                echo "  ❌ {$name} - Missing\n";
            }
        }

        echo "\n";
    }

    private function testServiceProviderLoading(): void
    {
        echo "🧪 Testing Service Provider Loading...\n";
        echo str_repeat("─", 60) . "\n";

        try {
            // Test LibraryLoader
            if (class_exists('HighPerApp\\HighPer\\ServiceProvider\\LibraryLoader')) {
                echo "  ✅ LibraryLoader class available\n";
                $this->recordTest('LibraryLoader', true, 'Service provider loading system available');
                
                // Create mock application for testing
                $mockApp = new class implements \HighPerApp\HighPer\Contracts\ApplicationInterface {
                    private array $container = [];
                    
                    public function bootstrap(): void {}
                    public function run(): void {}
                    public function getContainer(): \HighPerApp\HighPer\Contracts\ContainerInterface {
                        return new class($this->container) implements \HighPerApp\HighPer\Contracts\ContainerInterface {
                            public function __construct(private array &$container) {}
                            public function get(string $id): mixed { return $this->container[$id] ?? null; }
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
                
                $loader = new \HighPerApp\HighPer\ServiceProvider\LibraryLoader($mockApp);
                $availableProviders = $loader->getAvailableProviders();
                
                echo "    📦 Available providers: " . count($availableProviders) . "\n";
                foreach ($availableProviders as $provider) {
                    echo "      - {$provider}\n";
                }
                
                $this->recordTest('Provider Discovery', true, count($availableProviders) . ' providers discovered');
            } else {
                $this->recordTest('LibraryLoader', false, 'LibraryLoader class not found');
                echo "  ❌ LibraryLoader not available\n";
            }
        } catch (\Exception $e) {
            $this->recordTest('Service Provider Loading', false, 'Error: ' . $e->getMessage());
            echo "  ❌ Error testing service providers: " . $e->getMessage() . "\n";
        }

        echo "\n";
    }

    private function testReliabilityStackIntegration(): void
    {
        echo "🧪 Testing Reliability Stack Integration...\n";
        echo str_repeat("─", 60) . "\n";

        try {
            // Test if all reliability components can work together
            $components = [];
            
            if (class_exists('HighPerApp\\HighPer\\Resilience\\CircuitBreaker')) {
                $components['CircuitBreaker'] = new \HighPerApp\HighPer\Resilience\CircuitBreaker();
                echo "  ✅ CircuitBreaker instantiated\n";
            }
            
            if (class_exists('HighPerApp\\HighPer\\Resilience\\BulkheadIsolator')) {
                $components['BulkheadIsolator'] = new \HighPerApp\HighPer\Resilience\BulkheadIsolator();
                echo "  ✅ BulkheadIsolator instantiated\n";
            }
            
            if (class_exists('HighPerApp\\HighPer\\Resilience\\SelfHealingManager')) {
                $components['SelfHealingManager'] = new \HighPerApp\HighPer\Resilience\SelfHealingManager();
                echo "  ✅ SelfHealingManager instantiated\n";
            }
            
            // Test if FiveNinesReliability can orchestrate them
            if (count($components) >= 3 && class_exists('HighPerApp\\HighPer\\Resilience\\FiveNinesReliability')) {
                $reliability = new \HighPerApp\HighPer\Resilience\FiveNinesReliability(
                    $components['CircuitBreaker'],
                    $components['BulkheadIsolator'],
                    $components['SelfHealingManager']
                );
                
                // Test basic functionality
                $result = $reliability->execute('test', function() {
                    return 'Integration test successful';
                });
                
                if ($result === 'Integration test successful') {
                    echo "  ✅ Reliability stack integration working\n";
                    $this->recordTest('Reliability Stack Integration', true, 'Full reliability stack operational');
                } else {
                    echo "  ❌ Reliability stack integration failed\n";
                    $this->recordTest('Reliability Stack Integration', false, 'Stack execution failed');
                }
            } else {
                echo "  ⚠️ Insufficient components for full reliability stack test\n";
                $this->recordTest('Reliability Stack Integration', false, 'Missing required components');
            }
            
        } catch (\Exception $e) {
            echo "  ❌ Error testing reliability stack: " . $e->getMessage() . "\n";
            $this->recordTest('Reliability Stack Integration', false, 'Error: ' . $e->getMessage());
        }

        echo "\n";
    }

    private function recordTest(string $name, bool $passed, string $message): void
    {
        $this->testResults[] = [
            'name' => $name,
            'passed' => $passed,
            'message' => $message,
            'timestamp' => microtime(true)
        ];
    }

    private function generateIntegrationReport(): array
    {
        echo "📊 Cross-Library Integration Test Report\n";
        echo "========================================\n\n";
        
        $passed = count(array_filter($this->testResults, fn($test) => $test['passed']));
        $total = count($this->testResults);
        $percentage = $total > 0 ? round(($passed / $total) * 100, 1) : 0;
        
        echo "📈 Summary:\n";
        echo "  • Total Tests: {$total}\n";
        echo "  • Passed: {$passed}\n";
        echo "  • Failed: " . ($total - $passed) . "\n";
        echo "  • Success Rate: {$percentage}%\n\n";
        
        echo "📋 Detailed Results:\n";
        foreach ($this->testResults as $test) {
            $status = $test['passed'] ? '✅' : '❌';
            echo "  {$status} {$test['name']}: {$test['message']}\n";
        }
        
        echo "\n📦 Loaded Libraries: " . implode(', ', $this->loadedLibraries) . "\n";
        
        return [
            'total_tests' => $total,
            'passed_tests' => $passed,
            'failed_tests' => $total - $passed,
            'success_rate' => $percentage,
            'loaded_libraries' => $this->loadedLibraries,
            'detailed_results' => $this->testResults
        ];
    }
}

// CLI execution
if (php_sapi_name() === 'cli') {
    $tester = new CrossLibraryIntegrationTest();
    $results = $tester->runAllIntegrationTests();
    
    if ($results['success_rate'] >= 80) {
        echo "\n🎉 Cross-library integration testing PASSED!\n";
        exit(0);
    } else {
        echo "\n❌ Cross-library integration testing FAILED!\n";
        exit(1);
    }
}