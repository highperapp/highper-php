<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Foundation;

use HighPerApp\HighPer\Contracts\ServiceProviderInterface;
use HighPerApp\HighPer\Contracts\ContainerInterface;
use HighPerApp\HighPer\Contracts\ApplicationInterface;

/**
 * Service Provider
 * 
 * Base service provider class following the ea-rapid pattern.
 * Simple, clean implementation that can be extended for specific needs.
 */
class ServiceProvider implements ServiceProviderInterface
{
    protected ContainerInterface $container;
    protected ApplicationInterface $app;

    public function __construct(ApplicationInterface $app)
    {
        $this->app = $app;
        $this->container = $app->getContainer();
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Default implementation - override in extending classes
    }

    /**
     * Boot any application services.
     */
    public function boot(): void
    {
        // Default implementation - override in extending classes
    }

    /**
     * Helper method to bind a service to the container
     */
    protected function bind(string $id, mixed $concrete): void
    {
        $this->container->bind($id, $concrete);
    }

    /**
     * Helper method to register a singleton service
     */
    protected function singleton(string $id, mixed $concrete): void
    {
        $this->container->singleton($id, $concrete);
    }

    /**
     * Helper method to register an instance
     */
    protected function instance(string $id, object $instance): void
    {
        $this->container->instance($id, $instance);
    }

    /**
     * Helper method to register an alias
     */
    protected function alias(string $alias, string $id): void
    {
        $this->container->alias($alias, $id);
    }

    /**
     * Get environment variable with fallback
     */
    protected function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? getenv($key);
        
        if ($value === false) {
            return $default;
        }
        
        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => $value
        };
    }

    /**
     * Get configuration value
     */
    protected function config(string $key, mixed $default = null): mixed
    {
        return $this->app->getConfig()->get($key, $default);
    }

    /**
     * Log a message
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        $this->app->getLogger()->log($level, $message, $context);
    }
}