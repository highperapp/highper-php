<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Tests\Reliability;

use HighPerApp\HighPer\Resilience\SelfHealingManager;
use HighPerApp\HighPer\Tests\TestCase;

class SelfHealingTest extends TestCase
{
    protected SelfHealingManager $healingManager;
    protected array $serviceStates;

    protected function setUp(): void
    {
        parent::setUp();
        $this->healingManager = new SelfHealingManager();
        $this->serviceStates = [
            'database' => 'healthy',
            'cache' => 'healthy',
            'queue' => 'healthy',
            'search' => 'healthy'
        ];
    }

    protected function tearDown(): void
    {
        $this->healingManager->stop();
        parent::tearDown();
    }

    public function testDatabaseConnectionHealing(): void
    {
        $this->serviceStates['database'] = 'disconnected';
        
        $this->healingManager->registerStrategy('database', function() {
            if ($this->serviceStates['database'] === 'disconnected') {
                // Simulate reconnection attempt
                $this->serviceStates['database'] = 'healthy';
                return true;
            }
            return false;
        });
        
        $result = $this->healingManager->heal('database');
        
        $this->assertTrue($result);
        $this->assertEquals('healthy', $this->serviceStates['database']);
    }

    public function testCacheRecoveryStrategy(): void
    {
        $this->serviceStates['cache'] = 'memory_full';
        
        $this->healingManager->registerStrategy('cache', function() {
            if ($this->serviceStates['cache'] === 'memory_full') {
                // Simulate cache cleanup
                $this->serviceStates['cache'] = 'healthy';
                return true;
            }
            return false;
        });
        
        $result = $this->healingManager->heal('cache');
        
        $this->assertTrue($result);
        $this->assertEquals('healthy', $this->serviceStates['cache']);
    }

    public function testQueueBacklogRecovery(): void
    {
        $this->serviceStates['queue'] = 'backlogged';
        
        $this->healingManager->registerStrategy('queue', function() {
            if ($this->serviceStates['queue'] === 'backlogged') {
                // Simulate queue processing acceleration
                $this->serviceStates['queue'] = 'healthy';
                return true;
            }
            return false;
        });
        
        $result = $this->healingManager->heal('queue');
        
        $this->assertTrue($result);
        $this->assertEquals('healthy', $this->serviceStates['queue']);
    }

    public function testSearchIndexRebuild(): void
    {
        $this->serviceStates['search'] = 'corrupted';
        
        $this->healingManager->registerStrategy('search', function() {
            if ($this->serviceStates['search'] === 'corrupted') {
                // Simulate index rebuild
                $this->serviceStates['search'] = 'healthy';
                return true;
            }
            return false;
        });
        
        $result = $this->healingManager->heal('search');
        
        $this->assertTrue($result);
        $this->assertEquals('healthy', $this->serviceStates['search']);
    }

    public function testCascadingFailureRecovery(): void
    {
        // Simulate cascading failure
        $this->serviceStates['database'] = 'disconnected';
        $this->serviceStates['cache'] = 'stale';
        $this->serviceStates['queue'] = 'stuck';
        
        // Register recovery strategies in dependency order
        $this->healingManager->registerStrategy('database', function() {
            if ($this->serviceStates['database'] === 'disconnected') {
                $this->serviceStates['database'] = 'healthy';
                return true;
            }
            return false;
        });
        
        $this->healingManager->registerStrategy('cache', function() {
            if ($this->serviceStates['database'] === 'healthy' && 
                $this->serviceStates['cache'] === 'stale') {
                $this->serviceStates['cache'] = 'healthy';
                return true;
            }
            return false;
        });
        
        $this->healingManager->registerStrategy('queue', function() {
            if ($this->serviceStates['database'] === 'healthy' && 
                $this->serviceStates['queue'] === 'stuck') {
                $this->serviceStates['queue'] = 'healthy';
                return true;
            }
            return false;
        });
        
        // Heal in correct order
        $this->assertTrue($this->healingManager->heal('database'));
        $this->assertTrue($this->healingManager->heal('cache'));
        $this->assertTrue($this->healingManager->heal('queue'));
        
        // All services should be healthy
        foreach ($this->serviceStates as $service => $state) {
            $this->assertEquals('healthy', $state, "Service {$service} should be healthy");
        }
    }

    public function testAutomaticHealingCycle(): void
    {
        $this->healingManager->setInterval(0.1); // 100ms intervals
        
        $healingAttempts = 0;
        $this->serviceStates['auto_service'] = 'failing';
        
        $this->healingManager->registerStrategy('auto_service', function() use (&$healingAttempts) {
            $healingAttempts++;
            if ($healingAttempts >= 3) {
                $this->serviceStates['auto_service'] = 'healthy';
                return true;
            }
            return false;
        });
        
        $this->healingManager->start();
        
        // Wait for multiple healing cycles
        usleep(350000); // 350ms - should trigger 3+ cycles
        
        $this->healingManager->stop();
        
        $this->assertGreaterThanOrEqual(3, $healingAttempts);
        $this->assertEquals('healthy', $this->serviceStates['auto_service']);
    }

    public function testHealingStrategyPriority(): void
    {
        $executionOrder = [];
        
        // Register multiple strategies for same service
        $this->healingManager->registerStrategy('priority_service', function() use (&$executionOrder) {
            $executionOrder[] = 'strategy_1';
            return false; // Fail to test next strategy
        });
        
        $this->healingManager->registerStrategy('priority_service', function() use (&$executionOrder) {
            $executionOrder[] = 'strategy_2';
            return true; // Success
        });
        
        $this->healingManager->registerStrategy('priority_service', function() use (&$executionOrder) {
            $executionOrder[] = 'strategy_3';
            return true; // Should not execute
        });
        
        $result = $this->healingManager->heal('priority_service');
        
        $this->assertTrue($result);
        $this->assertEquals(['strategy_1', 'strategy_2'], $executionOrder);
    }

    public function testMemoryLeakPrevention(): void
    {
        $initialMemory = memory_get_usage(true);
        
        // Register and execute many healing strategies
        for ($i = 0; $i < 100; $i++) {
            $this->healingManager->registerStrategy("service_{$i}", function() {
                return true;
            });
            
            $this->healingManager->heal("service_{$i}");
        }
        
        $finalMemory = memory_get_usage(true);
        $memoryIncrease = $finalMemory - $initialMemory;
        
        // Memory increase should be reasonable (less than 1MB for 100 services)
        $this->assertLessThan(1024 * 1024, $memoryIncrease);
    }

    public function testHealingTimeout(): void
    {
        $this->healingManager->registerStrategy('slow_service', function() {
            usleep(200000); // 200ms - simulate slow healing
            return true;
        });
        
        $startTime = microtime(true);
        $result = $this->healingManager->heal('slow_service');
        $endTime = microtime(true);
        
        $this->assertTrue($result);
        $this->assertGreaterThan(0.19, $endTime - $startTime); // Should take at least 190ms
    }

    public function testFailedHealingRetry(): void
    {
        $attempts = 0;
        
        $this->healingManager->registerStrategy('retry_service', function() use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new \RuntimeException('Healing failed');
            }
            return true;
        });
        
        // Multiple heal attempts should eventually succeed
        for ($i = 0; $i < 5; $i++) {
            try {
                $result = $this->healingManager->heal('retry_service');
                if ($result) {
                    break;
                }
            } catch (\Exception $e) {
                // Continue trying
            }
            usleep(10000); // Small delay between retries
        }
        
        $this->assertEquals(3, $attempts);
    }

    public function testHealthCheckIntegration(): void
    {
        $healthChecks = [
            'database' => false,
            'cache' => false,
            'queue' => true  // Only queue is healthy
        ];
        
        foreach ($healthChecks as $service => $isHealthy) {
            $this->healingManager->registerStrategy($service, function() use ($service, &$healthChecks) {
                if (!$healthChecks[$service]) {
                    $healthChecks[$service] = true;
                    return true;
                }
                return false;
            });
        }
        
        // Heal unhealthy services
        foreach ($healthChecks as $service => $isHealthy) {
            if (!$isHealthy) {
                $this->healingManager->heal($service);
            }
        }
        
        // All services should now be healthy
        foreach ($healthChecks as $service => $isHealthy) {
            $this->assertTrue($isHealthy, "Service {$service} should be healthy after healing");
        }
    }

    public function testRealTimeHealingMetrics(): void
    {
        $this->healingManager->registerStrategy('metrics_service', function() {
            return true;
        });
        
        $this->healingManager->heal('metrics_service');
        
        $stats = $this->healingManager->getStats();
        
        $this->assertArrayHasKey('healing_attempts', $stats);
        $this->assertArrayHasKey('successful_healings', $stats);
        $this->assertArrayHasKey('failed_healings', $stats);
        $this->assertArrayHasKey('success_rate', $stats);
        $this->assertArrayHasKey('registered_contexts', $stats);
        
        $this->assertEquals(1, $stats['healing_attempts']);
        $this->assertEquals(1, $stats['successful_healings']);
        $this->assertEquals(0, $stats['failed_healings']);
        $this->assertEquals(100.0, $stats['success_rate']);
        $this->assertEquals(1, $stats['registered_contexts']);
    }
}