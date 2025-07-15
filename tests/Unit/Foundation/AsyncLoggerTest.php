<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Tests\Unit\Foundation;

use HighPerApp\HighPer\Foundation\AsyncLogger;
use HighPerApp\HighPer\Tests\TestCase;
use HighPerApp\HighPer\Contracts\ConfigManagerInterface;
use HighPerApp\HighPer\Contracts\LogHandlerInterface;
use Psr\Log\LogLevel;

class AsyncLoggerTest extends TestCase
{
    protected AsyncLogger $logger;
    protected ConfigManagerInterface $config;
    protected TestLogHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->config = new class implements ConfigManagerInterface {
            public function get(string $key, mixed $default = null): mixed
            {
                return $default;
            }
            
            public function set(string $key, mixed $value): void {}
            public function has(string $key): bool { return false; }
            public function all(): array { return []; }
            public function getNamespace(string $namespace): array 
            { 
                return [
                    'level' => LogLevel::DEBUG,
                    'async' => true,
                    'batch_size' => 10,
                    'flush_interval' => 0.1
                ];
            }
            public function setNamespace(string $namespace, array $config): void {}
            public function merge(string $key, array $values): void {}
            public function invalidate(?string $key = null): void {}
        };
        
        $this->logger = new AsyncLogger($this->config);
        $this->handler = new TestLogHandler();
        $this->logger->addHandler($this->handler);
    }

    protected function tearDown(): void
    {
        $this->logger->flush();
        parent::tearDown();
    }

    public function testBasicLogging(): void
    {
        $this->logger->info('Test message');
        $this->logger->flush();
        
        $logs = $this->handler->getLogs();
        $this->assertCount(1, $logs);
        $this->assertEquals(LogLevel::INFO, $logs[0]['level']);
        $this->assertEquals('Test message', $logs[0]['message']);
    }

    public function testAllLogLevels(): void
    {
        $this->logger->emergency('Emergency message');
        $this->logger->alert('Alert message');
        $this->logger->critical('Critical message');
        $this->logger->error('Error message');
        $this->logger->warning('Warning message');
        $this->logger->notice('Notice message');
        $this->logger->info('Info message');
        $this->logger->debug('Debug message');
        
        $this->logger->flush();
        
        $logs = $this->handler->getLogs();
        $this->assertCount(8, $logs);
        
        $expectedLevels = [
            LogLevel::EMERGENCY,
            LogLevel::ALERT,
            LogLevel::CRITICAL,
            LogLevel::ERROR,
            LogLevel::WARNING,
            LogLevel::NOTICE,
            LogLevel::INFO,
            LogLevel::DEBUG
        ];
        
        foreach ($logs as $index => $log) {
            $this->assertEquals($expectedLevels[$index], $log['level']);
        }
    }

    public function testLogLevelFiltering(): void
    {
        $this->logger->setLevel(LogLevel::WARNING);
        
        $this->logger->debug('Debug message');
        $this->logger->info('Info message');
        $this->logger->warning('Warning message');
        $this->logger->error('Error message');
        
        $this->logger->flush();
        
        $logs = $this->handler->getLogs();
        $this->assertCount(2, $logs);
        $this->assertEquals(LogLevel::WARNING, $logs[0]['level']);
        $this->assertEquals(LogLevel::ERROR, $logs[1]['level']);
    }

    public function testContextInterpolation(): void
    {
        $this->logger->info('User {username} logged in from {ip}', [
            'username' => 'john_doe',
            'ip' => '192.168.1.100'
        ]);
        
        $this->logger->flush();
        
        $logs = $this->handler->getLogs();
        $this->assertCount(1, $logs);
        $this->assertEquals('User john_doe logged in from 192.168.1.100', $logs[0]['message']);
    }

    public function testAsyncLogging(): void
    {
        $this->logger->setAsync(true);
        $this->logger->setBatchSize(3);
        
        $this->logger->info('Message 1');
        $this->logger->info('Message 2');
        
        // Should not be logged yet (batch size not reached)
        $logs = $this->handler->getLogs();
        $this->assertCount(0, $logs);
        
        $this->logger->info('Message 3');
        
        // Should trigger batch flush
        $logs = $this->handler->getLogs();
        $this->assertCount(3, $logs);
    }

    public function testHighPrioritySync(): void
    {
        $this->logger->setAsync(true);
        
        $this->logger->emergency('Emergency message');
        
        // Emergency should be logged immediately (sync)
        $logs = $this->handler->getLogs();
        $this->assertCount(1, $logs);
        $this->assertEquals(LogLevel::EMERGENCY, $logs[0]['level']);
    }

    public function testBatchLogging(): void
    {
        $entries = [
            ['level' => LogLevel::INFO, 'message' => 'Batch message 1'],
            ['level' => LogLevel::WARNING, 'message' => 'Batch message 2'],
            ['level' => LogLevel::ERROR, 'message' => 'Batch message 3']
        ];
        
        $this->logger->logBatch($entries);
        
        $logs = $this->handler->getLogs();
        $this->assertCount(3, $logs);
        
        foreach ($logs as $index => $log) {
            $this->assertEquals($entries[$index]['level'], $log['level']);
            $this->assertEquals($entries[$index]['message'], $log['message']);
        }
    }

    public function testHandlerManagement(): void
    {
        $handler1 = new TestLogHandler();
        $handler2 = new TestLogHandler();
        
        $this->logger->addHandler($handler1);
        $this->logger->addHandler($handler2);
        
        $handlers = $this->logger->getHandlers();
        $this->assertCount(3, $handlers); // Including the default handler from setUp
        
        $this->logger->removeHandler($handler1);
        
        $handlers = $this->logger->getHandlers();
        $this->assertCount(2, $handlers);
    }

    public function testLoggerStatistics(): void
    {
        $this->logger->info('Message 1');
        $this->logger->warning('Message 2');
        $this->logger->logAsync(LogLevel::DEBUG, 'Async message', []);
        
        $this->logger->flush();
        
        $stats = $this->logger->getStats();
        
        $this->assertArrayHasKey('logs_written', $stats);
        $this->assertArrayHasKey('async_logs', $stats);
        $this->assertArrayHasKey('batch_flushes', $stats);
        $this->assertArrayHasKey('handlers_count', $stats);
        $this->assertArrayHasKey('current_level', $stats);
        
        $this->assertGreaterThanOrEqual(3, $stats['logs_written']);
        $this->assertGreaterThan(0, $stats['async_logs']);
    }

    public function testFlushFunctionality(): void
    {
        $this->logger->setAsync(true);
        $this->logger->setBatchSize(10);
        
        $this->logger->info('Async message 1');
        $this->logger->info('Async message 2');
        
        // Should not be logged yet
        $logs = $this->handler->getLogs();
        $this->assertCount(0, $logs);
        
        $this->logger->flush();
        
        // Should be logged after flush
        $logs = $this->handler->getLogs();
        $this->assertCount(2, $logs);
    }

    public function testBatchSizeConfiguration(): void
    {
        $this->logger->setAsync(true);
        $this->logger->setBatchSize(2);
        
        $this->logger->info('Message 1');
        $logs = $this->handler->getLogs();
        $this->assertCount(0, $logs);
        
        $this->logger->info('Message 2');
        
        // Should trigger batch flush at size 2
        $logs = $this->handler->getLogs();
        $this->assertCount(2, $logs);
    }

    public function testFlushInterval(): void
    {
        $this->logger->setAsync(true);
        $this->logger->setFlushInterval(0.1); // 100ms
        $this->logger->setBatchSize(10);
        
        $this->logger->info('Timed message');
        
        // Should not be logged immediately
        $logs = $this->handler->getLogs();
        $this->assertCount(0, $logs);
        
        // Wait for flush interval
        usleep(150000); // 150ms
        
        // Should be logged after interval
        $logs = $this->handler->getLogs();
        $this->assertCount(1, $logs);
    }

    public function testHandlerErrorHandling(): void
    {
        $faultyHandler = new class implements LogHandlerInterface {
            public function handle(string $level, string $message, array $context = []): void
            {
                throw new \RuntimeException('Handler error');
            }
            
            public function handleBatch(array $entries): void
            {
                throw new \RuntimeException('Batch handler error');
            }
            
            public function canHandle(string $level): bool
            {
                return true;
            }
            
            public function setLevel(string $level): void {}
            public function getLevel(): string { return LogLevel::DEBUG; }
            public function flush(): void {}
            public function close(): void {}
        };
        
        $this->logger->addHandler($faultyHandler);
        
        // Should not throw exception, error should be handled internally
        $this->logger->info('Test message');
        $this->logger->flush();
        
        $stats = $this->logger->getStats();
        $this->assertGreaterThan(0, $stats['errors']);
    }

    public function testAsyncToggle(): void
    {
        $this->logger->setAsync(true);
        $this->logger->setBatchSize(10);
        
        $this->logger->info('Async message');
        $logs = $this->handler->getLogs();
        $this->assertCount(0, $logs); // Should be pending
        
        $this->logger->setAsync(false);
        
        // Should flush pending logs when disabling async
        $logs = $this->handler->getLogs();
        $this->assertCount(1, $logs);
        
        $this->logger->info('Sync message');
        
        // Should be logged immediately in sync mode
        $logs = $this->handler->getLogs();
        $this->assertCount(2, $logs);
    }
}

class TestLogHandler implements LogHandlerInterface
{
    private array $logs = [];
    private string $level = LogLevel::DEBUG;

    public function handle(string $level, string $message, array $context = []): void
    {
        $this->logs[] = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'timestamp' => microtime(true)
        ];
    }

    public function handleBatch(array $entries): void
    {
        foreach ($entries as $entry) {
            $this->handle($entry['level'], $entry['message'], $entry['context']);
        }
    }

    public function canHandle(string $level): bool
    {
        return true;
    }

    public function setLevel(string $level): void
    {
        $this->level = $level;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function flush(): void
    {
        // No-op for test handler
    }

    public function close(): void
    {
        $this->logs = [];
    }

    public function getLogs(): array
    {
        return $this->logs;
    }

    public function clearLogs(): void
    {
        $this->logs = [];
    }
}