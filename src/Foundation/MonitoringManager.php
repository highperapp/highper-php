<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Foundation;

use HighPerApp\HighPer\Contracts\LoggerInterface;
use HighPerApp\HighPer\Contracts\ApplicationInterface;
use Psr\Log\NullLogger;

/**
 * Monitoring Manager for HighPer Framework
 * 
 * Integrates with existing monitoring packages and provides
 * comprehensive metrics collection and alerting for C10M applications.
 */
class MonitoringManager
{
    private LoggerInterface $logger;
    private ApplicationInterface $app;
    private array $config;
    private array $metrics = [];
    private array $collectors = [];
    private array $alertRules = [];
    private array $stats = [
        'metrics_collected' => 0,
        'alerts_triggered' => 0,
        'collectors_registered' => 0,
        'last_collection_time' => 0
    ];
    private object $monitoringAdapter;

    public function __construct(ApplicationInterface $app, array $config = [], ?LoggerInterface $logger = null)
    {
        $this->app = $app;
        $this->logger = $logger ?? new NullLogger();
        $this->config = array_merge([
            'collection_interval' => 60, // seconds
            'retention_hours' => 24,
            'enable_alerts' => true,
            'enable_external_monitoring' => true,
            'external_monitoring_package' => 'highper-monitoring',
            'metrics_storage' => 'memory', // memory, file, database
            'alert_channels' => ['log'], // log, webhook, email
            'performance_tracking' => true
        ], $config);

        $this->initializeExternalMonitoring();
        $this->registerDefaultCollectors();
        $this->setupDefaultAlerts();
    }

    /**
     * Collect all metrics from registered collectors
     */
    public function collectMetrics(): array
    {
        $startTime = microtime(true);
        $collected = [];

        foreach ($this->collectors as $name => $collector) {
            try {
                $metrics = $this->runCollector($name, $collector);
                $collected[$name] = $metrics;
                $this->storeMetrics($name, $metrics);

                // Check for alerts
                $this->checkAlerts($name, $metrics);

            } catch (\Throwable $e) {
                $this->logger->error("Metrics collection failed: {$name}", [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
            }
        }

        $duration = (microtime(true) - $startTime) * 1000;
        $this->stats['metrics_collected']++;
        $this->stats['last_collection_time'] = time();

        $this->logger->debug('Metrics collection completed', [
            'collectors' => count($this->collectors),
            'duration_ms' => round($duration, 2)
        ]);

        return $collected;
    }

    /**
     * Get performance metrics for C10M optimization
     */
    public function getPerformanceMetrics(): array
    {
        return [
            'timestamp' => date('c'),
            'connections' => $this->getConnectionMetrics(),
            'memory' => $this->getMemoryMetrics(),
            'cpu' => $this->getCpuMetrics(),
            'network' => $this->getNetworkMetrics(),
            'application' => $this->getApplicationMetrics(),
            'event_loop' => $this->getEventLoopMetrics()
        ];
    }

    /**
     * Register a custom metrics collector
     */
    public function registerCollector(string $name, callable $collector): void
    {
        $this->collectors[$name] = $collector;
        $this->stats['collectors_registered']++;

        $this->logger->debug("Metrics collector registered: {$name}");
    }

    /**
     * Register an alert rule
     */
    public function registerAlert(string $name, array $rule): void
    {
        $this->alertRules[$name] = array_merge([
            'metric' => '',
            'threshold' => 0,
            'operator' => '>',
            'severity' => 'warning',
            'cooldown' => 300, // 5 minutes
            'channels' => ['log']
        ], $rule);

        $this->logger->debug("Alert rule registered: {$name}", $rule);
    }

    /**
     * Get monitoring statistics
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'collectors_count' => count($this->collectors),
            'alert_rules_count' => count($this->alertRules),
            'stored_metrics' => count($this->metrics),
            'external_monitoring' => isset($this->monitoringAdapter),
            'config' => $this->config
        ]);
    }

    /**
     * Export metrics in Prometheus format
     */
    public function exportPrometheus(): string
    {
        $metrics = $this->collectMetrics();
        $prometheus = [];

        foreach ($metrics as $collector => $data) {
            if (is_array($data)) {
                foreach ($data as $key => $value) {
                    if (is_numeric($value)) {
                        $metricName = "highper_{$collector}_{$key}";
                        $prometheus[] = "# TYPE {$metricName} gauge";
                        $prometheus[] = "{$metricName} {$value}";
                    }
                }
            }
        }

        return implode("\n", $prometheus) . "\n";
    }

    /**
     * Send metrics to external monitoring system
     */
    public function sendToExternalMonitoring(array $metrics): bool
    {
        if (!$this->config['enable_external_monitoring'] || !isset($this->monitoringAdapter)) {
            return false;
        }

        try {
            return $this->monitoringAdapter->sendMetrics($metrics);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send metrics to external monitoring', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function initializeExternalMonitoring(): void
    {
        $packageClass = '\\EaseAppPHP\\HighPer\\Monitoring\\Manager';
        
        if (class_exists($packageClass)) {
            try {
                $this->monitoringAdapter = new $packageClass($this->config);
                $this->logger->info('External monitoring package initialized');
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to initialize external monitoring', [
                    'error' => $e->getMessage()
                ]);
            }
        } else {
            // Create simple adapter for basic functionality
            $this->monitoringAdapter = new class {
                public function sendMetrics(array $metrics): bool {
                    return true; // Placeholder
                }
            };
        }
    }

    private function registerDefaultCollectors(): void
    {
        // System metrics collector
        $this->registerCollector('system', function() {
            return [
                'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                'cpu_load_1min' => $this->getLoadAverage()[0] ?? 0,
                'disk_usage_percent' => $this->getDiskUsagePercent(),
                'uptime_seconds' => $this->getUptime()
            ];
        });

        // Application metrics collector
        $this->registerCollector('application', function() {
            $stats = $this->app->getStats();
            return [
                'providers_count' => $stats['providers_count'] ?? 0,
                'container_bindings' => $stats['container_stats']['bindings_count'] ?? 0,
                'routes_count' => $stats['router_stats']['routes_count'] ?? 0,
                'is_running' => $this->app->isRunning() ? 1 : 0
            ];
        });

        // Connection pool metrics collector
        $this->registerCollector('connections', function() {
            $container = $this->app->getContainer();
            
            if ($container->has('EaseAppPHP\\HighPer\\Performance\\ConnectionPoolManager')) {
                $poolManager = $container->get('EaseAppPHP\\HighPer\\Performance\\ConnectionPoolManager');
                return $poolManager->getStats();
            }
            
            return [
                'pools_total' => 0,
                'connections_active' => 0,
                'connections_idle' => 0
            ];
        });

        // Performance metrics collector
        $this->registerCollector('performance', function() {
            $container = $this->app->getContainer();
            $metrics = [];
            
            if ($container->has('EaseAppPHP\\HighPer\\Performance\\MemoryManager')) {
                $memoryManager = $container->get('EaseAppPHP\\HighPer\\Performance\\MemoryManager');
                $memStats = $memoryManager->getStats();
                
                $metrics = array_merge($metrics, [
                    'object_pool_hits' => $memStats['pool_hits'] ?? 0,
                    'object_pool_misses' => $memStats['pool_misses'] ?? 0,
                    'gc_runs' => $memStats['gc_runs'] ?? 0,
                    'memory_warnings' => $memStats['memory_warnings'] ?? 0
                ]);
            }
            
            return $metrics;
        });

        $this->logger->debug('Default metrics collectors registered', [
            'collectors' => count($this->collectors)
        ]);
    }

    private function setupDefaultAlerts(): void
    {
        if (!$this->config['enable_alerts']) {
            return;
        }

        // High memory usage alert
        $this->registerAlert('high_memory_usage', [
            'metric' => 'system.memory_usage_mb',
            'threshold' => 400, // 400MB
            'operator' => '>',
            'severity' => 'warning',
            'cooldown' => 300
        ]);

        // Critical memory usage alert
        $this->registerAlert('critical_memory_usage', [
            'metric' => 'system.memory_usage_mb',
            'threshold' => 480, // 480MB
            'operator' => '>',
            'severity' => 'critical',
            'cooldown' => 60
        ]);

        // High CPU load alert
        $this->registerAlert('high_cpu_load', [
            'metric' => 'system.cpu_load_1min',
            'threshold' => 2.0,
            'operator' => '>',
            'severity' => 'warning',
            'cooldown' => 300
        ]);

        // Connection pool exhaustion alert
        $this->registerAlert('connection_pool_exhaustion', [
            'metric' => 'connections.pool_misses',
            'threshold' => 100,
            'operator' => '>',
            'severity' => 'critical',
            'cooldown' => 60
        ]);

        $this->logger->debug('Default alert rules configured', [
            'rules' => count($this->alertRules)
        ]);
    }

    private function runCollector(string $name, callable $collector): array
    {
        $startTime = microtime(true);
        $result = call_user_func($collector);
        $duration = (microtime(true) - $startTime) * 1000;

        if (!is_array($result)) {
            throw new \InvalidArgumentException("Collector {$name} must return an array");
        }

        $result['_collection_duration_ms'] = round($duration, 2);
        $result['_timestamp'] = time();

        return $result;
    }

    private function storeMetrics(string $collector, array $metrics): void
    {
        $timestamp = time();
        
        if (!isset($this->metrics[$collector])) {
            $this->metrics[$collector] = [];
        }

        $this->metrics[$collector][$timestamp] = $metrics;

        // Clean old metrics based on retention
        $retentionTime = $timestamp - ($this->config['retention_hours'] * 3600);
        foreach ($this->metrics[$collector] as $time => $data) {
            if ($time < $retentionTime) {
                unset($this->metrics[$collector][$time]);
            }
        }
    }

    private function checkAlerts(string $collector, array $metrics): void
    {
        foreach ($this->alertRules as $alertName => $rule) {
            $metricPath = explode('.', $rule['metric']);
            
            if (count($metricPath) === 2 && $metricPath[0] === $collector) {
                $metricValue = $metrics[$metricPath[1]] ?? null;
                
                if ($metricValue !== null && $this->evaluateAlert($metricValue, $rule)) {
                    $this->triggerAlert($alertName, $rule, $metricValue);
                }
            }
        }
    }

    private function evaluateAlert($value, array $rule): bool
    {
        $threshold = $rule['threshold'];
        
        return match($rule['operator']) {
            '>' => $value > $threshold,
            '<' => $value < $threshold,
            '>=' => $value >= $threshold,
            '<=' => $value <= $threshold,
            '==' => $value == $threshold,
            '!=' => $value != $threshold,
            default => false
        };
    }

    private function triggerAlert(string $name, array $rule, $value): void
    {
        static $lastAlerts = [];
        
        $now = time();
        $lastAlert = $lastAlerts[$name] ?? 0;
        
        if ($now - $lastAlert < $rule['cooldown']) {
            return; // Still in cooldown
        }

        $lastAlerts[$name] = $now;
        $this->stats['alerts_triggered']++;

        $alertData = [
            'alert' => $name,
            'severity' => $rule['severity'],
            'metric' => $rule['metric'],
            'value' => $value,
            'threshold' => $rule['threshold'],
            'operator' => $rule['operator'],
            'timestamp' => date('c')
        ];

        foreach ($rule['channels'] as $channel) {
            $this->sendAlert($channel, $alertData);
        }

        $this->logger->warning("Alert triggered: {$name}", $alertData);
    }

    private function sendAlert(string $channel, array $alertData): void
    {
        switch ($channel) {
            case 'log':
                $level = $alertData['severity'] === 'critical' ? 'critical' : 'warning';
                $this->logger->log($level, "ALERT: {$alertData['alert']}", $alertData);
                break;
                
            case 'webhook':
                // Implement webhook alert sending
                break;
                
            case 'email':
                // Implement email alert sending
                break;
        }
    }

    private function getConnectionMetrics(): array
    {
        // Placeholder - would integrate with actual server metrics
        return [
            'active' => 0,
            'total' => 0,
            'rate_per_second' => 0
        ];
    }

    private function getMemoryMetrics(): array
    {
        return [
            'current_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'limit_mb' => (int) ini_get('memory_limit')
        ];
    }

    private function getCpuMetrics(): array
    {
        $load = $this->getLoadAverage();
        return [
            'load_1min' => $load[0] ?? 0,
            'load_5min' => $load[1] ?? 0,
            'load_15min' => $load[2] ?? 0
        ];
    }

    private function getNetworkMetrics(): array
    {
        // Placeholder - would integrate with system network stats
        return [
            'bytes_in' => 0,
            'bytes_out' => 0,
            'packets_in' => 0,
            'packets_out' => 0
        ];
    }

    private function getApplicationMetrics(): array
    {
        return $this->app->getStats();
    }

    private function getEventLoopMetrics(): array
    {
        // Placeholder - would integrate with AMPHP/Revolt metrics
        return [
            'pending_tasks' => 0,
            'executed_tasks' => 0,
            'average_task_duration_ms' => 0
        ];
    }

    private function getLoadAverage(): array
    {
        if (function_exists('sys_getloadavg')) {
            return sys_getloadavg();
        }
        return [0, 0, 0];
    }

    private function getDiskUsagePercent(): float
    {
        $path = getcwd() ?: '/';
        $total = disk_total_space($path);
        $free = disk_free_space($path);
        return round((($total - $free) / $total) * 100, 2);
    }

    private function getUptime(): int
    {
        static $startTime = null;
        if ($startTime === null) {
            $startTime = time();
        }
        return time() - $startTime;
    }
}