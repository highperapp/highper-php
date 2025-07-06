<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Contracts;

/**
 * Zero Downtime Interface
 * 
 * Defines the contract for zero-downtime deployment support
 * with WebSocket preservation and graceful transitions.
 */
interface ZeroDowntimeInterface
{
    /**
     * Prepare for deployment
     */
    public function prepareDeployment(): void;

    /**
     * Execute deployment with zero downtime
     */
    public function deploy(): void;

    /**
     * Rollback deployment if needed
     */
    public function rollback(): void;

    /**
     * Preserve WebSocket connections during deployment
     */
    public function preserveWebSockets(): void;

    /**
     * Transfer connections to new instance
     */
    public function transferConnections(): void;

    /**
     * Check deployment health
     */
    public function checkHealth(): bool;

    /**
     * Get deployment status
     */
    public function getStatus(): array;
}