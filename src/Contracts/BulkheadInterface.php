<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Contracts;

/**
 * Bulkhead Interface
 * 
 * Defines the contract for bulkhead isolation pattern
 * to prevent cascade failures across service boundaries.
 */
interface BulkheadInterface
{
    /**
     * Execute operation within isolated bulkhead
     */
    public function execute(string $compartment, callable $operation): mixed;

    /**
     * Create isolated compartment
     */
    public function createCompartment(string $name, array $config): void;

    /**
     * Check compartment health
     */
    public function isCompartmentHealthy(string $compartment): bool;

    /**
     * Isolate compartment
     */
    public function isolateCompartment(string $compartment): void;

    /**
     * Recover compartment
     */
    public function recoverCompartment(string $compartment): bool;

    /**
     * Get compartment statistics
     */
    public function getCompartmentStats(string $compartment): array;

    /**
     * Get all compartments
     */
    public function getCompartments(): array;

    /**
     * Get bulkhead status
     */
    public function getStatus(): array;
}