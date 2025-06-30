<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Contracts;

use Psr\Log\LoggerInterface as PsrLoggerInterface;

/**
 * Logger Interface
 * 
 * Extends PSR-3 LoggerInterface with high-throughput async logging capabilities.
 * Optimized for C10M scenarios with minimal performance impact.
 */
interface LoggerInterface extends PsrLoggerInterface
{
    /**
     * Log asynchronously (non-blocking)
     */
    public function logAsync(string $level, string $message, array $context = []): void;

    /**
     * Batch log multiple entries for performance
     */
    public function logBatch(array $entries): void;

    /**
     * Set minimum log level
     */
    public function setLevel(string $level): void;

    /**
     * Get current log level
     */
    public function getLevel(): string;

    /**
     * Add a log handler
     */
    public function addHandler(LogHandlerInterface $handler): void;

    /**
     * Remove a log handler
     */
    public function removeHandler(LogHandlerInterface $handler): void;

    /**
     * Get all registered handlers
     */
    public function getHandlers(): array;

    /**
     * Flush pending async logs
     */
    public function flush(): void;

    /**
     * Get logger statistics
     */
    public function getStats(): array;

    /**
     * Enable/disable async logging
     */
    public function setAsync(bool $async): void;
}