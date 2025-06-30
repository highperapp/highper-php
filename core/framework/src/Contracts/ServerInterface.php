<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Contracts;

/**
 * Server Interface
 * 
 * Defines the contract for high-performance server implementations.
 * Supports multiple protocols (HTTP, WebSocket, gRPC, TCP) and C10M optimization.
 */
interface ServerInterface
{
    /**
     * Start the server
     */
    public function start(): void;

    /**
     * Stop the server
     */
    public function stop(): void;

    /**
     * Restart the server
     */
    public function restart(): void;

    /**
     * Check if server is running
     */
    public function isRunning(): bool;

    /**
     * Get server configuration
     */
    public function getConfig(): array;

    /**
     * Set server configuration
     */
    public function setConfig(array $config): void;

    /**
     * Get server statistics
     */
    public function getStats(): array;

    /**
     * Get supported protocols
     */
    public function getSupportedProtocols(): array;

    /**
     * Add a protocol handler
     */
    public function addProtocolHandler(string $protocol, callable $handler): void;

    /**
     * Remove a protocol handler
     */
    public function removeProtocolHandler(string $protocol): void;

    /**
     * Get current connections count
     */
    public function getConnectionsCount(): int;

    /**
     * Get worker processes count
     */
    public function getWorkersCount(): int;

    /**
     * Scale worker processes
     */
    public function scaleWorkers(int $count): void;
}