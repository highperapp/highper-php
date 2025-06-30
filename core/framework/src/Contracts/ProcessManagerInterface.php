<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Contracts;

/**
 * Process Manager Interface
 * 
 * Defines the contract for multi-process worker management
 * following Workerman + RevoltPHP hybrid architecture.
 */
interface ProcessManagerInterface
{
    /**
     * Start multi-process workers
     */
    public function start(): void;

    /**
     * Stop workers gracefully
     */
    public function stop(): void;

    /**
     * Restart workers
     */
    public function restart(): void;

    /**
     * Check if workers are running
     */
    public function isRunning(): bool;

    /**
     * Get worker processes configuration
     */
    public function getConfig(): array;

    /**
     * Set worker processes configuration
     */
    public function setConfig(array $config): void;

    /**
     * Get worker statistics
     */
    public function getStats(): array;

    /**
     * Get worker processes count
     */
    public function getWorkersCount(): int;

    /**
     * Scale worker processes
     */
    public function scaleWorkers(int $count): void;

    /**
     * Get worker PIDs
     */
    public function getWorkerPids(): array;
}