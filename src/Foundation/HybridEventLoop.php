<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Foundation;

use HighPerApp\HighPer\Contracts\EventLoopInterface;
use HighPerApp\HighPer\Contracts\LoggerInterface;
use Revolt\EventLoop;
use Revolt\EventLoop\Driver;

/**
 * Hybrid Event Loop with php-uv Auto-Detection
 * 
 * Strategically uses php-uv when available and beneficial for performance,
 * while transparently falling back to RevoltPHP for full compatibility.
 * Environment-configurable with intelligent threshold-based switching.
 */
class HybridEventLoop implements EventLoopInterface
{
    private Driver $revoltLoop;
    private ?object $uvLoop = null;
    private bool $uvAvailable = false;
    private bool $uvEnabled = false;
    private LoggerInterface $logger;
    private array $configuration;
    private array $metrics;
    private int $connectionCount = 0;
    private int $timerCount = 0;
    private bool $isHighPerformanceMode = false;

    public function __construct(LoggerInterface $logger, array $configuration = [])
    {
        $this->logger = $logger;
        $this->configuration = array_merge($this->getDefaultConfiguration(), $configuration);
        $this->metrics = ['uv_usage' => 0, 'revolt_usage' => 0, 'switches' => 0];
        
        $this->initializeEventLoops();
        $this->detectOptimalLoop();
    }

    public function run(): void
    {
        if ($this->shouldUseUV()) {
            $this->runWithUV();
        } else {
            $this->runWithRevolt();
        }
    }

    public function stop(): void
    {
        if ($this->uvLoop && $this->uvAvailable) {
            // Stop UV loop if running
            if (method_exists($this->uvLoop, 'stop')) {
                $this->uvLoop->stop();
            }
        }
        
        EventLoop::getDriver()->stop();
        
        $this->logger->info('HybridEventLoop stopped', [
            'final_metrics' => $this->getMetrics()
        ]);
    }

    public function delay(float $delay, callable $callback): string
    {
        if ($this->shouldUseUVForTimers()) {
            return $this->delayWithUV($delay, $callback);
        }
        
        $this->metrics['revolt_usage']++;
        return EventLoop::delay($delay, $callback);
    }

    public function repeat(float $interval, callable $callback): string
    {
        if ($this->shouldUseUVForTimers()) {
            return $this->repeatWithUV($interval, $callback);
        }
        
        $this->metrics['revolt_usage']++;
        return EventLoop::repeat($interval, $callback);
    }

    public function onReadable($stream, callable $callback): string
    {
        if ($this->shouldUseUVForIO()) {
            return $this->onReadableWithUV($stream, $callback);
        }
        
        $this->metrics['revolt_usage']++;
        return EventLoop::onReadable($stream, $callback);
    }

    public function onWritable($stream, callable $callback): string
    {
        if ($this->shouldUseUVForIO()) {
            return $this->onWritableWithUV($stream, $callback);
        }
        
        $this->metrics['revolt_usage']++;
        return EventLoop::onWritable($stream, $callback);
    }

    public function onSignal(int $signal, callable $callback): string
    {
        // Signals are always handled by RevoltPHP for compatibility
        $this->metrics['revolt_usage']++;
        return EventLoop::onSignal($signal, $callback);
    }

    public function cancel(string $watcherId): void
    {
        // Try to cancel from both loops
        if ($this->uvLoop && $this->uvAvailable) {
            try {
                if (method_exists($this->uvLoop, 'cancel')) {
                    $this->uvLoop->cancel($watcherId);
                }
            } catch (\Throwable $e) {
                // Continue to RevoltPHP cancellation
            }
        }
        
        try {
            EventLoop::cancel($watcherId);
        } catch (\Throwable $e) {
            // Watcher might not exist in RevoltPHP
        }
    }

    public function reference(string $watcherId): void
    {
        EventLoop::reference($watcherId);
    }

    public function unreference(string $watcherId): void
    {
        EventLoop::unreference($watcherId);
    }

    public function defer(callable $callback): string
    {
        $this->metrics['revolt_usage']++;
        return EventLoop::defer($callback);
    }

    public function getDriver(): Driver
    {
        return EventLoop::getDriver();
    }

    public function addConnectionCount(int $count = 1): void
    {
        $this->connectionCount += $count;
        $this->checkForOptimizationSwitch();
    }

    public function removeConnectionCount(int $count = 1): void
    {
        $this->connectionCount = max(0, $this->connectionCount - $count);
        $this->checkForOptimizationSwitch();
    }

    public function getConnectionCount(): int
    {
        return $this->connectionCount;
    }

    public function setHighPerformanceMode(bool $enabled): void
    {
        if ($this->isHighPerformanceMode !== $enabled) {
            $this->isHighPerformanceMode = $enabled;
            $this->logger->info('High performance mode changed', [
                'enabled' => $enabled,
                'will_use_uv' => $this->shouldUseUV()
            ]);
        }
    }

    public function getMetrics(): array
    {
        return array_merge($this->metrics, [
            'uv_available' => $this->uvAvailable,
            'uv_enabled' => $this->uvEnabled,
            'connection_count' => $this->connectionCount,
            'timer_count' => $this->timerCount,
            'high_performance_mode' => $this->isHighPerformanceMode,
            'should_use_uv' => $this->shouldUseUV(),
            'memory_usage' => memory_get_usage(true)
        ]);
    }

    public function getConfiguration(): array
    {
        return $this->configuration;
    }

    public function setConfiguration(array $config): void
    {
        $this->configuration = array_merge($this->configuration, $config);
        $this->logger->info('HybridEventLoop configuration updated', $config);
    }

    private function initializeEventLoops(): void
    {
        // Initialize RevoltPHP event loop (always available)
        $this->revoltLoop = EventLoop::getDriver();
        
        // Check php-uv availability
        $this->uvAvailable = extension_loaded('uv');
        $this->uvEnabled = $this->uvAvailable && $this->configuration['uv_enabled'];
        
        if ($this->uvAvailable && $this->uvEnabled) {
            try {
                $this->uvLoop = uv_default_loop();
                $this->logger->info('php-uv extension detected and enabled', [
                    'uv_version' => phpversion('uv')
                ]);
            } catch (\Throwable $e) {
                $this->uvAvailable = false;
                $this->uvEnabled = false;
                $this->logger->warning('Failed to initialize php-uv loop', [
                    'error' => $e->getMessage()
                ]);
            }
        } else {
            $this->logger->info('php-uv not available or disabled', [
                'extension_loaded' => extension_loaded('uv'),
                'uv_enabled_config' => $this->configuration['uv_enabled']
            ]);
        }
    }

    private function detectOptimalLoop(): void
    {
        $capabilities = [
            'php_uv' => $this->uvAvailable,
            'cpu_cores' => (int) shell_exec('nproc') ?: 4,
            'memory_limit' => ini_get('memory_limit'),
            'expected_load' => $this->getExpectedLoad()
        ];

        $this->logger->info('System capabilities detected', $capabilities);

        // Log optimal configuration
        $this->logger->info('HybridEventLoop initialized', [
            'primary_loop' => 'revolt',
            'uv_available' => $this->uvAvailable,
            'uv_enabled' => $this->uvEnabled,
            'auto_switch_enabled' => $this->configuration['auto_switch'],
            'thresholds' => $this->configuration['thresholds']
        ]);
    }

    private function shouldUseUV(): bool
    {
        if (!$this->uvAvailable || !$this->uvEnabled) {
            return false;
        }

        if (!$this->configuration['auto_switch']) {
            return false;
        }

        $thresholds = $this->configuration['thresholds'];
        
        return (
            $this->connectionCount >= $thresholds['connections'] ||
            $this->timerCount >= $thresholds['timers'] ||
            $this->isHighPerformanceMode
        );
    }

    private function shouldUseUVForTimers(): bool
    {
        return $this->shouldUseUV() && $this->timerCount >= $this->configuration['thresholds']['timers'];
    }

    private function shouldUseUVForIO(): bool
    {
        return $this->shouldUseUV() && $this->connectionCount >= $this->configuration['thresholds']['connections'];
    }

    private function runWithUV(): void
    {
        if (!$this->uvLoop) {
            $this->logger->warning('UV loop not available, falling back to RevoltPHP');
            $this->runWithRevolt();
            return;
        }

        $this->logger->info('Running with php-uv event loop', [
            'connection_count' => $this->connectionCount,
            'timer_count' => $this->timerCount
        ]);

        $this->metrics['uv_usage']++;
        $this->metrics['switches']++;

        try {
            uv_run($this->uvLoop);
        } catch (\Throwable $e) {
            $this->logger->error('UV loop error, falling back to RevoltPHP', [
                'error' => $e->getMessage()
            ]);
            $this->runWithRevolt();
        }
    }

    private function runWithRevolt(): void
    {
        $this->logger->info('Running with RevoltPHP event loop', [
            'connection_count' => $this->connectionCount,
            'timer_count' => $this->timerCount
        ]);

        $this->metrics['revolt_usage']++;
        EventLoop::run();
    }

    private function delayWithUV(float $delay, callable $callback): string
    {
        if (!$this->uvLoop) {
            return $this->delay($delay, $callback); // Fallback
        }

        $timer = uv_timer_init($this->uvLoop);
        $watcherId = spl_object_id($timer);
        
        uv_timer_start($timer, $delay * 1000, 0, function() use ($callback, $timer) {
            $callback();
            uv_timer_stop($timer);
        });

        $this->timerCount++;
        $this->metrics['uv_usage']++;
        
        return (string) $watcherId;
    }

    private function repeatWithUV(float $interval, callable $callback): string
    {
        if (!$this->uvLoop) {
            return $this->repeat($interval, $callback); // Fallback
        }

        $timer = uv_timer_init($this->uvLoop);
        $watcherId = spl_object_id($timer);
        
        uv_timer_start($timer, $interval * 1000, $interval * 1000, $callback);

        $this->timerCount++;
        $this->metrics['uv_usage']++;
        
        return (string) $watcherId;
    }

    private function onReadableWithUV($stream, callable $callback): string
    {
        if (!$this->uvLoop) {
            return $this->onReadable($stream, $callback); // Fallback
        }

        // For UV, we'll use poll for compatibility
        $poll = uv_poll_init($this->uvLoop, $stream);
        $watcherId = spl_object_id($poll);
        
        uv_poll_start($poll, UV::READABLE, $callback);

        $this->metrics['uv_usage']++;
        
        return (string) $watcherId;
    }

    private function onWritableWithUV($stream, callable $callback): string
    {
        if (!$this->uvLoop) {
            return $this->onWritable($stream, $callback); // Fallback
        }

        $poll = uv_poll_init($this->uvLoop, $stream);
        $watcherId = spl_object_id($poll);
        
        uv_poll_start($poll, UV::WRITABLE, $callback);

        $this->metrics['uv_usage']++;
        
        return (string) $watcherId;
    }

    private function checkForOptimizationSwitch(): void
    {
        if (!$this->configuration['auto_switch']) {
            return;
        }

        $shouldUseUV = $this->shouldUseUV();
        $currentlyUsingUV = $this->metrics['uv_usage'] > $this->metrics['revolt_usage'];

        if ($shouldUseUV !== $currentlyUsingUV) {
            $this->logger->info('Event loop optimization switch recommended', [
                'should_use_uv' => $shouldUseUV,
                'currently_using_uv' => $currentlyUsingUV,
                'connection_count' => $this->connectionCount,
                'timer_count' => $this->timerCount
            ]);
        }
    }

    private function getExpectedLoad(): string
    {
        // Analyze environment to determine expected load
        $memoryLimit = ini_get('memory_limit');
        $maxExecutionTime = ini_get('max_execution_time');
        
        if (strpos($memoryLimit, 'G') !== false || (int) $memoryLimit > 512) {
            return 'high';
        } elseif ((int) $memoryLimit > 128) {
            return 'medium';
        } else {
            return 'low';
        }
    }

    private function getDefaultConfiguration(): array
    {
        return [
            'uv_enabled' => (bool) ($_ENV['EVENT_LOOP_UV_ENABLED'] ?? true),
            'auto_switch' => (bool) ($_ENV['EVENT_LOOP_AUTO_SWITCH'] ?? true),
            'thresholds' => [
                'connections' => (int) ($_ENV['EVENT_LOOP_UV_THRESHOLD_CONNECTIONS'] ?? 1000),
                'timers' => (int) ($_ENV['EVENT_LOOP_UV_THRESHOLD_TIMERS'] ?? 100),
                'file_ops' => (int) ($_ENV['EVENT_LOOP_UV_THRESHOLD_FILE_OPS'] ?? 50)
            ],
            'monitoring' => [
                'enabled' => (bool) ($_ENV['EVENT_LOOP_MONITORING_ENABLED'] ?? true),
                'log_switches' => (bool) ($_ENV['EVENT_LOOP_LOG_SWITCHES'] ?? true),
                'performance_tracking' => (bool) ($_ENV['EVENT_LOOP_PERFORMANCE_TRACKING'] ?? true)
            ]
        ];
    }
}