<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Contracts;

/**
 * Core Application Interface
 * 
 * Defines the contract for the main framework application.
 * Interface-driven design - NO abstract classes, everything extendable.
 */
interface ApplicationInterface
{
    /**
     * Bootstrap the application
     */
    public function bootstrap(): void;

    /**
     * Run the application
     */
    public function run(): void;

    /**
     * Get the container instance
     */
    public function getContainer(): ContainerInterface;

    /**
     * Get the router instance
     */
    public function getRouter(): RouterInterface;

    /**
     * Get application configuration
     */
    public function getConfig(): ConfigManagerInterface;

    /**
     * Get the logger instance
     */
    public function getLogger(): LoggerInterface;

    /**
     * Register a service provider
     */
    public function register(ServiceProviderInterface $provider): void;

    /**
     * Boot registered service providers
     */
    public function bootProviders(): void;

    /**
     * Check if application is running
     */
    public function isRunning(): bool;

    /**
     * Shutdown the application gracefully
     */
    public function shutdown(): void;
}