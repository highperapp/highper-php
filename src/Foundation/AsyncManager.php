<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Foundation;

use HighPerApp\HighPer\Contracts\AsyncManagerInterface;
use Amp\Future;
use Amp\async;
use Amp\delay;
use Revolt\EventLoop;

/**
 * Async Manager - Enhanced async/await with auto-yield
 * 
 * Transparent auto-yield async patterns for maximum efficiency
 * with zero-config async handling.
 * 
 */
class AsyncManager implements AsyncManagerInterface
{
    private array $stats = ['operations' => 0, 'concurrent' => 0];
    private array $timers = [];

    public function autoYield(callable $operation): Future
    {
        $this->stats['operations']++;
        return async(fn() => $operation());
    }

    public function concurrent(array $operations): Future
    {
        $this->stats['concurrent']++;
        $futures = array_map(fn($op) => $this->autoYield($op), $operations);
        return async(fn() => Future\await($futures));
    }

    public function withTimeout(callable $operation, float $timeout): Future
    {
        return async(function() use ($operation, $timeout) {
            $future = $this->autoYield($operation);
            delay($timeout);
            return $future->await();
        });
    }

    public function nextTick(callable $operation): void
    {
        EventLoop::defer($operation);
    }

    public function repeat(float $interval, callable $operation): string
    {
        $id = EventLoop::repeat($interval, $operation);
        $this->timers[$id] = $interval;
        return $id;
    }

    public function isAsync(): bool { return EventLoop::getDriver() !== null; }
    public function getStats(): array { return $this->stats; }
    public function cleanup(): void { foreach($this->timers as $id => $interval) EventLoop::cancel($id); $this->timers = []; }
}