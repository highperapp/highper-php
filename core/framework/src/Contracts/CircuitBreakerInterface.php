<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Contracts;

/**
 * Circuit Breaker Interface
 * 
 * Defines the contract for circuit breaker pattern implementation
 * with <10ms recovery time and fast failure detection.
 */
interface CircuitBreakerInterface
{
    /**
     * Execute operation with circuit breaker protection
     */
    public function execute(callable $operation): mixed;

    /**
     * Get circuit breaker state
     */
    public function getState(): string;

    /**
     * Check if circuit is open
     */
    public function isOpen(): bool;

    /**
     * Check if circuit is closed
     */
    public function isClosed(): bool;

    /**
     * Check if circuit is half-open
     */
    public function isHalfOpen(): bool;

    /**
     * Force circuit open
     */
    public function forceOpen(): void;

    /**
     * Force circuit closed
     */
    public function forceClosed(): void;

    /**
     * Get circuit statistics
     */
    public function getStats(): array;

    /**
     * Reset circuit breaker
     */
    public function reset(): void;
}