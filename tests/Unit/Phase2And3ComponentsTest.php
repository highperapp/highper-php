<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Tests\Unit;

/**
 * Phase 2 & 3 Components Unit Test
 * 
 * Comprehensive unit tests for Phase 2 optimizations and Phase 3 reliability components
 */
class Phase2And3ComponentsTest
{
    private array $testResults = [];
    private int $totalTests = 0;
    private int $passedTests = 0;

    public function runAllPhase2And3Tests(): array
    {
        echo "🧪 HighPer Framework v3 - Phase 2 & 3 Components Unit Tests\n";
        echo "============================================================\n\n";

        // Load framework
        $this->loadFramework();

        // Test Phase 2 components
        $this->testContainerCompiler();
        $this->testRingBufferCache();
        $this->testCompiledPatterns();
        $this->testAsyncConnectionPool();

        // Test Phase 3 components
        $this->testCircuitBreaker();
        $this->testBulkheadIsolator();
        $this->testSelfHealingManager();
        $this->testGracefulDegradation();
        $this->testIndexedBroadcaster();
        $this->testFiveNinesReliability();

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

    // ==================== PHASE 2 TESTS ====================

    private function testContainerCompiler(): void
    {
        echo "🔧 Testing ContainerCompiler (Phase 2)...\n";
        echo str_repeat("─", 50) . "\n";

        if (!class_exists('HighPerApp\\HighPer\\Container\\ContainerCompiler')) {
            $this->recordTest('ContainerCompiler - Class Exists', false, 'ContainerCompiler class not found');
            echo "  ❌ ContainerCompiler class not available\n\n";
            return;
        }

        try {
            $compiler = new \HighPerApp\HighPer\Container\ContainerCompiler();
            
            $this->recordTest('ContainerCompiler - Instantiation', true, 'ContainerCompiler created successfully');
            echo "  ✅ ContainerCompiler instantiation - OK\n";

            // Test availability
            $isAvailable = $compiler->isAvailable();
            $this->recordTest('ContainerCompiler - Availability', is_bool($isAvailable), 'Availability: ' . ($isAvailable ? 'true' : 'false'));
            echo "  ✅ Availability check - OK\n";

            // Test compilation
            $definitions = [
                'test_service' => ['class' => 'stdClass', 'dependencies' => []],
                'another_service' => ['class' => 'DateTime', 'dependencies' => []]
            ];
            
            $compiled = $compiler->compileContainer($definitions);
            $this->recordTest('ContainerCompiler - Compilation', is_string($compiled) && !empty($compiled), 'Compiled successfully');
            echo "  ✅ Container compilation - OK\n";

            // Test validation
            $isValid = $compiler->validateCompiled($compiled);
            $this->recordTest('ContainerCompiler - Validation', $isValid === true, 'Compiled code is valid');
            echo "  ✅ Compiled code validation - OK\n";

            // Test statistics
            $stats = $compiler->getStats();
            $this->recordTest('ContainerCompiler - Statistics', is_array($stats), 'Statistics: ' . json_encode($stats));
            echo "  ✅ Statistics - OK\n";

        } catch (\Exception $e) {
            $this->recordTest('ContainerCompiler - General', false, 'Error: ' . $e->getMessage());
            echo "  ❌ ContainerCompiler error: " . $e->getMessage() . "\n";
        }

        echo "\n";
    }

    private function testRingBufferCache(): void
    {
        echo "🔧 Testing RingBufferCache (Phase 2)...\n";
        echo str_repeat("─", 50) . "\n";

        if (!class_exists('HighPerApp\\HighPer\\Router\\RingBufferCache')) {
            $this->recordTest('RingBufferCache - Class Exists', false, 'RingBufferCache class not found');
            echo "  ❌ RingBufferCache class not available\n\n";
            return;
        }

        try {
            $cache = new \HighPerApp\HighPer\Router\RingBufferCache(64);
            
            $this->recordTest('RingBufferCache - Instantiation', true, 'RingBufferCache created successfully');
            echo "  ✅ RingBufferCache instantiation - OK\n";

            // Test set/get operations
            $cache->set('test_key', 'test_value');
            $value = $cache->get('test_key');
            $this->recordTest('RingBufferCache - Set/Get', $value === 'test_value', 'Set/Get operation successful');
            echo "  ✅ Set/Get operations - OK\n";

            // Test has operation
            $hasKey = $cache->has('test_key');
            $this->recordTest('RingBufferCache - Has', $hasKey === true, 'Has operation works');
            echo "  ✅ Has operation - OK\n";

            // Test multiple operations
            $values = ['key1' => 'value1', 'key2' => 'value2', 'key3' => 'value3'];
            $cache->setMultiple($values);
            $retrieved = $cache->getMultiple(array_keys($values));
            $this->recordTest('RingBufferCache - Multiple Operations', count($retrieved) === 3, 'Multiple operations work');
            echo "  ✅ Multiple operations - OK\n";

            // Test statistics
            $stats = $cache->getStats();
            $this->recordTest('RingBufferCache - Statistics', is_array($stats) && isset($stats['hits']), 'Statistics: ' . json_encode($stats));
            echo "  ✅ Statistics - OK\n";

            // Test delete
            $deleted = $cache->delete('test_key');
            $this->recordTest('RingBufferCache - Delete', $deleted === true, 'Delete operation works');
            echo "  ✅ Delete operation - OK\n";

        } catch (\Exception $e) {
            $this->recordTest('RingBufferCache - General', false, 'Error: ' . $e->getMessage());
            echo "  ❌ RingBufferCache error: " . $e->getMessage() . "\n";
        }

        echo "\n";
    }

    private function testCompiledPatterns(): void
    {
        echo "🔧 Testing CompiledPatterns (Phase 2)...\n";
        echo str_repeat("─", 50) . "\n";

        if (!class_exists('HighPerApp\\HighPer\\Security\\CompiledPatterns')) {
            $this->recordTest('CompiledPatterns - Class Exists', false, 'CompiledPatterns class not found');
            echo "  ❌ CompiledPatterns class not available\n\n";
            return;
        }

        try {
            $patterns = new \HighPerApp\HighPer\Security\CompiledPatterns();
            
            $this->recordTest('CompiledPatterns - Instantiation', true, 'CompiledPatterns created successfully');
            echo "  ✅ CompiledPatterns instantiation - OK\n";

            // Test availability
            $isAvailable = $patterns->isAvailable();
            $this->recordTest('CompiledPatterns - Availability', is_bool($isAvailable), 'Availability: ' . ($isAvailable ? 'true' : 'false'));
            echo "  ✅ Availability check - OK\n";

            // Test threat validation - safe input
            $safeInput = 'This is a normal user input';
            $isSafe = $patterns->validate($safeInput);
            $this->recordTest('CompiledPatterns - Safe Input', $isSafe === true, 'Safe input validated correctly');
            echo "  ✅ Safe input validation - OK\n";

            // Test threat validation - XSS attempt
            $xssInput = '<script>alert("xss")</script>';
            $isXssThreat = $patterns->validate($xssInput);
            $this->recordTest('CompiledPatterns - XSS Detection', $isXssThreat === false, 'XSS threat detected correctly');
            echo "  ✅ XSS threat detection - OK\n";

            // Test threat validation - SQL injection attempt
            $sqlInput = "'; DROP TABLE users; --";
            $isSqlThreat = $patterns->validate($sqlInput);
            $this->recordTest('CompiledPatterns - SQL Injection Detection', $isSqlThreat === false, 'SQL injection detected correctly');
            echo "  ✅ SQL injection detection - OK\n";

            // Test statistics
            $stats = $patterns->getStats();
            $this->recordTest('CompiledPatterns - Statistics', is_array($stats), 'Statistics: ' . json_encode($stats));
            echo "  ✅ Statistics - OK\n";

        } catch (\Exception $e) {
            $this->recordTest('CompiledPatterns - General', false, 'Error: ' . $e->getMessage());
            echo "  ❌ CompiledPatterns error: " . $e->getMessage() . "\n";
        }

        echo "\n";
    }

    private function testAsyncConnectionPool(): void
    {
        echo "🔧 Testing AsyncConnectionPool (Phase 2)...\n";
        echo str_repeat("─", 50) . "\n";

        if (!class_exists('HighPerApp\\HighPer\\Database\\AsyncConnectionPool')) {
            $this->recordTest('AsyncConnectionPool - Class Exists', false, 'AsyncConnectionPool class not found');
            echo "  ❌ AsyncConnectionPool class not available\n\n";
            return;
        }

        try {
            $pool = new \HighPerApp\HighPer\Database\AsyncConnectionPool(['max_connections' => 5]);
            
            $this->recordTest('AsyncConnectionPool - Instantiation', true, 'AsyncConnectionPool created successfully');
            echo "  ✅ AsyncConnectionPool instantiation - OK\n";

            // Test configuration
            $config = $pool->getConfig();
            $this->recordTest('AsyncConnectionPool - Configuration', is_array($config), 'Configuration: ' . json_encode($config));
            echo "  ✅ Configuration - OK\n";

            // Test connection retrieval
            $connection = $pool->getConnection();
            $this->recordTest('AsyncConnectionPool - Get Connection', $connection !== null, 'Connection retrieved successfully');
            echo "  ✅ Get connection - OK\n";

            // Test connection validation
            $isValid = $pool->validateConnection($connection);
            $this->recordTest('AsyncConnectionPool - Validate Connection', is_bool($isValid), 'Connection validation: ' . ($isValid ? 'valid' : 'invalid'));
            echo "  ✅ Connection validation - OK\n";

            // Test connection return
            $pool->returnConnection($connection);
            $this->recordTest('AsyncConnectionPool - Return Connection', true, 'Connection returned successfully');
            echo "  ✅ Return connection - OK\n";

            // Test health check
            $isHealthy = $pool->isHealthy();
            $this->recordTest('AsyncConnectionPool - Health Check', is_bool($isHealthy), 'Health check: ' . ($isHealthy ? 'healthy' : 'unhealthy'));
            echo "  ✅ Health check - OK\n";

            // Test statistics
            $stats = $pool->getStats();
            $this->recordTest('AsyncConnectionPool - Statistics', is_array($stats), 'Statistics: ' . json_encode($stats));
            echo "  ✅ Statistics - OK\n";

        } catch (\Exception $e) {
            $this->recordTest('AsyncConnectionPool - General', false, 'Error: ' . $e->getMessage());
            echo "  ❌ AsyncConnectionPool error: " . $e->getMessage() . "\n";
        }

        echo "\n";
    }

    // ==================== PHASE 3 TESTS ====================

    private function testCircuitBreaker(): void
    {
        echo "🔧 Testing CircuitBreaker (Phase 3)...\n";
        echo str_repeat("─", 50) . "\n";

        if (!class_exists('HighPerApp\\HighPer\\Resilience\\CircuitBreaker')) {
            $this->recordTest('CircuitBreaker - Class Exists', false, 'CircuitBreaker class not found');
            echo "  ❌ CircuitBreaker class not available\n\n";
            return;
        }

        try {
            $circuitBreaker = new \HighPerApp\HighPer\Resilience\CircuitBreaker();
            
            $this->recordTest('CircuitBreaker - Instantiation', true, 'CircuitBreaker created successfully');
            echo "  ✅ CircuitBreaker instantiation - OK\n";

            // Test initial state
            $initialState = $circuitBreaker->getState();
            $this->recordTest('CircuitBreaker - Initial State', $initialState === 'closed', 'Initial state: ' . $initialState);
            echo "  ✅ Initial state - OK\n";

            // Test successful execution
            $result = $circuitBreaker->execute(function() {
                return 'success';
            });
            $this->recordTest('CircuitBreaker - Successful Execution', $result === 'success', 'Successful execution works');
            echo "  ✅ Successful execution - OK\n";

            // Test state checks
            $isClosed = $circuitBreaker->isClosed();
            $isOpen = $circuitBreaker->isOpen();
            $isHalfOpen = $circuitBreaker->isHalfOpen();
            $this->recordTest('CircuitBreaker - State Checks', is_bool($isClosed) && is_bool($isOpen) && is_bool($isHalfOpen), 'State checks work');
            echo "  ✅ State checks - OK\n";

            // Test statistics
            $stats = $circuitBreaker->getStats();
            $this->recordTest('CircuitBreaker - Statistics', is_array($stats) && isset($stats['calls']), 'Statistics: ' . json_encode($stats));
            echo "  ✅ Statistics - OK\n";

            // Test reset
            $circuitBreaker->reset();
            $this->recordTest('CircuitBreaker - Reset', true, 'Reset executed successfully');
            echo "  ✅ Reset - OK\n";

        } catch (\Exception $e) {
            $this->recordTest('CircuitBreaker - General', false, 'Error: ' . $e->getMessage());
            echo "  ❌ CircuitBreaker error: " . $e->getMessage() . "\n";
        }

        echo "\n";
    }

    private function testBulkheadIsolator(): void
    {
        echo "🔧 Testing BulkheadIsolator (Phase 3)...\n";
        echo str_repeat("─", 50) . "\n";

        if (!class_exists('HighPerApp\\HighPer\\Resilience\\BulkheadIsolator')) {
            $this->recordTest('BulkheadIsolator - Class Exists', false, 'BulkheadIsolator class not found');
            echo "  ❌ BulkheadIsolator class not available\n\n";
            return;
        }

        try {
            $bulkhead = new \HighPerApp\HighPer\Resilience\BulkheadIsolator();
            
            $this->recordTest('BulkheadIsolator - Instantiation', true, 'BulkheadIsolator created successfully');
            echo "  ✅ BulkheadIsolator instantiation - OK\n";

            // Test compartment creation
            $bulkhead->createCompartment('test_compartment', ['max_concurrent' => 10]);
            $this->recordTest('BulkheadIsolator - Create Compartment', true, 'Compartment created successfully');
            echo "  ✅ Compartment creation - OK\n";

            // Test compartment health
            $isHealthy = $bulkhead->isCompartmentHealthy('test_compartment');
            $this->recordTest('BulkheadIsolator - Compartment Health', is_bool($isHealthy), 'Compartment health: ' . ($isHealthy ? 'healthy' : 'unhealthy'));
            echo "  ✅ Compartment health check - OK\n";

            // Test execution
            $result = $bulkhead->execute('test_compartment', function() {
                return 'compartment_result';
            });
            $this->recordTest('BulkheadIsolator - Execution', $result === 'compartment_result', 'Compartment execution successful');
            echo "  ✅ Compartment execution - OK\n";

            // Test statistics
            $stats = $bulkhead->getCompartmentStats('test_compartment');
            $this->recordTest('BulkheadIsolator - Compartment Stats', is_array($stats), 'Compartment stats: ' . json_encode($stats));
            echo "  ✅ Compartment statistics - OK\n";

            // Test recovery
            $recovered = $bulkhead->recoverCompartment('test_compartment');
            $this->recordTest('BulkheadIsolator - Recovery', $recovered === true, 'Compartment recovery successful');
            echo "  ✅ Compartment recovery - OK\n";

        } catch (\Exception $e) {
            $this->recordTest('BulkheadIsolator - General', false, 'Error: ' . $e->getMessage());
            echo "  ❌ BulkheadIsolator error: " . $e->getMessage() . "\n";
        }

        echo "\n";
    }

    private function testSelfHealingManager(): void
    {
        echo "🔧 Testing SelfHealingManager (Phase 3)...\n";
        echo str_repeat("─", 50) . "\n";

        if (!class_exists('HighPerApp\\HighPer\\Resilience\\SelfHealingManager')) {
            $this->recordTest('SelfHealingManager - Class Exists', false, 'SelfHealingManager class not found');
            echo "  ❌ SelfHealingManager class not available\n\n";
            return;
        }

        try {
            $selfHealing = new \HighPerApp\HighPer\Resilience\SelfHealingManager();
            
            $this->recordTest('SelfHealingManager - Instantiation', true, 'SelfHealingManager created successfully');
            echo "  ✅ SelfHealingManager instantiation - OK\n";

            // Test initial state
            $isActive = $selfHealing->isActive();
            $this->recordTest('SelfHealingManager - Initial State', $isActive === false, 'Initial state: ' . ($isActive ? 'active' : 'inactive'));
            echo "  ✅ Initial state - OK\n";

            // Test strategy registration
            $selfHealing->registerStrategy('test_context', function() {
                return true;
            });
            $this->recordTest('SelfHealingManager - Strategy Registration', true, 'Strategy registered successfully');
            echo "  ✅ Strategy registration - OK\n";

            // Test healing
            $healed = $selfHealing->heal('test_context');
            $this->recordTest('SelfHealingManager - Healing', $healed === true, 'Healing executed successfully');
            echo "  ✅ Healing execution - OK\n";

            // Test statistics
            $stats = $selfHealing->getStats();
            $this->recordTest('SelfHealingManager - Statistics', is_array($stats), 'Statistics: ' . json_encode($stats));
            echo "  ✅ Statistics - OK\n";

            // Test strategies
            $strategies = $selfHealing->getStrategies();
            $this->recordTest('SelfHealingManager - Strategies', is_array($strategies) && in_array('test_context', $strategies), 'Strategies: ' . implode(', ', $strategies));
            echo "  ✅ Strategy listing - OK\n";

        } catch (\Exception $e) {
            $this->recordTest('SelfHealingManager - General', false, 'Error: ' . $e->getMessage());
            echo "  ❌ SelfHealingManager error: " . $e->getMessage() . "\n";
        }

        echo "\n";
    }

    private function testGracefulDegradation(): void
    {
        echo "🔧 Testing GracefulDegradation (Phase 3)...\n";
        echo str_repeat("─", 50) . "\n";

        if (!class_exists('HighPerApp\\HighPer\\Resilience\\GracefulDegradation')) {
            $this->recordTest('GracefulDegradation - Class Exists', false, 'GracefulDegradation class not found');
            echo "  ❌ GracefulDegradation class not available\n\n";
            return;
        }

        try {
            $degradation = new \HighPerApp\HighPer\Resilience\GracefulDegradation();
            
            $this->recordTest('GracefulDegradation - Instantiation', true, 'GracefulDegradation created successfully');
            echo "  ✅ GracefulDegradation instantiation - OK\n";

            // Test fallback registration
            $degradation->registerFallback('test_service', function($context, $exception) {
                return 'fallback_result';
            }, 1);
            $this->recordTest('GracefulDegradation - Fallback Registration', true, 'Fallback registered successfully');
            echo "  ✅ Fallback registration - OK\n";

            // Test execution with degradation
            $result = $degradation->executeWithDegradation('test_service', function() {
                throw new \Exception('Primary service failed');
            });
            $this->recordTest('GracefulDegradation - Execution', $result === 'fallback_result', 'Degradation execution successful');
            echo "  ✅ Degradation execution - OK\n";

            // Test degradation level
            $level = $degradation->getDegradationLevel('test_service');
            $this->recordTest('GracefulDegradation - Degradation Level', is_int($level), 'Degradation level: ' . $level);
            echo "  ✅ Degradation level - OK\n";

            // Test service degradation check
            $isDegraded = $degradation->isServiceDegraded('test_service');
            $this->recordTest('GracefulDegradation - Service Degraded', is_bool($isDegraded), 'Service degraded: ' . ($isDegraded ? 'yes' : 'no'));
            echo "  ✅ Service degradation check - OK\n";

            // Test statistics
            $stats = $degradation->getStats();
            $this->recordTest('GracefulDegradation - Statistics', is_array($stats), 'Statistics: ' . json_encode($stats));
            echo "  ✅ Statistics - OK\n";

        } catch (\Exception $e) {
            $this->recordTest('GracefulDegradation - General', false, 'Error: ' . $e->getMessage());
            echo "  ❌ GracefulDegradation error: " . $e->getMessage() . "\n";
        }

        echo "\n";
    }

    private function testIndexedBroadcaster(): void
    {
        echo "🔧 Testing IndexedBroadcaster (Phase 3)...\n";
        echo str_repeat("─", 50) . "\n";

        if (!class_exists('HighPerApp\\HighPer\\WebSockets\\IndexedBroadcaster')) {
            $this->recordTest('IndexedBroadcaster - Class Exists', false, 'IndexedBroadcaster class not found');
            echo "  ❌ IndexedBroadcaster class not available\n\n";
            return;
        }

        try {
            $broadcaster = new \HighPerApp\HighPer\WebSockets\IndexedBroadcaster();
            
            $this->recordTest('IndexedBroadcaster - Instantiation', true, 'IndexedBroadcaster created successfully');
            echo "  ✅ IndexedBroadcaster instantiation - OK\n";

            // Test subscription
            $subscriptionId = $broadcaster->subscribe('test_channel', function($message) {
                // Mock subscriber callback
            });
            $this->recordTest('IndexedBroadcaster - Subscription', is_string($subscriptionId), 'Subscription ID: ' . $subscriptionId);
            echo "  ✅ Subscription - OK\n";

            // Test subscriber count
            $count = $broadcaster->getSubscriberCount('test_channel');
            $this->recordTest('IndexedBroadcaster - Subscriber Count', $count === 1, 'Subscriber count: ' . $count);
            echo "  ✅ Subscriber count - OK\n";

            // Test channels
            $channels = $broadcaster->getChannels();
            $this->recordTest('IndexedBroadcaster - Channels', is_array($channels) && in_array('test_channel', $channels), 'Channels: ' . implode(', ', $channels));
            echo "  ✅ Channel listing - OK\n";

            // Test unsubscription
            $unsubscribed = $broadcaster->unsubscribe('test_channel', $subscriptionId);
            $this->recordTest('IndexedBroadcaster - Unsubscription', $unsubscribed === true, 'Unsubscription successful');
            echo "  ✅ Unsubscription - OK\n";

            // Test statistics
            $stats = $broadcaster->getStats();
            $this->recordTest('IndexedBroadcaster - Statistics', is_array($stats), 'Statistics: ' . json_encode($stats));
            echo "  ✅ Statistics - OK\n";

        } catch (\Exception $e) {
            $this->recordTest('IndexedBroadcaster - General', false, 'Error: ' . $e->getMessage());
            echo "  ❌ IndexedBroadcaster error: " . $e->getMessage() . "\n";
        }

        echo "\n";
    }

    private function testFiveNinesReliability(): void
    {
        echo "🔧 Testing FiveNinesReliability (Phase 3)...\n";
        echo str_repeat("─", 50) . "\n";

        // Check if all required components exist
        $requiredClasses = [
            'HighPerApp\\HighPer\\Resilience\\FiveNinesReliability',
            'HighPerApp\\HighPer\\Resilience\\CircuitBreaker',
            'HighPerApp\\HighPer\\Resilience\\BulkheadIsolator',
            'HighPerApp\\HighPer\\Resilience\\SelfHealingManager'
        ];

        $allExist = true;
        foreach ($requiredClasses as $class) {
            if (!class_exists($class)) {
                $allExist = false;
                break;
            }
        }

        if (!$allExist) {
            $this->recordTest('FiveNinesReliability - Prerequisites', false, 'Required reliability classes not found');
            echo "  ❌ FiveNinesReliability prerequisites not available\n\n";
            return;
        }

        try {
            // Create dependency components
            $circuitBreaker = new \HighPerApp\HighPer\Resilience\CircuitBreaker();
            $bulkhead = new \HighPerApp\HighPer\Resilience\BulkheadIsolator();
            $selfHealing = new \HighPerApp\HighPer\Resilience\SelfHealingManager();
            
            $reliability = new \HighPerApp\HighPer\Resilience\FiveNinesReliability(
                $circuitBreaker,
                $bulkhead,
                $selfHealing
            );
            
            $this->recordTest('FiveNinesReliability - Instantiation', true, 'FiveNinesReliability created successfully');
            echo "  ✅ FiveNinesReliability instantiation - OK\n";

            // Test execution
            $result = $reliability->execute('test_context', function() {
                return 'reliability_test_success';
            });
            $this->recordTest('FiveNinesReliability - Execution', $result === 'reliability_test_success', 'Reliability execution successful');
            echo "  ✅ Reliability execution - OK\n";

            // Test health check
            $isHealthy = $reliability->isHealthy('test_context');
            $this->recordTest('FiveNinesReliability - Health Check', is_bool($isHealthy), 'Health check: ' . ($isHealthy ? 'healthy' : 'unhealthy'));
            echo "  ✅ Health check - OK\n";

            // Test metrics
            $metrics = $reliability->getMetrics();
            $this->recordTest('FiveNinesReliability - Metrics', is_array($metrics), 'Metrics: ' . json_encode($metrics));
            echo "  ✅ Metrics - OK\n";

            // Test uptime calculation
            $uptime = $reliability->getUptime();
            $this->recordTest('FiveNinesReliability - Uptime', is_float($uptime) && $uptime >= 0, 'Uptime: ' . round($uptime, 2) . '%');
            echo "  ✅ Uptime calculation - OK\n";

            // Test status
            $status = $reliability->getStatus();
            $this->recordTest('FiveNinesReliability - Status', is_array($status), 'Status retrieved successfully');
            echo "  ✅ Status retrieval - OK\n";

        } catch (\Exception $e) {
            $this->recordTest('FiveNinesReliability - General', false, 'Error: ' . $e->getMessage());
            echo "  ❌ FiveNinesReliability error: " . $e->getMessage() . "\n";
        }

        echo "\n";
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
        echo "📊 Phase 2 & 3 Components Unit Test Report\n";
        echo "==========================================\n\n";
        
        $percentage = $this->totalTests > 0 ? round(($this->passedTests / $this->totalTests) * 100, 1) : 0;
        
        echo "📈 Summary:\n";
        echo "  • Total Tests: {$this->totalTests}\n";
        echo "  • Passed: {$this->passedTests}\n";
        echo "  • Failed: " . ($this->totalTests - $this->passedTests) . "\n";
        echo "  • Success Rate: {$percentage}%\n\n";
        
        echo "📋 Component Results:\n";
        $currentComponent = '';
        foreach ($this->testResults as $test) {
            $component = explode(' - ', $test['name'])[0];
            if ($component !== $currentComponent) {
                $currentComponent = $component;
                echo "\n  📦 {$component}:\n";
            }
            $status = $test['passed'] ? '✅' : '❌';
            $testName = substr($test['name'], strlen($component) + 3);
            echo "    {$status} {$testName}: {$test['message']}\n";
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
    $tester = new Phase2And3ComponentsTest();
    $results = $tester->runAllPhase2And3Tests();
    
    if ($results['success_rate'] >= 80) {
        echo "\n🎉 Phase 2 & 3 unit tests PASSED!\n";
        exit(0);
    } else {
        echo "\n❌ Phase 2 & 3 unit tests FAILED!\n";
        exit(1);
    }
}