<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Contracts;

/**
 * Route Match Interface
 * 
 * Represents a matched route with parameters and handler information.
 */
interface RouteMatchInterface
{
    /**
     * Get the matched handler
     */
    public function getHandler(): mixed;

    /**
     * Get route parameters
     */
    public function getParameters(): array;

    /**
     * Get a specific parameter by name
     */
    public function getParameter(string $name, mixed $default = null): mixed;

    /**
     * Check if a parameter exists
     */
    public function hasParameter(string $name): bool;

    /**
     * Get the matched route path
     */
    public function getPath(): string;

    /**
     * Get the HTTP method
     */
    public function getMethod(): string;

    /**
     * Get additional route attributes
     */
    public function getAttributes(): array;
}