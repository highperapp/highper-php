<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Contracts;

/**
 * Router Interface
 * 
 * Defines the contract for ultra-fast O(1) routing.
 * This will be implemented by the external highperapp/router package.
 */
interface RouterInterface
{
    /**
     * Add a route to the router
     */
    public function addRoute(string $method, string $path, mixed $handler): void;

    /**
     * Add multiple routes in batch for performance
     */
    public function addRoutes(array $routes): void;

    /**
     * Match a route against method and path
     */
    public function match(string $method, string $path): ?RouteMatchInterface;

    /**
     * Get all registered routes
     */
    public function getRoutes(): array;

    /**
     * Clear the route cache
     */
    public function clearCache(): void;

    /**
     * Get router statistics (for C10M performance monitoring)
     */
    public function getStats(): array;

    /**
     * Set route caching options
     */
    public function setCacheOptions(array $options): void;
}