<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Tests\Unit\Foundation;

use HighPerApp\HighPer\Foundation\AsyncManager;
use HighPerApp\HighPer\Tests\TestCase;
use Amp\Future;
use Amp\DeferredFuture;

class AsyncManagerTest extends TestCase
{
    protected AsyncManager $asyncManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asyncManager = new AsyncManager();
    }

    public function testBasicAsyncExecution(): void
    {
        $result = $this->asyncManager->run(function() {
            return 'async_result';
        });
        
        $this->assertEquals('async_result', $result);
    }

    public function testPromiseExecution(): void
    {
        $deferred = new DeferredFuture();
        $future = $deferred->getFuture();
        
        $this->asyncManager->defer(function() use ($deferred) {
            $deferred->complete('deferred_result');
        });
        
        $result = $this->asyncManager->await($future);
        $this->assertEquals('deferred_result', $result);
    }

    public function testParallelExecution(): void
    {
        $tasks = [
            function() { return 'task1'; },
            function() { return 'task2'; },
            function() { return 'task3'; }
        ];
        
        $results = $this->asyncManager->parallel($tasks);
        
        $this->assertCount(3, $results);
        $this->assertEquals('task1', $results[0]);
        $this->assertEquals('task2', $results[1]);
        $this->assertEquals('task3', $results[2]);
    }

    public function testConcurrentExecution(): void
    {
        $startTime = microtime(true);
        
        $tasks = [
            function() { 
                usleep(50000); // 50ms
                return 'concurrent1'; 
            },
            function() { 
                usleep(50000); // 50ms
                return 'concurrent2'; 
            }
        ];
        
        $results = $this->asyncManager->concurrent($tasks);
        $endTime = microtime(true);
        
        // Should take less than 100ms total (parallel execution)
        $this->assertLessThan(0.1, $endTime - $startTime);
        $this->assertCount(2, $results);
        $this->assertEquals('concurrent1', $results[0]);
        $this->assertEquals('concurrent2', $results[1]);
    }

    public function testTimeoutHandling(): void
    {
        $this->expectException(\Amp\TimeoutException::class);
        
        $this->asyncManager->timeout(function() {
            usleep(200000); // 200ms
            return 'timeout_result';
        }, 0.1); // 100ms timeout
    }

    public function testErrorHandling(): void
    {
        try {
            $this->asyncManager->run(function() {
                throw new \RuntimeException('Async error');
            });
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertEquals('Async error', $e->getMessage());
        }
    }

    public function testTaskQueuing(): void
    {
        $results = [];
        
        $this->asyncManager->queue(function() use (&$results) {
            $results[] = 'queued1';
        });
        
        $this->asyncManager->queue(function() use (&$results) {
            $results[] = 'queued2';
        });
        
        $this->asyncManager->processQueue();
        
        $this->assertCount(2, $results);
        $this->assertEquals('queued1', $results[0]);
        $this->assertEquals('queued2', $results[1]);
    }

    public function testAsyncIterator(): void
    {
        $data = [1, 2, 3, 4, 5];
        
        $results = $this->asyncManager->map($data, function($item) {
            return $item * 2;
        });
        
        $this->assertEquals([2, 4, 6, 8, 10], $results);
    }

    public function testAsyncFilter(): void
    {
        $data = [1, 2, 3, 4, 5, 6];
        
        $results = $this->asyncManager->filter($data, function($item) {
            return $item % 2 === 0; // Even numbers only
        });
        
        $this->assertEquals([2, 4, 6], array_values($results));
    }

    public function testAsyncReduce(): void
    {
        $data = [1, 2, 3, 4, 5];
        
        $result = $this->asyncManager->reduce($data, function($carry, $item) {
            return $carry + $item;
        }, 0);
        
        $this->assertEquals(15, $result);
    }

    public function testResourceManagement(): void
    {
        $resource = tmpfile();
        
        $result = $this->asyncManager->withResource($resource, function($r) {
            fwrite($r, 'test data');
            rewind($r);
            return fread($r, 1024);
        });
        
        $this->assertEquals('test data', $result);
        
        // Resource should be automatically closed
        $this->assertFalse(is_resource($resource));
    }

    public function testAsyncStatistics(): void
    {
        $this->asyncManager->run(function() { return 'test'; });
        $this->asyncManager->run(function() { return 'test2'; });
        
        $stats = $this->asyncManager->getStats();
        
        $this->assertArrayHasKey('tasks_executed', $stats);
        $this->assertArrayHasKey('total_execution_time', $stats);
        $this->assertArrayHasKey('average_execution_time', $stats);
        $this->assertGreaterThanOrEqual(2, $stats['tasks_executed']);
    }

    public function testMemoryManagement(): void
    {
        $initialMemory = memory_get_usage(true);
        
        // Execute many async tasks
        for ($i = 0; $i < 100; $i++) {
            $this->asyncManager->run(function() use ($i) {
                return str_repeat('x', 1000); // 1KB string
            });
        }
        
        $this->asyncManager->cleanup();
        
        $finalMemory = memory_get_usage(true);
        
        // Memory usage should not increase significantly
        $memoryIncrease = $finalMemory - $initialMemory;
        $this->assertLessThan(1024 * 1024, $memoryIncrease); // Less than 1MB increase
    }
}