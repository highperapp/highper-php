<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Contracts;

/**
 * Bootstrap Interface
 * 
 * Defines the contract for application and server bootstrapping.
 * Supports different bootstrap strategies for various deployment scenarios.
 */
interface BootstrapInterface
{
    /**
     * Bootstrap the component
     */
    public function bootstrap(ApplicationInterface $app): void;

    /**
     * Get bootstrap priority (lower numbers boot first)
     */
    public function getPriority(): int;

    /**
     * Check if bootstrap requirements are met
     */
    public function canBootstrap(ApplicationInterface $app): bool;

    /**
     * Get bootstrap dependencies
     */
    public function getDependencies(): array;

    /**
     * Get bootstrap configuration
     */
    public function getConfig(): array;

    /**
     * Shutdown cleanup
     */
    public function shutdown(): void;
}