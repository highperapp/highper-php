<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Contracts;

/**
 * Component Health Checker Interface
 * 
 * Defines the contract for component health checking in the HighPer Framework.
 * Used by reliability components to report their health status.
 */
interface ComponentHealthChecker
{
    /**
     * Check if the component is healthy
     */
    public function isHealthy(): bool;

    /**
     * Get health score (0-100)
     */
    public function getHealthScore(): int;

    /**
     * Get component name
     */
    public function getComponentName(): string;

    /**
     * Get last health check timestamp
     */
    public function getLastHealthCheck(): float;

    /**
     * Get health check details
     */
    public function getHealthDetails(): array;

    /**
     * Perform health check
     */
    public function performHealthCheck(): array;

    /**
     * Get health status
     */
    public function getHealthStatus(): string;
}