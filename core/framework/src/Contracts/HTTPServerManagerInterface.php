<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Contracts;

/**
 * HTTP Server Manager Interface
 * 
 * Defines the contract for enhanced AMPHP HTTP server integration
 * with complete secure/non-secure protocol matrix support.
 */
interface HTTPServerManagerInterface
{
    /**
     * Start HTTP server with protocol matrix
     */
    public function start(): void;

    /**
     * Stop HTTP server gracefully
     */
    public function stop(): void;

    /**
     * Enable specific protocols
     */
    public function enableProtocols(array $protocols): void;

    /**
     * Configure proxy headers for NGINX compatibility
     */
    public function setProxyHeaders(array $headers): void;

    /**
     * Set server configuration
     */
    public function setConfig(array $config): void;

    /**
     * Get server statistics
     */
    public function getStats(): array;

    /**
     * Check if server is running
     */
    public function isRunning(): bool;

    /**
     * Get enabled protocols
     */
    public function getEnabledProtocols(): array;

    /**
     * Handle graceful shutdown
     */
    public function gracefulShutdown(): void;
}