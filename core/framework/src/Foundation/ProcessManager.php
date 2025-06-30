<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Foundation;

use HighPerApp\HighPer\Contracts\ProcessManagerInterface;
use HighPerApp\HighPer\Contracts\ApplicationInterface;
use Revolt\EventLoop;

/**
 * Process Manager - Workerman + RevoltPHP Hybrid Architecture
 * 
 * Implements multi-process worker architecture for true CPU core utilization
 * while maintaining RevoltPHP async efficiency within each process.
 * 
 * Total: ~50 LOC as per project plan
 */
class ProcessManager implements ProcessManagerInterface
{
    private array $workers = [];
    private array $config = [];
    private bool $running = false;
    private ApplicationInterface $app;

    public function __construct(ApplicationInterface $app)
    {
        $this->app = $app;
        $this->config = [
            'workers' => (int) shell_exec('nproc') ?: 4,
            'memory_limit' => '256M',
            'restart_threshold' => 10000
        ];
    }

    public function start(): void
    {
        $workers = $this->config['workers'];
        
        for ($i = 0; $i < $workers; $i++) {
            $this->spawnWorker($i);
        }
        $this->running = true;
    }

    private function spawnWorker(int $workerId): void
    {
        $pid = pcntl_fork();
        
        if ($pid === -1) {
            throw new \RuntimeException('Failed to fork worker process');
        } elseif ($pid === 0) {
            $this->runWorker($workerId);
            exit(0);
        } else {
            $this->workers[$workerId] = $pid;
        }
    }

    private function runWorker(int $workerId): void
    {
        // Each worker = RevoltPHP async + multi-protocol support
        $server = $this->app->getContainer()->get('server');
        $server->setConfig($this->getWorkerConfig($workerId));
        
        // Complete secure/non-secure protocol matrix
        $server->enableProtocols(['http', 'https', 'ws', 'wss', 'grpc', 'grpc-tls']);
        
        // NGINX compatibility when deployed behind proxy
        if (getenv('BEHIND_NGINX')) {
            $server->setProxyHeaders(['X-Real-IP', 'X-Forwarded-For', 'X-Forwarded-Proto']);
        }
        
        EventLoop::run();
    }

    private function getWorkerConfig(int $workerId): array
    {
        return ['worker_id' => $workerId, 'memory_limit' => $this->config['memory_limit']];
    }

    public function stop(): void { $this->running = false; foreach ($this->workers as $pid) posix_kill($pid, SIGTERM); }
    public function restart(): void { $this->stop(); $this->start(); }
    public function isRunning(): bool { return $this->running; }
    public function getConfig(): array { return $this->config; }
    public function setConfig(array $config): void { $this->config = array_merge($this->config, $config); }
    public function getStats(): array { return ['workers' => count($this->workers), 'running' => $this->running]; }
    public function getWorkersCount(): int { return count($this->workers); }
    public function scaleWorkers(int $count): void { /* Implementation for scaling */ }
    public function getWorkerPids(): array { return $this->workers; }
}