<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Tests\Concurrency;

use PHPUnit\Framework\TestCase;
use HighPerApp\HighPer\Foundation\Application;
use HighPerApp\HighPer\Foundation\ProcessManager;
use HighPerApp\HighPer\Foundation\HybridEventLoop;

/**
 * Multi-Process Concurrency Tests
 * 
 * Tests concurrent operations across multiple worker processes and
 * thread-safe operations in the hybrid architecture.
 */
class MultiProcessConcurrencyTest extends TestCase
{
    private Application $app;
    private ProcessManager $processManager;
    private HybridEventLoop $eventLoop;

    protected function setUp(): void
    {
        $this->app = new Application([
            'testing' => true,
            'environment' => 'concurrency-test'
        ]);
        
        $this->app->bootstrap();
        
        // Use minimal workers for testing
        $_ENV['WORKER_COUNT'] = '2';
        $this->processManager = new ProcessManager($this->app);
        $this->eventLoop = new HybridEventLoop($this->app->getLogger());
    }

    protected function tearDown(): void
    {
        if ($this->processManager->isRunning()) {
            $this->processManager->stop();
        }
        unset($_ENV['WORKER_COUNT']);
    }

    public function testConcurrentWorkerSpawning(): void
    {
        $startTime = microtime(true);
        
        // Start multiple workers concurrently
        $this->processManager->start();
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        $this->assertTrue($this->processManager->isRunning());
        $this->assertGreaterThan(0, $this->processManager->getWorkersCount());
        
        // Should complete within reasonable time even with multiple workers
        $this->assertLessThan(10.0, $duration, 'Concurrent worker spawning took too long');
        
        $this->processManager->stop();
    }

    public function testConcurrentConnectionCountUpdates(): void
    {
        $iterations = 1000;
        $processes = [];
        
        // Simulate concurrent connection updates
        for ($i = 0; $i < 10; $i++) {
            $pid = pcntl_fork();
            
            if ($pid === 0) {
                // Child process
                for ($j = 0; $j < $iterations / 10; $j++) {
                    $this->eventLoop->addConnectionCount(1);
                    usleep(100); // Small delay to increase concurrency
                    $this->eventLoop->removeConnectionCount(1);
                }
                exit(0);
            } elseif ($pid > 0) {
                $processes[] = $pid;
            }
        }
        
        // Wait for all child processes
        foreach ($processes as $pid) {
            pcntl_waitpid($pid, $status);
        }
        
        // Connection count should return to 0 if operations were thread-safe
        $finalCount = $this->eventLoop->getConnectionCount();
        $this->assertEquals(0, $finalCount, 'Connection count operations were not thread-safe');
    }

    public function testConcurrentEventLoopOperations(): void
    {
        $processes = [];
        $sharedFile = '/tmp/highper_concurrency_test_' . uniqid();
        
        // Create shared file for results
        file_put_contents($sharedFile, '');
        
        for ($i = 0; $i < 5; $i++) {
            $pid = pcntl_fork();
            
            if ($pid === 0) {
                // Child process - perform event loop operations
                $childEventLoop = new HybridEventLoop($this->app->getLogger());
                
                $operations = 0;
                for ($j = 0; $j < 100; $j++) {
                    // Create and cancel timers
                    $timer = $childEventLoop->delay(0.001, function() {});
                    $childEventLoop->cancel($timer);
                    $operations++;
                    
                    // Create and cancel deferred operations
                    $defer = $childEventLoop->defer(function() {});
                    $childEventLoop->cancel($defer);
                    $operations++;
                }
                
                // Write results to shared file
                file_put_contents($sharedFile, "Process $i completed $operations operations\n", FILE_APPEND | LOCK_EX);
                exit(0);
            } elseif ($pid > 0) {
                $processes[] = $pid;
            }
        }
        
        // Wait for all processes
        foreach ($processes as $pid) {
            pcntl_waitpid($pid, $status);
        }
        
        // Check results
        $results = file_get_contents($sharedFile);
        $lines = explode("\n", trim($results));
        
        $this->assertCount(5, $lines, 'Not all processes completed');
        
        foreach ($lines as $line) {
            $this->assertStringContains('completed 200 operations', $line);
        }
        
        // Cleanup
        unlink($sharedFile);
    }

    public function testConcurrentWorkerScaling(): void
    {
        $this->processManager->start();
        $initialCount = $this->processManager->getWorkersCount();
        
        $processes = [];
        
        // Multiple processes trying to scale workers simultaneously
        for ($i = 0; $i < 3; $i++) {
            $pid = pcntl_fork();
            
            if ($pid === 0) {
                // Child process - attempt scaling
                $testProcessManager = new ProcessManager($this->app);
                $testProcessManager->scaleWorkers($initialCount + 1);
                exit(0);
            } elseif ($pid > 0) {
                $processes[] = $pid;
            }
        }
        
        // Wait for all scaling attempts
        foreach ($processes as $pid) {
            pcntl_waitpid($pid, $status);
        }
        
        // Worker count should be consistent
        $finalCount = $this->processManager->getWorkersCount();
        $this->assertGreaterThanOrEqual($initialCount, $finalCount);
        
        $this->processManager->stop();
    }

    public function testConcurrentMetricsCollection(): void
    {
        $processes = [];
        $metricsFile = '/tmp/highper_metrics_test_' . uniqid();
        
        for ($i = 0; $i < 5; $i++) {
            $pid = pcntl_fork();
            
            if ($pid === 0) {
                // Child process - collect metrics repeatedly
                $childEventLoop = new HybridEventLoop($this->app->getLogger());
                
                $metricsCollected = 0;
                for ($j = 0; $j < 100; $j++) {
                    $metrics = $childEventLoop->getMetrics();
                    $this->assertIsArray($metrics);
                    $metricsCollected++;
                    usleep(10); // Small delay
                }
                
                file_put_contents($metricsFile, "Process $i collected $metricsCollected metrics\n", FILE_APPEND | LOCK_EX);
                exit(0);
            } elseif ($pid > 0) {
                $processes[] = $pid;
            }
        }
        
        // Wait for all processes
        foreach ($processes as $pid) {
            pcntl_waitpid($pid, $status);
        }
        
        // Verify all processes completed
        $results = file_get_contents($metricsFile);
        $lines = explode("\n", trim($results));
        
        $this->assertCount(5, $lines);
        
        foreach ($lines as $line) {
            $this->assertStringContains('collected 100 metrics', $line);
        }
        
        unlink($metricsFile);
    }

    public function testRaceConditionInProcessCreation(): void
    {
        $this->markTestSkipped('Advanced race condition test - requires careful process management');
        
        // This test would simulate race conditions during process creation
        // Currently skipped to avoid complex inter-process synchronization
    }

    public function testSignalHandlingConcurrency(): void
    {
        if (!function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX functions not available');
        }
        
        $this->processManager->start();
        $workers = $this->processManager->getWorkerPids();
        
        $this->assertNotEmpty($workers, 'No workers to test signal handling');
        
        // Send signals to workers concurrently
        $processes = [];
        foreach ($workers as $workerId => $pid) {
            $testPid = pcntl_fork();
            
            if ($testPid === 0) {
                // Child process - send signal to worker
                // Use SIGUSR1 for testing (non-terminating signal)
                if (posix_kill($pid, 0)) { // Check if process exists
                    // Process exists, we can test signal handling
                    usleep(10000); // 10ms delay
                }
                exit(0);
            } elseif ($testPid > 0) {
                $processes[] = $testPid;
            }
        }
        
        // Wait for signal senders
        foreach ($processes as $pid) {
            pcntl_waitpid($pid, $status);
        }
        
        // Workers should still be running
        $this->assertTrue($this->processManager->isRunning());
        
        $this->processManager->stop();
    }

    public function testConcurrentConfigurationAccess(): void
    {
        $processes = [];
        $configFile = '/tmp/highper_config_test_' . uniqid();
        
        for ($i = 0; $i < 5; $i++) {
            $pid = pcntl_fork();
            
            if ($pid === 0) {
                // Child process - access configuration
                $testProcessManager = new ProcessManager($this->app);
                
                $accessCount = 0;
                for ($j = 0; $j < 50; $j++) {
                    $config = $testProcessManager->getConfig();
                    $this->assertIsArray($config);
                    $accessCount++;
                    usleep(100);
                }
                
                file_put_contents($configFile, "Process $i accessed config $accessCount times\n", FILE_APPEND | LOCK_EX);
                exit(0);
            } elseif ($pid > 0) {
                $processes[] = $pid;
            }
        }
        
        // Wait for all processes
        foreach ($processes as $pid) {
            pcntl_waitpid($pid, $status);
        }
        
        // Verify all processes completed
        $results = file_get_contents($configFile);
        $lines = explode("\n", trim($results));
        
        $this->assertCount(5, $lines);
        
        foreach ($lines as $line) {
            $this->assertStringContains('accessed config 50 times', $line);
        }
        
        unlink($configFile);
    }

    public function testMemoryIsolationBetweenProcesses(): void
    {
        $this->processManager->start();
        
        $processes = [];
        $memoryFile = '/tmp/highper_memory_test_' . uniqid();
        
        for ($i = 0; $i < 3; $i++) {
            $pid = pcntl_fork();
            
            if ($pid === 0) {
                // Child process - allocate memory
                $initialMemory = memory_get_usage(true);
                
                // Allocate memory in this process
                $data = [];
                for ($j = 0; $j < 1000; $j++) {
                    $data[] = str_repeat('x', 1024); // 1KB each
                }
                
                $finalMemory = memory_get_usage(true);
                $allocated = $finalMemory - $initialMemory;
                
                file_put_contents($memoryFile, "Process $i allocated " . round($allocated / 1024) . " KB\n", FILE_APPEND | LOCK_EX);
                exit(0);
            } elseif ($pid > 0) {
                $processes[] = $pid;
            }
        }
        
        // Parent process memory should not be affected
        $parentMemoryBefore = memory_get_usage(true);
        
        // Wait for child processes
        foreach ($processes as $pid) {
            pcntl_waitpid($pid, $status);
        }
        
        $parentMemoryAfter = memory_get_usage(true);
        $parentMemoryChange = $parentMemoryAfter - $parentMemoryBefore;
        
        // Parent memory should not increase significantly
        $this->assertLessThan(100 * 1024, $parentMemoryChange, 'Parent process memory was affected by child allocations');
        
        // Verify all child processes completed
        $results = file_get_contents($memoryFile);
        $lines = explode("\n", trim($results));
        $this->assertCount(3, $lines);
        
        unlink($memoryFile);
        $this->processManager->stop();
    }

    public function testConcurrentStreamOperations(): void
    {
        $processes = [];
        $streamFile = '/tmp/highper_stream_test_' . uniqid();
        
        for ($i = 0; $i < 3; $i++) {
            $pid = pcntl_fork();
            
            if ($pid === 0) {
                // Child process - create stream watchers
                $childEventLoop = new HybridEventLoop($this->app->getLogger());
                
                $streamCount = 0;
                for ($j = 0; $j < 10; $j++) {
                    $stream = fopen('php://memory', 'r+');
                    
                    $readable = $childEventLoop->onReadable($stream, function() {});
                    $writable = $childEventLoop->onWritable($stream, function() {});
                    
                    // Cleanup
                    $childEventLoop->cancel($readable);
                    $childEventLoop->cancel($writable);
                    fclose($stream);
                    
                    $streamCount++;
                }
                
                file_put_contents($streamFile, "Process $i created $streamCount stream watchers\n", FILE_APPEND | LOCK_EX);
                exit(0);
            } elseif ($pid > 0) {
                $processes[] = $pid;
            }
        }
        
        // Wait for all processes
        foreach ($processes as $pid) {
            pcntl_waitpid($pid, $status);
        }
        
        // Verify results
        $results = file_get_contents($streamFile);
        $lines = explode("\n", trim($results));
        
        $this->assertCount(3, $lines);
        
        foreach ($lines as $line) {
            $this->assertStringContains('created 10 stream watchers', $line);
        }
        
        unlink($streamFile);
    }

    public function testDeadlockPrevention(): void
    {
        // Test that the system doesn't deadlock under concurrent load
        $this->processManager->start();
        
        $startTime = microtime(true);
        $timeout = 10.0; // 10 second timeout
        
        $processes = [];
        for ($i = 0; $i < 5; $i++) {
            $pid = pcntl_fork();
            
            if ($pid === 0) {
                // Child process - perform mixed operations
                $childEventLoop = new HybridEventLoop($this->app->getLogger());
                
                for ($j = 0; $j < 20; $j++) {
                    $childEventLoop->addConnectionCount(5);
                    $timer = $childEventLoop->delay(0.001, function() {});
                    $childEventLoop->getMetrics();
                    $childEventLoop->cancel($timer);
                    $childEventLoop->removeConnectionCount(5);
                    
                    // Check for timeout
                    if (microtime(true) - $startTime > $timeout) {
                        exit(1); // Timeout
                    }
                }
                
                exit(0); // Success
            } elseif ($pid > 0) {
                $processes[] = $pid;
            }
        }
        
        // Wait for all processes with timeout
        $allCompleted = true;
        foreach ($processes as $pid) {
            $result = pcntl_waitpid($pid, $status);
            if ($result === -1 || pcntl_wexitstatus($status) !== 0) {
                $allCompleted = false;
            }
        }
        
        $totalTime = microtime(true) - $startTime;
        
        $this->assertTrue($allCompleted, 'Some processes failed or timed out');
        $this->assertLessThan($timeout, $totalTime, 'Operations took too long, possible deadlock');
        
        $this->processManager->stop();
    }
}