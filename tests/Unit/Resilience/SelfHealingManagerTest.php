<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Tests\Unit\Resilience;

use HighPerApp\HighPer\Resilience\SelfHealingManager;
use HighPerApp\HighPer\Tests\TestCase;

class SelfHealingManagerTest extends TestCase
{
    protected SelfHealingManager $healingManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->healingManager = new SelfHealingManager();
    }

    protected function tearDown(): void
    {
        $this->healingManager->stop();
        parent::tearDown();
    }

    public function testInitialState(): void
    {
        $this->assertFalse($this->healingManager->isActive());
        $this->assertEmpty($this->healingManager->getStrategies());
    }

    public function testStartStop(): void
    {
        $this->healingManager->start();
        $this->assertTrue($this->healingManager->isActive());
        
        $this->healingManager->stop();
        $this->assertFalse($this->healingManager->isActive());
    }

    public function testStrategyRegistration(): void
    {
        $strategy = function() {
            return true;
        };
        
        $this->healingManager->registerStrategy('database', $strategy);
        
        $strategies = $this->healingManager->getStrategies();
        $this->assertContains('database', $strategies);
    }

    public function testSuccessfulHealing(): void
    {
        $healed = false;
        $strategy = function() use (&$healed) {
            $healed = true;
            return true;
        };
        
        $this->healingManager->registerStrategy('test-service', $strategy);
        
        $result = $this->healingManager->heal('test-service');
        
        $this->assertTrue($result);
        $this->assertTrue($healed);
    }

    public function testFailedHealing(): void
    {
        $strategy = function() {
            return false;
        };
        
        $this->healingManager->registerStrategy('failing-service', $strategy);
        
        $result = $this->healingManager->heal('failing-service');
        
        $this->assertFalse($result);
    }

    public function testExceptionHandling(): void
    {
        $strategy = function() {
            throw new \RuntimeException('Healing strategy failed');
        };
        
        $this->healingManager->registerStrategy('exception-service', $strategy);
        
        $result = $this->healingManager->heal('exception-service');
        
        $this->assertFalse($result);
    }

    public function testMultipleStrategies(): void
    {
        $attempt1 = false;
        $attempt2 = false;
        
        $strategy1 = function() use (&$attempt1) {
            $attempt1 = true;
            return false; // First strategy fails
        };
        
        $strategy2 = function() use (&$attempt2) {
            $attempt2 = true;
            return true; // Second strategy succeeds
        };
        
        $this->healingManager->registerStrategy('multi-service', $strategy1);
        $this->healingManager->registerStrategy('multi-service', $strategy2);
        
        $result = $this->healingManager->heal('multi-service');
        
        $this->assertTrue($result);
        $this->assertTrue($attempt1);
        $this->assertTrue($attempt2);
    }

    public function testHealingStatistics(): void
    {
        $strategy1 = function() { return true; };
        $strategy2 = function() { return false; };
        
        $this->healingManager->registerStrategy('service1', $strategy1);
        $this->healingManager->registerStrategy('service2', $strategy2);
        
        $this->healingManager->heal('service1');
        $this->healingManager->heal('service2');
        $this->healingManager->heal('non-existent');
        
        $stats = $this->healingManager->getStats();
        
        $this->assertArrayHasKey('healing_attempts', $stats);
        $this->assertArrayHasKey('successful_healings', $stats);
        $this->assertArrayHasKey('failed_healings', $stats);
        $this->assertArrayHasKey('success_rate', $stats);
        $this->assertArrayHasKey('context_stats', $stats);
        
        $this->assertEquals(3, $stats['healing_attempts']);
        $this->assertEquals(1, $stats['successful_healings']);
        $this->assertEquals(2, $stats['failed_healings']);
        $this->assertEquals(33.33, round($stats['success_rate'], 2));
    }

    public function testContextStatistics(): void
    {
        $strategy = function() { return true; };
        
        $this->healingManager->registerStrategy('tracked-service', $strategy);
        $this->healingManager->heal('tracked-service');
        
        $stats = $this->healingManager->getStats();
        $contextStats = $stats['context_stats'];
        
        $this->assertArrayHasKey('tracked-service', $contextStats);
        $this->assertEquals(1, $contextStats['tracked-service']['strategy_count']);
        $this->assertEquals(1, $contextStats['tracked-service']['total_successes']);
        $this->assertEquals(0, $contextStats['tracked-service']['total_failures']);
    }

    public function testHealingInterval(): void
    {
        $this->healingManager->setInterval(0.1); // 100ms
        
        $healingCount = 0;
        $strategy = function() use (&$healingCount) {
            $healingCount++;
            return true;
        };
        
        $this->healingManager->registerStrategy('periodic-service', $strategy);
        $this->healingManager->start();
        
        // Wait for multiple intervals
        usleep(250000); // 250ms - should trigger 2-3 healing cycles
        
        $this->healingManager->stop();
        
        $this->assertGreaterThan(1, $healingCount);
    }

    public function testStrategyThrottling(): void
    {
        $this->healingManager->setInterval(0.1); // 100ms
        
        $executionTimes = [];
        $strategy = function() use (&$executionTimes) {
            $executionTimes[] = microtime(true);
            return true;
        };
        
        $this->healingManager->registerStrategy('throttled-service', $strategy);
        
        // Execute multiple times rapidly
        $this->healingManager->heal('throttled-service');
        $this->healingManager->heal('throttled-service');
        $this->healingManager->heal('throttled-service');
        
        // Should only execute once due to throttling
        $this->assertCount(1, $executionTimes);
    }

    public function testNonExistentContext(): void
    {
        $result = $this->healingManager->heal('non-existent-service');
        $this->assertFalse($result);
    }

    public function testHealingManagerReset(): void
    {
        $strategy = function() { return true; };
        
        $this->healingManager->registerStrategy('test-service', $strategy);
        $this->healingManager->heal('test-service');
        
        $statsBefore = $this->healingManager->getStats();
        $this->assertEquals(1, $statsBefore['healing_attempts']);
        
        $this->healingManager->reset();
        
        $statsAfter = $this->healingManager->getStats();
        $this->assertEquals(0, $statsAfter['healing_attempts']);
        $this->assertEquals(0, $statsAfter['successful_healings']);
        $this->assertEquals(0, $statsAfter['failed_healings']);
    }

    public function testComplexHealingScenario(): void
    {
        $serviceState = 'broken';
        
        $databaseHealingStrategy = function() use (&$serviceState) {
            if ($serviceState === 'broken') {
                // Simulate database reconnection
                $serviceState = 'healthy';
                return true;
            }
            return false;
        };
        
        $cacheHealingStrategy = function() use (&$serviceState) {
            if ($serviceState === 'healthy') {
                // Cache is already healthy when database is fixed
                return true;
            }
            return false;
        };
        
        $this->healingManager->registerStrategy('database', $databaseHealingStrategy);
        $this->healingManager->registerStrategy('cache', $cacheHealingStrategy);
        
        // Test database healing
        $dbResult = $this->healingManager->heal('database');
        $this->assertTrue($dbResult);
        $this->assertEquals('healthy', $serviceState);
        
        // Test cache healing (should succeed now that database is healthy)
        $cacheResult = $this->healingManager->heal('cache');
        $this->assertTrue($cacheResult);
    }

    public function testConcurrentHealingStrategies(): void
    {
        $executionOrder = [];
        
        $strategy1 = function() use (&$executionOrder) {
            $executionOrder[] = 'strategy1';
            usleep(10000); // 10ms
            return true;
        };
        
        $strategy2 = function() use (&$executionOrder) {
            $executionOrder[] = 'strategy2';
            usleep(5000); // 5ms
            return true;
        };
        
        $this->healingManager->registerStrategy('concurrent-service', $strategy1);
        $this->healingManager->registerStrategy('concurrent-service', $strategy2);
        
        $result = $this->healingManager->heal('concurrent-service');
        
        $this->assertTrue($result);
        $this->assertEquals(['strategy1'], $executionOrder); // Should stop after first success
    }
}