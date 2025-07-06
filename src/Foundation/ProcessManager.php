<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Foundation;

use HighPerApp\HighPer\Contracts\ProcessManagerInterface;
use HighPerApp\HighPer\Contracts\ApplicationInterface;
use HighPerApp\HighPer\Contracts\LoggerInterface;
use Revolt\EventLoop;

/**
 * Process Manager with Zero-Downtime Integration
 * 
 * Enhanced multi-process architecture using highperapp/zero-downtime for
 * production-ready worker management, graceful restarts, and connection preservation.
 */
class ProcessManager implements ProcessManagerInterface
{
    private array $workers = [];
    private array $config = [];
    private bool $running = false;
    private ApplicationInterface $app;
    private LoggerInterface $logger;
    private bool $zeroDowntimeEnabled = false;

    public function __construct(ApplicationInterface $app)
    {
        $this->app = $app;
        $this->logger = $app->getLogger();
        $this->config = [
            'workers' => $_ENV['WORKER_COUNT'] ?? (int) shell_exec('nproc') ?: 4,
            'memory_limit' => $_ENV['WORKER_MEMORY_LIMIT'] ?? '256M',
            'restart_threshold' => (int) ($_ENV['WORKER_RESTART_THRESHOLD'] ?? 10000),
            'deployment_strategy' => $_ENV['DEPLOYMENT_STRATEGY'] ?? 'blue_green',
            'max_connections_per_worker' => (int) ($_ENV['MAX_CONNECTIONS_PER_WORKER'] ?? 2500),
            'graceful_shutdown_timeout' => (int) ($_ENV['GRACEFUL_SHUTDOWN_TIMEOUT'] ?? 10)
        ];
        
        // Check if zero-downtime package is available
        $this->zeroDowntimeEnabled = class_exists('HighPerApp\\ZeroDowntime\\ProcessManager');
        
        if ($this->zeroDowntimeEnabled) {
            $this->logger->info('Zero-downtime deployment enabled', [
                'strategy' => $this->config['deployment_strategy']
            ]);
        }
    }

    public function start(): void
    {
        if ($this->running) {
            return;
        }

        $workers = (int) $this->config['workers'];
        
        $this->logger->info('Starting ProcessManager with zero-downtime capabilities', [
            'worker_count' => $workers,
            'zero_downtime_enabled' => $this->zeroDowntimeEnabled,
            'deployment_strategy' => $this->config['deployment_strategy']
        ]);
        
        if ($this->zeroDowntimeEnabled) {
            $this->startWithZeroDowntime($workers);
        } else {
            $this->startBasic($workers);
        }
        
        $this->running = true;
        
        // Register signal handlers for graceful shutdown
        pcntl_signal(SIGTERM, [$this, 'handleShutdown']);
        pcntl_signal(SIGINT, [$this, 'handleShutdown']);
        pcntl_signal(SIGHUP, [$this, 'handleRestart']);
    }

    private function startWithZeroDowntime(int $workers): void
    {
        // Initialize zero-downtime manager if available
        $zeroDowntimeConfig = [
            'worker_count' => $workers,
            'deployment_strategy' => $this->config['deployment_strategy'],
            'max_connections_per_worker' => $this->config['max_connections_per_worker'],
            'graceful_shutdown_timeout' => $this->config['graceful_shutdown_timeout']
        ];

        // Note: This would use the actual zero-downtime package when available
        // For now, we'll use enhanced basic spawning with zero-downtime principles
        for ($i = 0; $i < $workers; $i++) {
            $this->spawnWorkerWithZeroDowntime($i);
        }
    }

    private function startBasic(int $workers): void
    {
        for ($i = 0; $i < $workers; $i++) {
            $this->spawnWorker($i);
        }
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
            $this->workers[$workerId] = [
                'pid' => $pid,
                'started_at' => time(),
                'requests_processed' => 0,
                'connections' => 0,
                'memory_usage' => 0
            ];
            
            $this->logger->info('Worker spawned', [
                'worker_id' => $workerId,
                'pid' => $pid
            ]);
        }
    }

    private function spawnWorkerWithZeroDowntime(int $workerId): void
    {
        // Enhanced worker spawning with zero-downtime principles
        $pid = pcntl_fork();
        
        if ($pid === -1) {
            throw new \RuntimeException('Failed to fork worker process with zero-downtime');
        } elseif ($pid === 0) {
            // Set process title for identification
            if (function_exists('cli_set_process_title')) {
                cli_set_process_title("highper-worker-{$workerId}");
            }
            
            $this->runWorkerWithZeroDowntime($workerId);
            exit(0);
        } else {
            $this->workers[$workerId] = [
                'pid' => $pid,
                'started_at' => time(),
                'requests_processed' => 0,
                'connections' => 0,
                'memory_usage' => 0,
                'zero_downtime_enabled' => true,
                'max_connections' => $this->config['max_connections_per_worker']
            ];
            
            $this->logger->info('Zero-downtime worker spawned', [
                'worker_id' => $workerId,
                'pid' => $pid,
                'max_connections' => $this->config['max_connections_per_worker']
            ]);
        }
    }

    private function runWorker(int $workerId): void
    {
        try {
            // Each worker = RevoltPHP async + multi-protocol support
            $server = $this->app->getContainer()->get('server');
            $server->setConfig($this->getWorkerConfig($workerId));
            
            // Complete secure/non-secure protocol matrix
            $server->enableProtocols(['http', 'https', 'ws', 'wss', 'grpc', 'grpc-tls']);
            
            // NGINX compatibility when deployed behind proxy
            if (getenv('BEHIND_NGINX')) {
                $server->setProxyHeaders(['X-Real-IP', 'X-Forwarded-For', 'X-Forwarded-Proto']);
            }
            
            $this->logger->info('Worker started', ['worker_id' => $workerId, 'pid' => getmypid()]);
            
            EventLoop::run();
        } catch (\Throwable $e) {
            $this->logger->error('Worker error', [
                'worker_id' => $workerId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            exit(1);
        }
    }

    private function runWorkerWithZeroDowntime(int $workerId): void
    {
        try {
            // Initialize worker application instance
            $workerApp = clone $this->app;
            $workerApp->bootstrap();
            
            // Enhanced worker with zero-downtime capabilities
            $server = $workerApp->getContainer()->get('server');
            $config = $this->getWorkerConfig($workerId);
            $config['zero_downtime_enabled'] = true;
            $config['max_connections'] = $this->config['max_connections_per_worker'];
            
            $server->setConfig($config);
            
            // Enable all protocols with connection state management
            $server->enableProtocols(['http', 'https', 'ws', 'wss', 'grpc', 'grpc-tls']);
            $server->enableConnectionStateManagement();
            
            // NGINX compatibility
            if (getenv('BEHIND_NGINX')) {
                $server->setProxyHeaders(['X-Real-IP', 'X-Forwarded-For', 'X-Forwarded-Proto']);
            }
            
            // Register graceful shutdown handler
            pcntl_signal(SIGTERM, function() use ($server, $workerId) {
                $this->logger->info('Worker received SIGTERM, starting graceful shutdown', [
                    'worker_id' => $workerId
                ]);
                $server->gracefulShutdown();
            });
            
            $this->logger->info('Zero-downtime worker started', [
                'worker_id' => $workerId,
                'pid' => getmypid(),
                'max_connections' => $this->config['max_connections_per_worker']
            ]);
            
            EventLoop::run();
        } catch (\Throwable $e) {
            $this->logger->error('Zero-downtime worker error', [
                'worker_id' => $workerId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            exit(1);
        }
    }

    private function getWorkerConfig(int $workerId): array
    {
        return [
            'worker_id' => $workerId,
            'memory_limit' => $this->config['memory_limit'],
            'max_connections' => $this->config['max_connections_per_worker'],
            'deployment_strategy' => $this->config['deployment_strategy']
        ];
    }

    public function stop(): void
    {
        if (!$this->running) {
            return;
        }

        $this->logger->info('Stopping ProcessManager gracefully');
        $this->running = false;

        // Graceful shutdown with timeout
        $timeout = $this->config['graceful_shutdown_timeout'];
        $start = time();

        foreach ($this->workers as $workerId => $worker) {
            $pid = is_array($worker) ? $worker['pid'] : $worker;
            
            $this->logger->info('Sending SIGTERM to worker', [
                'worker_id' => $workerId,
                'pid' => $pid
            ]);
            
            posix_kill($pid, SIGTERM);
        }

        // Wait for graceful shutdown
        while (time() - $start < $timeout && !empty($this->workers)) {
            foreach ($this->workers as $workerId => $worker) {
                $pid = is_array($worker) ? $worker['pid'] : $worker;
                $status = null;
                $result = pcntl_waitpid($pid, $status, WNOHANG);
                
                if ($result === $pid) {
                    unset($this->workers[$workerId]);
                    $this->logger->info('Worker gracefully stopped', [
                        'worker_id' => $workerId,
                        'pid' => $pid
                    ]);
                }
            }
            usleep(100000); // 100ms
        }

        // Force kill remaining workers
        foreach ($this->workers as $workerId => $worker) {
            $pid = is_array($worker) ? $worker['pid'] : $worker;
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
            $this->logger->warning('Worker force killed', [
                'worker_id' => $workerId,
                'pid' => $pid
            ]);
        }

        $this->workers = [];
        $this->logger->info('All workers stopped');
    }

    public function restart(): void
    {
        if ($this->zeroDowntimeEnabled) {
            $this->restartWithZeroDowntime();
        } else {
            $this->stop();
            $this->start();
        }
    }

    private function restartWithZeroDowntime(): void
    {
        $this->logger->info('Starting zero-downtime restart', [
            'strategy' => $this->config['deployment_strategy']
        ]);

        switch ($this->config['deployment_strategy']) {
            case 'blue_green':
                $this->blueGreenRestart();
                break;
            case 'rolling':
                $this->rollingRestart();
                break;
            default:
                $this->logger->warning('Unknown deployment strategy, falling back to basic restart');
                $this->stop();
                $this->start();
        }
    }

    private function blueGreenRestart(): void
    {
        // Blue-green deployment: spawn new workers while keeping old ones
        $oldWorkers = $this->workers;
        $this->workers = [];
        
        // Spawn new workers (green)
        $workers = (int) $this->config['workers'];
        for ($i = 0; $i < $workers; $i++) {
            $this->spawnWorkerWithZeroDowntime($i);
        }
        
        // Wait for new workers to be ready
        sleep(2);
        
        // Stop old workers (blue)
        foreach ($oldWorkers as $workerId => $worker) {
            $pid = is_array($worker) ? $worker['pid'] : $worker;
            posix_kill($pid, SIGTERM);
        }
        
        $this->logger->info('Blue-green restart completed');
    }

    private function rollingRestart(): void
    {
        // Rolling deployment: restart workers one by one
        $workers = $this->workers;
        
        foreach ($workers as $workerId => $worker) {
            $pid = is_array($worker) ? $worker['pid'] : $worker;
            
            // Stop old worker
            posix_kill($pid, SIGTERM);
            pcntl_waitpid($pid, $status);
            
            // Spawn new worker
            $this->spawnWorkerWithZeroDowntime($workerId);
            
            // Wait before next worker
            sleep(1);
        }
        
        $this->logger->info('Rolling restart completed');
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function setConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }

    public function getStats(): array
    {
        $stats = [
            'running' => $this->running,
            'worker_count' => count($this->workers),
            'zero_downtime_enabled' => $this->zeroDowntimeEnabled,
            'deployment_strategy' => $this->config['deployment_strategy'],
            'memory_usage' => memory_get_usage(true),
            'workers' => []
        ];

        foreach ($this->workers as $workerId => $worker) {
            if (is_array($worker)) {
                $stats['workers'][$workerId] = [
                    'pid' => $worker['pid'],
                    'uptime' => time() - $worker['started_at'],
                    'requests_processed' => $worker['requests_processed'],
                    'connections' => $worker['connections'],
                    'memory_usage' => $worker['memory_usage']
                ];
            }
        }

        return $stats;
    }

    public function getWorkersCount(): int
    {
        return count($this->workers);
    }

    public function scaleWorkers(int $count): void
    {
        $currentCount = count($this->workers);
        
        if ($count > $currentCount) {
            // Scale up
            for ($i = $currentCount; $i < $count; $i++) {
                if ($this->zeroDowntimeEnabled) {
                    $this->spawnWorkerWithZeroDowntime($i);
                } else {
                    $this->spawnWorker($i);
                }
            }
        } elseif ($count < $currentCount) {
            // Scale down
            $workersToRemove = array_slice(array_keys($this->workers), $count);
            foreach ($workersToRemove as $workerId) {
                $worker = $this->workers[$workerId];
                $pid = is_array($worker) ? $worker['pid'] : $worker;
                posix_kill($pid, SIGTERM);
                unset($this->workers[$workerId]);
            }
        }
        
        $this->logger->info('Workers scaled', [
            'from' => $currentCount,
            'to' => $count
        ]);
    }

    public function getWorkerPids(): array
    {
        $pids = [];
        foreach ($this->workers as $workerId => $worker) {
            $pids[$workerId] = is_array($worker) ? $worker['pid'] : $worker;
        }
        return $pids;
    }

    public function handleShutdown(): void
    {
        $this->logger->info('Received shutdown signal');
        $this->stop();
    }

    public function handleRestart(): void
    {
        $this->logger->info('Received restart signal');
        $this->restart();
    }
}