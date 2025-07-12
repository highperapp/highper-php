<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use HighPerApp\HighPer\Foundation\ProcessManager;
use HighPerApp\HighPer\Contracts\ApplicationInterface;
use HighPerApp\HighPer\Contracts\LoggerInterface;

/**
 * ProcessManager Unit Tests
 * 
 * Tests multi-process worker spawning, zero-downtime capabilities,
 * and worker lifecycle management.
 */
class ProcessManagerTest extends TestCase
{
    private ApplicationInterface $app;
    private LoggerInterface $logger;
    private ProcessManager $processManager;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->app = $this->createMock(ApplicationInterface::class);
        $this->app->method('getLogger')->willReturn($this->logger);
        
        // Mock environment variables for testing
        $_ENV['WORKER_COUNT'] = '2';
        $_ENV['WORKER_MEMORY_LIMIT'] = '128M';
        $_ENV['DEPLOYMENT_STRATEGY'] = 'blue_green';
    }

    protected function tearDown(): void
    {
        // Clean up environment variables
        unset($_ENV['WORKER_COUNT']);
        unset($_ENV['WORKER_MEMORY_LIMIT']);
        unset($_ENV['DEPLOYMENT_STRATEGY']);
    }

    public function testProcessManagerInitialization(): void
    {
        $this->processManager = new ProcessManager($this->app);
        
        $config = $this->processManager->getConfig();
        
        $this->assertArrayHasKey('workers', $config);
        $this->assertArrayHasKey('memory_limit', $config);
        $this->assertArrayHasKey('deployment_strategy', $config);
        $this->assertArrayHasKey('max_connections_per_worker', $config);
        
        $this->assertEquals(2, $config['workers']);
        $this->assertEquals('128M', $config['memory_limit']);
        $this->assertEquals('blue_green', $config['deployment_strategy']);
    }

    public function testConfigurationDefaults(): void
    {
        // Clear environment variables to test defaults
        unset($_ENV['WORKER_COUNT']);
        unset($_ENV['WORKER_MEMORY_LIMIT']);
        unset($_ENV['DEPLOYMENT_STRATEGY']);
        
        $this->processManager = new ProcessManager($this->app);
        $config = $this->processManager->getConfig();
        
        // Should use CPU core count as default
        $expectedWorkers = (int) shell_exec('nproc') ?: 4;
        $this->assertEquals($expectedWorkers, $config['workers']);
        $this->assertEquals('256M', $config['memory_limit']);
        $this->assertEquals('blue_green', $config['deployment_strategy']);
    }

    public function testZeroDowntimeDetection(): void
    {
        $this->processManager = new ProcessManager($this->app);
        
        // Test that zero-downtime capability is detected
        $stats = $this->processManager->getStats();
        $this->assertArrayHasKey('zero_downtime_enabled', $stats);
        $this->assertIsBool($stats['zero_downtime_enabled']);
    }

    public function testConfigurationUpdate(): void
    {
        $this->processManager = new ProcessManager($this->app);
        
        $newConfig = [
            'workers' => 4,
            'memory_limit' => '512M',
            'max_connections_per_worker' => 5000
        ];
        
        $this->processManager->setConfig($newConfig);
        $config = $this->processManager->getConfig();
        
        $this->assertEquals(4, $config['workers']);
        $this->assertEquals('512M', $config['memory_limit']);
        $this->assertEquals(5000, $config['max_connections_per_worker']);
    }

    public function testStatsCollection(): void
    {
        $this->processManager = new ProcessManager($this->app);
        
        $stats = $this->processManager->getStats();
        
        $expectedKeys = [
            'running',
            'worker_count',
            'zero_downtime_enabled',
            'deployment_strategy',
            'memory_usage',
            'workers'
        ];
        
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $stats, "Missing key: {$key}");
        }
        
        $this->assertIsBool($stats['running']);
        $this->assertIsInt($stats['worker_count']);
        $this->assertIsBool($stats['zero_downtime_enabled']);
        $this->assertIsString($stats['deployment_strategy']);
        $this->assertIsInt($stats['memory_usage']);
        $this->assertIsArray($stats['workers']);
    }

    public function testIsRunningInitialState(): void
    {
        $this->processManager = new ProcessManager($this->app);
        
        $this->assertFalse($this->processManager->isRunning());
        $this->assertEquals(0, $this->processManager->getWorkersCount());
    }

    public function testWorkerPidsAccess(): void
    {
        $this->processManager = new ProcessManager($this->app);
        
        $pids = $this->processManager->getWorkerPids();
        $this->assertIsArray($pids);
        $this->assertEmpty($pids); // No workers started yet
    }

    public function testSignalHandlerRegistration(): void
    {
        $this->processManager = new ProcessManager($this->app);
        
        // Test that signal handlers can be called without errors
        // Note: We can't easily test actual signal handling in unit tests
        $this->processManager->handleShutdown();
        $this->processManager->handleRestart();
        
        $this->addToAssertionCount(2); // No exceptions thrown
    }

    public function testWorkerConfigGeneration(): void
    {
        $this->processManager = new ProcessManager($this->app);
        
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->processManager);
        $method = $reflection->getMethod('getWorkerConfig');
        $method->setAccessible(true);
        
        $workerConfig = $method->invoke($this->processManager, 1);
        
        $this->assertArrayHasKey('worker_id', $workerConfig);
        $this->assertArrayHasKey('memory_limit', $workerConfig);
        $this->assertArrayHasKey('max_connections', $workerConfig);
        $this->assertArrayHasKey('deployment_strategy', $workerConfig);
        
        $this->assertEquals(1, $workerConfig['worker_id']);
        $this->assertEquals('128M', $workerConfig['memory_limit']);
        $this->assertEquals('blue_green', $workerConfig['deployment_strategy']);
    }

    public function testEnvironmentVariableProcessing(): void
    {
        // Test different environment variable values
        $_ENV['WORKER_COUNT'] = '8';
        $_ENV['WORKER_MEMORY_LIMIT'] = '1G';
        $_ENV['DEPLOYMENT_STRATEGY'] = 'rolling';
        $_ENV['MAX_CONNECTIONS_PER_WORKER'] = '10000';
        $_ENV['GRACEFUL_SHUTDOWN_TIMEOUT'] = '60';
        
        $this->processManager = new ProcessManager($this->app);
        $config = $this->processManager->getConfig();
        
        $this->assertEquals(8, $config['workers']);
        $this->assertEquals('1G', $config['memory_limit']);
        $this->assertEquals('rolling', $config['deployment_strategy']);
        $this->assertEquals(10000, $config['max_connections_per_worker']);
        $this->assertEquals(60, $config['graceful_shutdown_timeout']);
        
        // Clean up
        unset($_ENV['WORKER_COUNT']);
        unset($_ENV['WORKER_MEMORY_LIMIT']);
        unset($_ENV['DEPLOYMENT_STRATEGY']);
        unset($_ENV['MAX_CONNECTIONS_PER_WORKER']);
        unset($_ENV['GRACEFUL_SHUTDOWN_TIMEOUT']);
    }

    public function testLoggerIntegration(): void
    {
        $this->logger->expects($this->atLeastOnce())
                     ->method('info')
                     ->with(
                         $this->logicalOr(
                             $this->stringContains('Starting ProcessManager'),
                             $this->stringContains('Zero-downtime deployment enabled')
                         ),
                         $this->isType('array')
                     );
        
        $this->processManager = new ProcessManager($this->app);
    }

    public function testScaleWorkersValidation(): void
    {
        $this->processManager = new ProcessManager($this->app);
        
        // Test scaling to same count
        $initialCount = $this->processManager->getWorkersCount();
        $this->processManager->scaleWorkers($initialCount);
        $this->assertEquals($initialCount, $this->processManager->getWorkersCount());
        
        // Logger should log scaling operations
        $this->logger->expects($this->atLeastOnce())
                     ->method('info')
                     ->with(
                         $this->stringContains('Workers scaled'),
                         $this->isType('array')
                     );
        
        $this->processManager->scaleWorkers(1);
    }

    public function testMemoryLimitValidation(): void
    {
        // Test various memory limit formats
        $testCases = [
            '256M' => '256M',
            '1G' => '1G',
            '512' => '512',
            '2048M' => '2048M'
        ];
        
        foreach ($testCases as $input => $expected) {
            $_ENV['WORKER_MEMORY_LIMIT'] = $input;
            $this->processManager = new ProcessManager($this->app);
            $config = $this->processManager->getConfig();
            $this->assertEquals($expected, $config['memory_limit']);
        }
        
        unset($_ENV['WORKER_MEMORY_LIMIT']);
    }
}