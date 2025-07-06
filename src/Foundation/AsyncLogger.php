<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Foundation;

use HighPerApp\HighPer\Contracts\LoggerInterface;
use HighPerApp\HighPer\Contracts\LogHandlerInterface;
use HighPerApp\HighPer\Contracts\ConfigManagerInterface;
use Psr\Log\LogLevel;
use Revolt\EventLoop;

/**
 * High-Throughput Async Logger
 * 
 * Optimized for larger concurrency scenarios with minimal performance impact.
 * Supports async logging, batching, and multiple handlers.
 */
class AsyncLogger implements LoggerInterface
{
    private array $handlers = [];
    private string $level = LogLevel::DEBUG;
    private bool $asyncEnabled = true;
    private array $pendingLogs = [];
    private int $batchSize = 100;
    private float $flushInterval = 1.0; // seconds
    private array $stats = [
        'logs_written' => 0,
        'async_logs' => 0,
        'batch_flushes' => 0,
        'errors' => 0
    ];

    private const LEVELS = [
        LogLevel::EMERGENCY => 800,
        LogLevel::ALERT => 700,
        LogLevel::CRITICAL => 600,
        LogLevel::ERROR => 500,
        LogLevel::WARNING => 400,
        LogLevel::NOTICE => 300,
        LogLevel::INFO => 200,
        LogLevel::DEBUG => 100,
    ];

    public function __construct(ConfigManagerInterface $config)
    {
        $this->configureFromConfig($config);
        $this->setupDefaultHandlers($config);
        $this->scheduleAsyncFlush();
    }

    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (!$this->isHandling($level)) {
            return;
        }

        $entry = $this->createLogEntry($level, $message, $context);

        if ($this->asyncEnabled && !$this->isHighPriorityLevel($level)) {
            $this->logAsync($level, $message, $context);
        } else {
            $this->writeToHandlers($entry);
        }

        $this->stats['logs_written']++;
    }

    public function logAsync(string $level, string $message, array $context = []): void
    {
        if (!$this->isHandling($level)) {
            return;
        }

        $this->pendingLogs[] = $this->createLogEntry($level, $message, $context);
        $this->stats['async_logs']++;

        // Flush if batch size reached
        if (count($this->pendingLogs) >= $this->batchSize) {
            $this->flushPendingLogs();
        }
    }

    public function logBatch(array $entries): void
    {
        $validEntries = [];
        
        foreach ($entries as $entry) {
            if (isset($entry['level']) && $this->isHandling($entry['level'])) {
                $validEntries[] = $this->normalizeLogEntry($entry);
            }
        }

        if (!empty($validEntries)) {
            foreach ($this->handlers as $handler) {
                try {
                    $handler->handleBatch($validEntries);
                } catch (\Throwable $e) {
                    $this->stats['errors']++;
                    // Fallback to error_log to prevent infinite loops
                    error_log("Logger handler error: " . $e->getMessage());
                }
            }
        }

        $this->stats['logs_written'] += count($validEntries);
    }

    public function setLevel(string $level): void
    {
        if (!isset(self::LEVELS[$level])) {
            throw new \InvalidArgumentException("Invalid log level: {$level}");
        }
        $this->level = $level;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function addHandler(LogHandlerInterface $handler): void
    {
        $this->handlers[] = $handler;
    }

    public function removeHandler(LogHandlerInterface $handler): void
    {
        $index = array_search($handler, $this->handlers, true);
        if ($index !== false) {
            unset($this->handlers[$index]);
            $this->handlers = array_values($this->handlers);
        }
    }

    public function getHandlers(): array
    {
        return $this->handlers;
    }

    public function flush(): void
    {
        $this->flushPendingLogs();
        
        foreach ($this->handlers as $handler) {
            try {
                $handler->flush();
            } catch (\Throwable $e) {
                $this->stats['errors']++;
                error_log("Logger handler flush error: " . $e->getMessage());
            }
        }
    }

    public function getStats(): array
    {
        return array_merge($this->stats, [
            'handlers_count' => count($this->handlers),
            'pending_logs' => count($this->pendingLogs),
            'current_level' => $this->level,
            'async_enabled' => $this->asyncEnabled,
            'batch_size' => $this->batchSize
        ]);
    }

    public function setAsync(bool $async): void
    {
        $this->asyncEnabled = $async;
        
        // If disabling async, flush pending logs immediately
        if (!$async && !empty($this->pendingLogs)) {
            $this->flushPendingLogs();
        }
    }

    /**
     * Set batch size for async logging
     */
    public function setBatchSize(int $size): void
    {
        $this->batchSize = max(1, $size);
    }

    /**
     * Set flush interval for async logging
     */
    public function setFlushInterval(float $interval): void
    {
        $this->flushInterval = max(0.1, $interval);
    }

    private function isHandling(string $level): bool
    {
        return self::LEVELS[$level] >= self::LEVELS[$this->level];
    }

    private function isHighPriorityLevel(string $level): bool
    {
        return in_array($level, [LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL, LogLevel::ERROR]);
    }

    private function createLogEntry(string $level, string|\Stringable $message, array $context): array
    {
        return [
            'level' => $level,
            'message' => $this->interpolate((string) $message, $context),
            'context' => $context,
            'timestamp' => microtime(true),
            'datetime' => date('Y-m-d H:i:s.u'),
            'memory' => memory_get_usage(true),
            'pid' => getmypid()
        ];
    }

    private function normalizeLogEntry(array $entry): array
    {
        return array_merge([
            'level' => LogLevel::INFO,
            'message' => '',
            'context' => [],
            'timestamp' => microtime(true),
            'datetime' => date('Y-m-d H:i:s.u'),
            'memory' => memory_get_usage(true),
            'pid' => getmypid()
        ], $entry);
    }

    private function writeToHandlers(array $entry): void
    {
        foreach ($this->handlers as $handler) {
            try {
                if ($handler->canHandle($entry['level'])) {
                    $handler->handle($entry['level'], $entry['message'], $entry['context']);
                }
            } catch (\Throwable $e) {
                $this->stats['errors']++;
                error_log("Logger handler error: " . $e->getMessage());
            }
        }
    }

    private function flushPendingLogs(): void
    {
        if (empty($this->pendingLogs)) {
            return;
        }

        $logs = $this->pendingLogs;
        $this->pendingLogs = [];

        $this->logBatch($logs);
        $this->stats['batch_flushes']++;
    }

    private function scheduleAsyncFlush(): void
    {
        EventLoop::repeat($this->flushInterval, function() {
            if (!empty($this->pendingLogs)) {
                $this->flushPendingLogs();
            }
        });
    }

    private function interpolate(string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $value) {
            if (is_string($value) || is_numeric($value) || (is_object($value) && method_exists($value, '__toString'))) {
                $replace['{' . $key . '}'] = $value;
            }
        }
        return strtr($message, $replace);
    }

    private function configureFromConfig(ConfigManagerInterface $config): void
    {
        $logConfig = $config->getNamespace('logging');
        
        $this->level = $logConfig['level'] ?? LogLevel::DEBUG;
        $this->asyncEnabled = $logConfig['async'] ?? true;
        $this->batchSize = $logConfig['batch_size'] ?? 100;
        $this->flushInterval = $logConfig['flush_interval'] ?? 1.0;
    }

    private function setupDefaultHandlers(ConfigManagerInterface $config): void
    {
        // For now, add a simple console handler
        // In a real implementation, this would load handlers based on configuration
        $this->addHandler(new class implements LogHandlerInterface {
            private string $level = LogLevel::DEBUG;
            
            public function handle(string $level, string $message, array $context = []): void
            {
                $output = sprintf(
                    "[%s] %s: %s\n",
                    date('Y-m-d H:i:s'),
                    strtoupper($level),
                    $message
                );
                
                if (php_sapi_name() === 'cli') {
                    echo $output;
                } else {
                    error_log(trim($output));
                }
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
                // No-op for console handler
            }
            
            public function close(): void
            {
                // No-op for console handler
            }
        });
    }
}