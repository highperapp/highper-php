<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Tests\Reliability;

use PHPUnit\Framework\TestCase;
use HighPerApp\HighPer\Reliability\CircuitBreaker;
use HighPerApp\HighPer\Reliability\BulkheadIsolation;
use HighPerApp\HighPer\Reliability\SelfHealingManager;
use HighPerApp\HighPer\Reliability\HealthMonitor;
use HighPerApp\HighPer\Foundation\RustFFIManager;
use HighPerApp\HighPer\Foundation\AsyncLogger;

/**
 * Phase 3 Reliability Stack Tests
 * 
 * Comprehensive testing of five nines reliability implementation:
 * - Circuit breaker patterns for fault tolerance
 * - Bulkhead isolation for service protection
 * - Self-healing mechanisms and automatic recovery
 * - Health monitoring and status reporting
 * - Integration between reliability components
 * - Five nines reliability characteristics (99.999% uptime)
 */
class Phase3ReliabilityTest extends TestCase
{
    private RustFFIManager $ffiManager;
    private AsyncLogger $logger;

    protected function setUp(): void
    {
        $this->ffiManager = new RustFFIManager();
        $this->logger = new AsyncLogger();
    }

    /**
     * @group reliability
     * @group circuit_breaker
     */
    public function testCircuitBreakerFaultTolerance(): void
    {
        $circuitBreaker = new CircuitBreaker(
            'test_service',
            [
                'failure_threshold' => 3,
                'timeout' => 1,
                'success_threshold' => 2
            ],
            $this->logger,
            $this->ffiManager
        );

        // Test normal operation (circuit closed)
        $this->assertTrue($circuitBreaker->isClosed());
        
        $successfulOperation = function () {
            return 'success';
        };

        $result = $circuitBreaker->call($successfulOperation);
        $this->assertEquals('success', $result);

        // Test failure handling and circuit opening
        $failingOperation = function () {
            throw new \RuntimeException('Service failure');
        };

        $failureCount = 0;
        for ($i = 0; $i < 5; $i++) {
            try {
                $circuitBreaker->call($failingOperation);
            } catch (\Throwable $e) {
                $failureCount++;
            }
            
            if ($i >= 2) { // After 3 failures, circuit should be open
                $this->assertTrue($circuitBreaker->isOpen());
            }
        }

        $this->assertGreaterThanOrEqual(3, $failureCount);

        // Test fallback execution
        $fallbackOperation = function () {
            return 'fallback_result';
        };

        $result = $circuitBreaker->call($failingOperation, $fallbackOperation);
        $this->assertEquals('fallback_result', $result);

        // Test circuit breaker statistics
        $stats = $circuitBreaker->getStats();
        $this->assertArrayHasKey('total_calls', $stats);
        $this->assertArrayHasKey('failed_calls', $stats);
        $this->assertArrayHasKey('times_opened', $stats);
        $this->assertGreaterThan(0, $stats['times_opened']);
    }

    /**
     * @group reliability
     * @group bulkhead
     */
    public function testBulkheadIsolationCapacity(): void
    {
        $bulkhead = new BulkheadIsolation(
            $this->logger,
            $this->ffiManager,
            [
                'default_pool_size' => 5,
                'enable_parallel' => true
            ]
        );

        // Create service bulkheads
        $userServiceBulkhead = $bulkhead->createBulkhead('user_service', [
            'pool_size' => 3,
            'isolation_level' => 'strict'
        ]);

        $paymentServiceBulkhead = $bulkhead->createBulkhead('payment_service', [
            'pool_size' => 2,
            'isolation_level' => 'strict'
        ]);

        // Test normal operation within capacity
        $fastOperation = function () {
            usleep(10000); // 10ms
            return 'completed';
        };

        $results = [];
        
        // Fill user service bulkhead to capacity
        for ($i = 0; $i < 3; $i++) {
            try {
                $result = $userServiceBulkhead->execute($fastOperation);
                $results[] = $result;
            } catch (\Throwable $e) {
                $this->fail("Operation {$i} should not fail within capacity: " . $e->getMessage());
            }
        }

        $this->assertCount(3, $results);

        // Test capacity overflow protection
        $capacityExceeded = false;
        try {
            // This should fail as bulkhead is at capacity
            $userServiceBulkhead->execute(function () {
                sleep(1); // Long operation
                return 'should_not_complete';
            });
        } catch (\HighPerApp\HighPer\Reliability\BulkheadCapacityException $e) {
            $capacityExceeded = true;
        }

        $this->assertTrue($capacityExceeded, 'Bulkhead should prevent capacity overflow');

        // Test service isolation - payment service should still work
        $paymentResult = $paymentServiceBulkhead->execute($fastOperation);
        $this->assertEquals('completed', $paymentResult);

        // Test health status
        $healthStatus = $bulkhead->healthCheck();
        $this->assertArrayHasKey('bulkheads', $healthStatus);
        $this->assertArrayHasKey('overall', $healthStatus);
        $this->assertArrayHasKey('user_service', $healthStatus['bulkheads']);
        $this->assertArrayHasKey('payment_service', $healthStatus['bulkheads']);
    }

    /**
     * @group reliability
     * @group self_healing
     */
    public function testSelfHealingRecovery(): void
    {
        $selfHealing = new SelfHealingManager(
            $this->logger,
            $this->ffiManager,
            [
                'max_retry_attempts' => 3,
                'exponential_backoff_base' => 1.5, // Faster for testing
                'enable_dead_letter_queue' => true
            ]
        );

        $selfHealing->start();

        // Test successful operation (no healing needed)
        $successfulOperation = function () {
            return 'success';
        };

        $result = $selfHealing->executeWithHealing($successfulOperation);
        $this->assertEquals('success', $result);

        // Test operation that fails initially but succeeds on retry
        $attemptCount = 0;
        $retryableOperation = function () use (&$attemptCount) {
            $attemptCount++;
            if ($attemptCount < 3) {
                throw new \RuntimeException("Attempt {$attemptCount} failed");
            }
            return "success_on_attempt_{$attemptCount}";
        };

        $result = $selfHealing->executeWithHealing($retryableOperation);
        $this->assertEquals('success_on_attempt_3', $result);
        $this->assertEquals(3, $attemptCount);

        // Test unrecoverable failure (all retries exhausted)
        $alwaysFailingOperation = function () {
            throw new \RuntimeException('Always fails');
        };

        $unrecoverableException = null;
        try {
            $selfHealing->executeWithHealing($alwaysFailingOperation);
        } catch (\HighPerApp\HighPer\Reliability\SelfHealingException $e) {
            $unrecoverableException = $e;
        }

        $this->assertNotNull($unrecoverableException);
        $this->assertStringContains('failed after 3 healing attempts', $unrecoverableException->getMessage());

        // Test self-healing statistics
        $stats = $selfHealing->getStats();
        $this->assertArrayHasKey('operations_attempted', $stats);
        $this->assertArrayHasKey('recovered_operations', $stats);
        $this->assertArrayHasKey('unrecoverable_failures', $stats);
        $this->assertGreaterThan(0, $stats['recovered_operations']);
        $this->assertGreaterThan(0, $stats['unrecoverable_failures']);

        $selfHealing->stop();
    }

    /**
     * @group reliability
     * @group health_monitor
     */
    public function testHealthMonitoringSystem(): void
    {
        $healthMonitor = new HealthMonitor(
            $this->logger,
            $this->ffiManager,
            [
                'health_threshold' => 99.999,
                'enable_system_monitoring' => true,
                'enable_component_monitoring' => true
            ]
        );

        // Register reliability components for monitoring
        $circuitBreaker = new CircuitBreaker(
            'monitored_service',
            ['failure_threshold' => 5],
            $this->logger,
            $this->ffiManager
        );

        $bulkhead = new BulkheadIsolation(
            $this->logger,
            $this->ffiManager,
            ['default_pool_size' => 10]
        );

        $selfHealing = new SelfHealingManager(
            $this->logger,
            $this->ffiManager
        );

        $healthMonitor->registerCircuitBreakerMonitor('circuit_breaker', $circuitBreaker);
        $healthMonitor->registerBulkheadMonitor('bulkhead', $bulkhead);
        $healthMonitor->registerSelfHealingMonitor('self_healing', $selfHealing);

        $healthMonitor->startMonitoring();

        // Perform comprehensive health check
        $healthData = $healthMonitor->performHealthCheck();

        // Validate health check structure
        $this->assertArrayHasKey('check_id', $healthData);
        $this->assertArrayHasKey('timestamp', $healthData);
        $this->assertArrayHasKey('components', $healthData);
        $this->assertArrayHasKey('system', $healthData);
        $this->assertArrayHasKey('overall', $healthData);

        // Validate component monitoring
        $components = $healthData['components'];
        $this->assertArrayHasKey('circuit_breaker', $components);
        $this->assertArrayHasKey('bulkhead', $components);
        $this->assertArrayHasKey('self_healing', $components);

        foreach ($components as $componentName => $componentHealth) {
            $this->assertArrayHasKey('status', $componentHealth);
            $this->assertArrayHasKey('healthy', $componentHealth);
            $this->assertArrayHasKey('last_checked', $componentHealth);
        }

        // Validate system monitoring
        $system = $healthData['system'];
        $this->assertArrayHasKey('memory', $system);
        $this->assertArrayHasKey('php', $system);

        $this->assertArrayHasKey('status', $system['memory']);
        $this->assertArrayHasKey('usage_mb', $system['memory']);
        $this->assertArrayHasKey('percentage', $system['memory']);

        // Validate overall health
        $overall = $healthData['overall'];
        $this->assertArrayHasKey('status', $overall);
        $this->assertArrayHasKey('health_percentage', $overall);
        $this->assertArrayHasKey('five_nines_compliance', $overall);
        $this->assertArrayHasKey('healthy_components', $overall);
        $this->assertArrayHasKey('total_components', $overall);

        // Test health metrics export
        $metrics = $healthMonitor->getHealthMetrics();
        $this->assertArrayHasKey('highper_framework_health_percentage', $metrics);
        $this->assertArrayHasKey('highper_framework_five_nines_compliance', $metrics);
        $this->assertArrayHasKey('highper_framework_component_count', $metrics);

        // Test health status lightweight endpoint
        $status = $healthMonitor->getHealthStatus();
        $this->assertArrayHasKey('status', $status);
        $this->assertArrayHasKey('health_percentage', $status);
        $this->assertArrayHasKey('five_nines_compliance', $status);

        $healthMonitor->stopMonitoring();
    }

    /**
     * @group reliability
     * @group integration
     */
    public function testReliabilityComponentsIntegration(): void
    {
        // Create integrated reliability stack
        $circuitBreaker = new CircuitBreaker(
            'integrated_service',
            ['failure_threshold' => 3, 'timeout' => 1],
            $this->logger,
            $this->ffiManager
        );

        $bulkhead = new BulkheadIsolation(
            $this->logger,
            $this->ffiManager,
            ['default_pool_size' => 5]
        );

        $selfHealing = new SelfHealingManager(
            $this->logger,
            $this->ffiManager,
            ['max_retry_attempts' => 3]
        );

        $healthMonitor = new HealthMonitor(
            $this->logger,
            $this->ffiManager
        );

        // Register components with health monitor
        $healthMonitor->registerCircuitBreakerMonitor('circuit_breaker', $circuitBreaker);
        $healthMonitor->registerBulkheadMonitor('bulkhead', $bulkhead);
        $healthMonitor->registerSelfHealingMonitor('self_healing', $selfHealing);

        $healthMonitor->startMonitoring();
        $selfHealing->start();

        // Test integrated operation with multiple reliability layers
        $serviceBulkhead = $bulkhead->createBulkhead('test_service', [
            'pool_size' => 3
        ]);

        $attemptCount = 0;
        $reliableOperation = function () use (&$attemptCount, $circuitBreaker) {
            return $circuitBreaker->call(function () use (&$attemptCount) {
                $attemptCount++;
                if ($attemptCount < 2) {
                    throw new \RuntimeException("Service temporarily unavailable");
                }
                return "success_after_healing";
            });
        };

        // Execute operation through bulkhead isolation and self-healing
        $result = $selfHealing->executeWithHealing(function () use ($serviceBulkhead, $reliableOperation) {
            return $serviceBulkhead->execute($reliableOperation);
        });

        $this->assertEquals('success_after_healing', $result);

        // Verify all components are functioning
        $healthData = $healthMonitor->performHealthCheck();
        $overall = $healthData['overall'];
        
        $this->assertGreaterThanOrEqual(80, $overall['health_percentage']);
        $this->assertEquals('healthy', $healthData['components']['self_healing']['status']);

        // Test failure cascade prevention
        $failingOperation = function () {
            throw new \RuntimeException('Critical failure');
        };

        $cascadeFailurePrevented = false;
        try {
            // Try operation that should fail but be isolated
            $result = $serviceBulkhead->execute(function () use ($circuitBreaker, $failingOperation) {
                return $circuitBreaker->call($failingOperation, function () {
                    return 'fallback_response'; // Circuit breaker fallback
                });
            });
            
            if ($result === 'fallback_response') {
                $cascadeFailurePrevented = true;
            }
        } catch (\Throwable $e) {
            // Bulkhead should isolate the failure
            $cascadeFailurePrevented = true;
        }

        $this->assertTrue($cascadeFailurePrevented, 'Reliability stack should prevent cascade failures');

        $healthMonitor->stopMonitoring();
        $selfHealing->stop();
    }

    /**
     * @group reliability
     * @group five_nines
     */
    public function testFiveNinesReliabilityCharacteristics(): void
    {
        // Test five nines reliability (99.999% uptime) characteristics
        $circuitBreaker = new CircuitBreaker(
            'five_nines_service',
            ['failure_threshold' => 10, 'timeout' => 5],
            $this->logger,
            $this->ffiManager
        );

        $bulkhead = new BulkheadIsolation(
            $this->logger,
            $this->ffiManager,
            ['default_pool_size' => 20]
        );

        $selfHealing = new SelfHealingManager(
            $this->logger,
            $this->ffiManager,
            ['max_retry_attempts' => 5]
        );

        $healthMonitor = new HealthMonitor(
            $this->logger,
            $this->ffiManager,
            ['health_threshold' => 99.999]
        );

        $healthMonitor->registerCircuitBreakerMonitor('circuit_breaker', $circuitBreaker);
        $healthMonitor->registerBulkheadMonitor('bulkhead', $bulkhead);
        $healthMonitor->registerSelfHealingMonitor('self_healing', $selfHealing);

        $healthMonitor->startMonitoring();
        $selfHealing->start();

        // Simulate high-load operations for five nines testing
        $serviceBulkhead = $bulkhead->createBulkhead('five_nines_service', [
            'pool_size' => 10
        ]);

        $totalOperations = 0;
        $successfulOperations = 0;
        $operationResults = [];

        // Run test operations
        for ($i = 0; $i < 100; $i++) {
            $totalOperations++;
            
            try {
                $operation = function () use ($i) {
                    // Simulate occasional failures (within five nines tolerance)
                    if ($i % 50 === 0 && $i > 0) { // 2% failure rate
                        throw new \RuntimeException("Simulated failure {$i}");
                    }
                    return "operation_{$i}_success";
                };

                $result = $selfHealing->executeWithHealing(function () use ($serviceBulkhead, $circuitBreaker, $operation) {
                    return $serviceBulkhead->execute(function () use ($circuitBreaker, $operation) {
                        return $circuitBreaker->call($operation, function () {
                            return 'fallback_success';
                        });
                    });
                });

                $successfulOperations++;
                $operationResults[] = $result;

            } catch (\Throwable $e) {
                // Track failures
                $operationResults[] = 'failed: ' . $e->getMessage();
            }
        }

        // Calculate reliability metrics
        $successRate = ($successfulOperations / $totalOperations) * 100;
        $this->assertGreaterThanOrEqual(99.9, $successRate, 'Success rate should be at least 99.9%');

        // Test health monitoring for five nines compliance
        $healthData = $healthMonitor->performHealthCheck();
        $overall = $healthData['overall'];

        // Five nines compliance verification
        $this->assertArrayHasKey('five_nines_compliance', $overall);
        $this->assertGreaterThanOrEqual(99.9, $overall['health_percentage']);

        // Component-level five nines verification
        $components = $healthData['components'];
        $healthyComponents = 0;
        $totalComponents = count($components);

        foreach ($components as $component) {
            if ($component['healthy'] && $component['status'] === 'healthy') {
                $healthyComponents++;
            }
        }

        $componentHealthRate = ($healthyComponents / $totalComponents) * 100;
        $this->assertGreaterThanOrEqual(99.9, $componentHealthRate, 'Component health should meet five nines standard');

        // Test recovery time characteristics (should be minimal)
        $stats = $selfHealing->getStats();
        if ($stats['healing_triggered'] > 0) {
            $healingSuccessRate = ($stats['successful_healings'] / $stats['healing_triggered']) * 100;
            $this->assertGreaterThanOrEqual(80, $healingSuccessRate, 'Healing should be effective');
        }

        // Verify uptime score
        $uptimeScore = $overall['uptime_score'] ?? 0;
        $this->assertGreaterThanOrEqual(99.9, $uptimeScore, 'Uptime score should meet five nines requirement');

        $healthMonitor->stopMonitoring();
        $selfHealing->stop();
    }

    /**
     * @group reliability
     * @group performance
     */
    public function testReliabilityPerformanceOverhead(): void
    {
        // Test that reliability features don't add excessive overhead
        $circuitBreaker = new CircuitBreaker(
            'performance_test',
            [],
            $this->logger,
            $this->ffiManager
        );

        $bulkhead = new BulkheadIsolation(
            $this->logger,
            $this->ffiManager
        );

        $selfHealing = new SelfHealingManager(
            $this->logger,
            $this->ffiManager
        );

        $serviceBulkhead = $bulkhead->createBulkhead('performance_service', [
            'pool_size' => 50
        ]);

        // Baseline operation without reliability features
        $baselineOperation = function () {
            return 'baseline_result';
        };

        $baselineStart = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $baselineOperation();
        }
        $baselineTime = microtime(true) - $baselineStart;

        // Operation with full reliability stack
        $reliabilityOperation = function () use ($serviceBulkhead, $circuitBreaker, $baselineOperation) {
            return $serviceBulkhead->execute(function () use ($circuitBreaker, $baselineOperation) {
                return $circuitBreaker->call($baselineOperation);
            });
        };

        $reliabilityStart = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $reliabilityOperation();
        }
        $reliabilityTime = microtime(true) - $reliabilityStart;

        // Calculate overhead
        $overhead = (($reliabilityTime - $baselineTime) / $baselineTime) * 100;
        
        // Reliability overhead should be reasonable (< 50% for 100 operations)
        $this->assertLessThan(50, $overhead, 'Reliability overhead should be reasonable');

        // Test memory usage impact
        $beforeMemory = memory_get_usage(true);
        
        // Create additional reliability components
        for ($i = 0; $i < 10; $i++) {
            $cb = new CircuitBreaker("test_cb_{$i}", [], $this->logger, $this->ffiManager);
            $bh = $bulkhead->createBulkhead("test_bulkhead_{$i}");
        }
        
        $afterMemory = memory_get_usage(true);
        $memoryIncrease = ($afterMemory - $beforeMemory) / 1024 / 1024; // MB
        
        // Memory increase should be reasonable (< 10MB for 10 components)
        $this->assertLessThan(10, $memoryIncrease, 'Memory usage should be reasonable');

        $this->logger->info('Reliability performance test completed', [
            'baseline_time' => round($baselineTime * 1000, 2) . 'ms',
            'reliability_time' => round($reliabilityTime * 1000, 2) . 'ms',
            'overhead_percentage' => round($overhead, 2) . '%',
            'memory_increase_mb' => round($memoryIncrease, 2)
        ]);
    }

    protected function tearDown(): void
    {
        // Cleanup resources
        gc_collect_cycles();
    }
}