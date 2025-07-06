<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Contracts;

/**
 * Broadcaster Interface
 * 
 * Defines the contract for high-performance broadcasting
 * with indexed operations for WebSocket optimization.
 */
interface BroadcasterInterface
{
    /**
     * Broadcast message to all subscribers
     */
    public function broadcast(string $channel, mixed $message): void;

    /**
     * Subscribe to channel
     */
    public function subscribe(string $channel, mixed $subscriber): string;

    /**
     * Unsubscribe from channel
     */
    public function unsubscribe(string $channel, string $subscriptionId): bool;

    /**
     * Get subscriber count for channel
     */
    public function getSubscriberCount(string $channel): int;

    /**
     * Get all channels
     */
    public function getChannels(): array;

    /**
     * Get broadcaster statistics
     */
    public function getStats(): array;

    /**
     * Clear channel
     */
    public function clearChannel(string $channel): void;
}