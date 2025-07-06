<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Contracts;

use Psr\Container\ContainerInterface as PsrContainerInterface;

/**
 * Container Interface
 * 
 * Extends PSR-11 ContainerInterface with additional HighPer-specific methods.
 * This will be implemented by the external highperapp/container package.
 */
interface ContainerInterface extends PsrContainerInterface
{
    /**
     * Bind a service to the container
     */
    public function bind(string $id, mixed $concrete): void;

    /**
     * Bind a singleton service to the container
     */
    public function singleton(string $id, mixed $concrete): void;

    /**
     * Bind a factory function to the container
     */
    public function factory(string $id, callable $factory): void;

    /**
     * Bind an instance to the container
     */
    public function instance(string $id, object $instance): void;

    /**
     * Create an alias for a service
     */
    public function alias(string $alias, string $id): void;

    /**
     * Check if a service is bound
     */
    public function bound(string $id): bool;

    /**
     * Remove a binding from the container
     */
    public function remove(string $id): void;

    /**
     * Get container statistics (for C10M performance monitoring)
     */
    public function getStats(): array;
}