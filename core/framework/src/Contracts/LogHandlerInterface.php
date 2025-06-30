<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Contracts;

/**
 * Log Handler Interface
 * 
 * Defines the contract for log handlers (file, console, remote, etc.).
 */
interface LogHandlerInterface
{
    /**
     * Handle a log entry
     */
    public function handle(string $level, string $message, array $context = []): void;

    /**
     * Handle multiple log entries in batch
     */
    public function handleBatch(array $entries): void;

    /**
     * Check if this handler can handle the given log level
     */
    public function canHandle(string $level): bool;

    /**
     * Set minimum log level for this handler
     */
    public function setLevel(string $level): void;

    /**
     * Get the minimum log level
     */
    public function getLevel(): string;

    /**
     * Flush any pending logs
     */
    public function flush(): void;

    /**
     * Close the handler
     */
    public function close(): void;
}