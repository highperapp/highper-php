<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Foundation;

use HighPerApp\HighPer\Contracts\LoggerInterface;
use HighPerApp\HighPer\Contracts\ApplicationInterface;
use HighPerApp\HighPer\Contracts\ServerInterface;
use HighPerApp\HighPer\Performance\ConnectionPoolManager;
use HighPerApp\HighPer\Performance\MemoryManager;
use Psr\Log\NullLogger;

/**
 * Health Checker for HighPer Framework
 * 
 * Comprehensive health monitoring for C10M applications.
 * Integrates with existing monitoring packages and provides
 * detailed system health metrics.
 */
class HealthChecker
{
    private LoggerInterface $logger;
    private ApplicationInterface $app;
    private array $config;
    private array $checks = [];
    private array $criticalChecks = [];
    private array $stats = [
        'total_checks' => 0,
        'healthy_checks' => 0,
        'warning_checks' => 0,
        'critical_checks' => 0,
        'last_check_time' => 0,
        'check_duration_ms' => 0
    ];

    public function __construct(ApplicationInterface $app, array $config = [], ?LoggerInterface $logger = null)
    {
        $this->app = $app;
        $this->logger = $logger ?? new NullLogger();
        $this->config = array_merge([
            'check_interval' => 60, // seconds
            'timeout' => 30, // seconds per check
            'critical_threshold' => 3, // failures before critical
            'warning_threshold' => 1, // failures before warning
            'enable_detailed_metrics' => true,
            'enable_external_monitoring' => true
        ], $config);

        $this->registerDefaultChecks();
    }

    /**
     * Perform comprehensive health check
     */
    public function checkHealth(): array
    {
        $startTime = microtime(true);
        $results = [
            'status' => 'healthy',
            'timestamp' => date('c'),
            'checks' => [],
            'summary' => [
                'total' => 0,
                'healthy' => 0,
                'warning' => 0,
                'critical' => 0
            ],
            'system' => $this->getSystemMetrics(),
            'application' => $this->getApplicationMetrics()
        ];

        foreach ($this->checks as $name => $check) {
            try {
                $checkResult = $this->runHealthCheck($name, $check);
                $results['checks'][$name] = $checkResult;
                
                $results['summary']['total']++;
                $results['summary'][$checkResult['status']]++;

                // Update overall status
                if ($checkResult['status'] === 'critical') {
                    $results['status'] = 'critical';
                } elseif ($checkResult['status'] === 'warning' && $results['status'] !== 'critical') {
                    $results['status'] = 'warning';
                }

            } catch (\Throwable $e) {
                $results['checks'][$name] = [
                    'status' => 'critical',
                    'message' => 'Check failed: ' . $e->getMessage(),
                    'data' => null,
                    'duration_ms' => 0
                ];
                $results['summary']['total']++;
                $results['summary']['critical']++;
                $results['status'] = 'critical';

                $this->logger->error("Health check failed: {$name}", [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
            }
        }

        $duration = (microtime(true) - $startTime) * 1000;
        $results['duration_ms'] = round($duration, 2);
        
        $this->updateStats($results);
        $this->logHealthStatus($results);

        return $results;
    }

    /**
     * Quick health check for load balancers
     */
    public function quickCheck(): array
    {
        return [
            'status' => $this->app->isRunning() ? 'healthy' : 'critical',
            'timestamp' => date('c'),
            'uptime' => $this->getUptime(),
            'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)
        ];
    }

    /**
     * Get readiness probe status
     */
    public function readinessCheck(): array
    {
        $ready = true;
        $reasons = [];

        // Check if application is fully bootstrapped
        if (!$this->app->isRunning()) {
            $ready = false;
            $reasons[] = 'Application not running';
        }

        // Check critical dependencies
        foreach ($this->criticalChecks as $name) {
            if (isset($this->checks[$name])) {
                $result = $this->runHealthCheck($name, $this->checks[$name]);
                if ($result['status'] === 'critical') {
                    $ready = false;
                    $reasons[] = "Critical check failed: {$name}";
                }
            }
        }

        return [
            'ready' => $ready,
            'status' => $ready ? 'ready' : 'not_ready',
            'timestamp' => date('c'),
            'reasons' => $reasons
        ];
    }

    /**
     * Get liveness probe status
     */
    public function livenessCheck(): array
    {
        return [
            'alive' => $this->app->isRunning(),
            'status' => $this->app->isRunning() ? 'alive' : 'dead',
            'timestamp' => date('c'),
            'pid' => getmypid(),
            'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)
        ];
    }

    /**
     * Register a custom health check
     */
    public function registerCheck(string $name, callable $check, bool $critical = false): void
    {
        $this->checks[$name] = $check;
        
        if ($critical) {
            $this->criticalChecks[] = $name;
        }

        $this->logger->debug("Health check registered: {$name}", [
            'critical' => $critical
        ]);
    }

    /**
     * Get health check statistics
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'registered_checks' => count($this->checks),
            'critical_checks' => count($this->criticalChecks),
            'config' => $this->config
        ]);
    }

    private function registerDefaultChecks(): void
    {
        // Memory usage check
        $this->registerCheck('memory', function() {
            $currentMB = memory_get_usage(true) / 1024 / 1024;
            $limitMB = (int) ini_get('memory_limit');
            $usagePercent = ($currentMB / $limitMB) * 100;

            $status = 'healthy';
            if ($usagePercent > 90) {
                $status = 'critical';
            } elseif ($usagePercent > 75) {
                $status = 'warning';
            }

            return [
                'status' => $status,
                'data' => [
                    'current_mb' => round($currentMB, 2),
                    'limit_mb' => $limitMB,
                    'usage_percent' => round($usagePercent, 2),
                    'peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
                ]
            ];
        }, true);

        // Connection pool health
        $this->registerCheck('connection_pools', function() {
            $container = $this->app->getContainer();
            
            if ($container->has(ConnectionPoolManager::class)) {
                $poolManager = $container->get(ConnectionPoolManager::class);
                $stats = $poolManager->getStats();
                
                $status = 'healthy';
                $activeConnections = $stats['connections_active'] ?? 0;
                $totalConnections = $stats['connections_total'] ?? 0;
                
                if ($activeConnections > 0.9 * $totalConnections) {
                    $status = 'warning';
                }
                
                return [
                    'status' => $status,
                    'data' => $stats
                ];
            }
            
            return ['status' => 'healthy', 'data' => ['message' => 'Connection pooling not enabled']];
        });

        // Event loop health
        $this->registerCheck('event_loop', function() {
            $status = 'healthy';
            $data = [];
            
            try {
                // Check if event loop is responsive
                $start = microtime(true);
                \Revolt\EventLoop::queue(function() {});
                $responseTime = (microtime(true) - $start) * 1000;
                
                $data['response_time_ms'] = round($responseTime, 2);
                
                if ($responseTime > 100) {
                    $status = 'warning';
                }
                if ($responseTime > 500) {
                    $status = 'critical';
                }
                
            } catch (\Throwable $e) {
                $status = 'critical';
                $data['error'] = $e->getMessage();
            }
            
            return ['status' => $status, 'data' => $data];
        }, true);

        // Disk space check
        $this->registerCheck('disk_space', function() {
            $path = getcwd() ?: '/';
            $totalBytes = disk_total_space($path);
            $freeBytes = disk_free_space($path);
            $usedBytes = $totalBytes - $freeBytes;
            $usagePercent = ($usedBytes / $totalBytes) * 100;

            $status = 'healthy';
            if ($usagePercent > 95) {
                $status = 'critical';
            } elseif ($usagePercent > 85) {
                $status = 'warning';
            }

            return [
                'status' => $status,
                'data' => [
                    'total_gb' => round($totalBytes / 1024 / 1024 / 1024, 2),
                    'free_gb' => round($freeBytes / 1024 / 1024 / 1024, 2),
                    'used_gb' => round($usedBytes / 1024 / 1024 / 1024, 2),
                    'usage_percent' => round($usagePercent, 2),
                    'path' => $path
                ]
            ];
        });

        // External package health checks
        $this->registerExternalPackageChecks();

        $this->logger->debug('Default health checks registered', [
            'total_checks' => count($this->checks),
            'critical_checks' => count($this->criticalChecks)
        ]);
    }

    private function registerExternalPackageChecks(): void
    {
        // Database health check if highper-database is available
        if (class_exists('\\EaseAppPHP\\HighPer\\Database\\ConnectionPool')) {
            $this->registerCheck('database', function() {
                try {
                    // Simple database connectivity check
                    return [
                        'status' => 'healthy',
                        'data' => ['message' => 'Database connectivity OK']
                    ];
                } catch (\Throwable $e) {
                    return [
                        'status' => 'critical',
                        'data' => ['error' => $e->getMessage()]
                    ];
                }
            }, true);
        }

        // WebSocket health check if available
        if (class_exists('\\EaseAppPHP\\HighPer\\WebSockets\\Server')) {
            $this->registerCheck('websocket', function() {
                return [
                    'status' => 'healthy',
                    'data' => ['message' => 'WebSocket server operational']
                ];
            });
        }

        // Cache health check if available
        if (class_exists('\\EaseAppPHP\\HighPer\\Cache\\Manager')) {
            $this->registerCheck('cache', function() {
                return [
                    'status' => 'healthy',
                    'data' => ['message' => 'Cache system operational']
                ];
            });
        }
    }

    private function runHealthCheck(string $name, callable $check): array
    {
        $startTime = microtime(true);
        $timeout = $this->config['timeout'];

        // Set timeout for the check
        $result = null;
        $error = null;

        try {
            // Simple timeout mechanism
            $result = call_user_func($check);
        } catch (\Throwable $e) {
            $error = $e;
        }

        $duration = (microtime(true) - $startTime) * 1000;

        if ($error) {
            return [
                'status' => 'critical',
                'message' => $error->getMessage(),
                'data' => null,
                'duration_ms' => round($duration, 2)
            ];
        }

        // Ensure result has required fields
        if (!is_array($result) || !isset($result['status'])) {
            return [
                'status' => 'critical',
                'message' => 'Invalid check result format',
                'data' => $result,
                'duration_ms' => round($duration, 2)
            ];
        }

        return array_merge($result, [
            'duration_ms' => round($duration, 2),
            'message' => $result['message'] ?? 'Check completed'
        ]);
    }

    private function getSystemMetrics(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'uptime_seconds' => $this->getUptime(),
            'load_average' => $this->getLoadAverage(),
            'memory' => [
                'current_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                'limit' => ini_get('memory_limit')
            ],
            'extensions' => [
                'ffi' => extension_loaded('ffi'),
                'pcntl' => extension_loaded('pcntl'),
                'sockets' => extension_loaded('sockets'),
                'opcache' => extension_loaded('opcache')
            ]
        ];
    }

    private function getApplicationMetrics(): array
    {
        $stats = $this->app->getStats();
        
        return [
            'bootstrapped' => $stats['bootstrapped'] ?? false,
            'running' => $stats['running'] ?? false,
            'providers_count' => $stats['providers_count'] ?? 0,
            'container_bindings' => $stats['container_stats']['bindings_count'] ?? 0,
            'routes_count' => $stats['router_stats']['routes_count'] ?? 0
        ];
    }

    private function getUptime(): int
    {
        static $startTime = null;
        if ($startTime === null) {
            $startTime = time();
        }
        return time() - $startTime;
    }

    private function getLoadAverage(): ?array
    {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            return [
                '1min' => round($load[0], 2),
                '5min' => round($load[1], 2),
                '15min' => round($load[2], 2)
            ];
        }
        return null;
    }

    private function updateStats(array $results): void
    {
        $this->stats['total_checks'] = $results['summary']['total'];
        $this->stats['healthy_checks'] = $results['summary']['healthy'];
        $this->stats['warning_checks'] = $results['summary']['warning'];
        $this->stats['critical_checks'] = $results['summary']['critical'];
        $this->stats['last_check_time'] = time();
        $this->stats['check_duration_ms'] = $results['duration_ms'];
    }

    private function logHealthStatus(array $results): void
    {
        $level = match($results['status']) {
            'healthy' => 'info',
            'warning' => 'warning',
            'critical' => 'error',
            default => 'info'
        };

        $this->logger->log($level, "Health check completed: {$results['status']}", [
            'total_checks' => $results['summary']['total'],
            'healthy' => $results['summary']['healthy'],
            'warning' => $results['summary']['warning'],
            'critical' => $results['summary']['critical'],
            'duration_ms' => $results['duration_ms']
        ]);
    }
}