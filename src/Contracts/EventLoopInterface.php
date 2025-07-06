<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Contracts;

use Revolt\EventLoop\Driver;

/**
 * Event Loop Interface
 * 
 * Defines the contract for hybrid event loop implementations that can
 * transparently switch between different event loop drivers for optimal performance.
 */
interface EventLoopInterface
{
    /**
     * Run the event loop
     */
    public function run(): void;

    /**
     * Stop the event loop
     */
    public function stop(): void;

    /**
     * Schedule a callback to be executed after a delay
     */
    public function delay(float $delay, callable $callback): string;

    /**
     * Schedule a callback to be executed repeatedly with an interval
     */
    public function repeat(float $interval, callable $callback): string;

    /**
     * Watch a stream for readability
     */
    public function onReadable($stream, callable $callback): string;

    /**
     * Watch a stream for writability
     */
    public function onWritable($stream, callable $callback): string;

    /**
     * Watch for process signals
     */
    public function onSignal(int $signal, callable $callback): string;

    /**
     * Cancel a watcher
     */
    public function cancel(string $watcherId): void;

    /**
     * Reference a watcher (keep event loop running)
     */
    public function reference(string $watcherId): void;

    /**
     * Unreference a watcher (allow event loop to exit)
     */
    public function unreference(string $watcherId): void;

    /**
     * Schedule a callback to be executed in the next iteration
     */
    public function defer(callable $callback): string;

    /**
     * Get the underlying RevoltPHP driver
     */
    public function getDriver(): Driver;

    /**
     * Add to the connection count for optimization decisions
     */
    public function addConnectionCount(int $count = 1): void;

    /**
     * Remove from the connection count
     */
    public function removeConnectionCount(int $count = 1): void;

    /**
     * Get the current connection count
     */
    public function getConnectionCount(): int;

    /**
     * Enable or disable high performance mode
     */
    public function setHighPerformanceMode(bool $enabled): void;

    /**
     * Get event loop metrics and statistics
     */
    public function getMetrics(): array;

    /**
     * Get current configuration
     */
    public function getConfiguration(): array;

    /**
     * Update configuration
     */
    public function setConfiguration(array $config): void;
}