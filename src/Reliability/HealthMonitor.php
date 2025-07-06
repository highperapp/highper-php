<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Reliability;

use HighPerApp\HighPer\Contracts\LoggerInterface;
use HighPerApp\HighPer\Contracts\FFIManagerInterface;

/**
 * Lightweight Health Monitor for HighPer Framework
 * 
 * Provides basic health monitoring focused on framework-specific components
 * without duplicating the comprehensive standalone monitoring library.
 * 
 * Features:
 * - Framework component health checks (circuit breakers, bulkheads, self-healing)
 * - Basic system resource monitoring (memory, CPU, connections)
 * - Simple health status API (JSON endpoints)
 * - Integration hooks for standalone monitoring library
 * - Lightweight metrics collection for five nines reliability
 * - No router/HTTP server dependencies (returns data structures)
 */
class HealthMonitor
{
    private LoggerInterface $logger;
    private FFIManagerInterface $ffi;
    private bool $rustAvailable = false;
    private array $config = [];

    private array $healthChecks = [];
    private array $componentMonitors = [];
    private array $healthHistory = [];
    private array $stats = [];
    private bool $monitoringActive = false;

    // Integration hooks for standalone monitoring library
    private ?object $externalMonitor = null;
    private array $integrationCallbacks = [];

    public function __construct(
        LoggerInterface $logger,
        ?FFIManagerInterface $ffi = null,
        array $config = []
    ) {
        $this->logger = $logger;
        $this->ffi = $ffi ?? new class implements FFIManagerInterface {
            public function load(string $library): ?\FFI { return null; }
            public function isAvailable(): bool { return false; }
            public function getLoadedLibraries(): array { return []; }
            public function getStats(): array { return []; }
            public function call(string $library, string $function, array $args = [], ?callable $fallback = null): mixed { return null; }
            public function registerLibrary(string $name, array $config): void {}
            public function isLibraryLoaded(string $library): bool { return false; }
        };

        $this->config = array_merge([
            'enable_rust_ffi' => true,
            'check_interval' => 30,
            'history_retention' => 1440, // 24 hours at 1-minute intervals
            'health_threshold' => 99.999, // Five nines reliability
            'enable_system_monitoring' => true,
            'enable_component_monitoring' => true,
            'enable_integration_hooks' => true,
            'enable_metrics_export' => true,
            'failure_detection_threshold' => 3,
            'recovery_confirmation_threshold' => 5
        ], $config);

        $this->initializeStats();
        $this->detectRustCapabilities();
        $this->initializeRustHealthMonitor();

        $this->logger->info('HealthMonitor initialized', [
            'rust_available' => $this->rustAvailable,
            'system_monitoring' => $this->config['enable_system_monitoring'],
            'component_monitoring' => $this->config['enable_component_monitoring']
        ]);
    }

    public function registerComponentMonitor(string $componentName, ComponentHealthChecker $monitor): void
    {
        $this->componentMonitors[$componentName] = $monitor;
        $this->logger->debug("Component monitor registered: {$componentName}");
    }

    public function registerCircuitBreakerMonitor(string $name, CircuitBreaker $circuitBreaker): void
    {
        $this->registerComponentMonitor($name, new CircuitBreakerHealthChecker($circuitBreaker));
    }

    public function registerBulkheadMonitor(string $name, BulkheadIsolation $bulkhead): void
    {
        $this->registerComponentMonitor($name, new BulkheadHealthChecker($bulkhead));
    }

    public function registerSelfHealingMonitor(string $name, SelfHealingManager $selfHealing): void
    {
        $this->registerComponentMonitor($name, new SelfHealingHealthChecker($selfHealing));
    }

    public function integrateWithExternalMonitor(object $monitor, array $callbacks = []): void
    {
        if (!$this->config['enable_integration_hooks']) {
            return;
        }

        $this->externalMonitor = $monitor;
        $this->integrationCallbacks = array_merge([
            'on_health_check' => null,
            'on_status_change' => null,
            'on_failure_detected' => null,
            'on_recovery_confirmed' => null
        ], $callbacks);

        $this->logger->info('External monitor integration configured', [
            'monitor_class' => get_class($monitor),
            'callbacks' => array_keys(array_filter($this->integrationCallbacks))
        ]);
    }

    public function performHealthCheck(): array
    {
        $checkId = uniqid('health_check_');
        $startTime = microtime(true);
        $this->stats['total_health_checks']++;

        $healthData = [
            'check_id' => $checkId,
            'timestamp' => time(),
            'framework_version' => $this->getFrameworkVersion(),
            'components' => [],
            'system' => [],
            'overall' => []
        ];

        // Component health checks
        if ($this->config['enable_component_monitoring']) {
            $healthData['components'] = $this->checkComponentHealth();
        }

        // System health checks  
        if ($this->config['enable_system_monitoring']) {
            $healthData['system'] = $this->checkSystemHealth();
        }

        // Calculate overall health
        $healthData['overall'] = $this->calculateOverallHealth(
            $healthData['components'],
            $healthData['system']
        );

        // Record in history
        $this->recordHealthHistory($healthData);

        // Integration hooks
        $this->triggerIntegrationCallback('on_health_check', $healthData);

        // Performance tracking
        $duration = microtime(true) - $startTime;
        $this->stats['total_check_time'] += $duration;
        $this->stats['avg_check_time'] = $this->stats['total_check_time'] / $this->stats['total_health_checks'];

        $this->logger->debug('Health check completed', [
            'check_id' => $checkId,
            'duration_ms' => round($duration * 1000, 2),
            'overall_status' => $healthData['overall']['status']
        ]);

        return $healthData;
    }

    public function getHealthStatus(): array
    {
        // Lightweight status check without full health check
        $latestHealth = end($this->healthHistory) ?: $this->performHealthCheck();
        
        return [
            'status' => $latestHealth['overall']['status'] ?? 'unknown',
            'health_percentage' => $latestHealth['overall']['health_percentage'] ?? 0,
            'five_nines_compliance' => $latestHealth['overall']['five_nines_compliance'] ?? false,
            'last_check' => $latestHealth['timestamp'] ?? null,
            'component_count' => count($this->componentMonitors),
            'monitoring_active' => $this->monitoringActive
        ];
    }

    public function getHealthMetrics(): array
    {
        // Metrics suitable for external monitoring systems
        $metrics = [
            'highper_framework_health_percentage' => $this->getCurrentHealthPercentage(),
            'highper_framework_component_count' => count($this->componentMonitors),
            'highper_framework_five_nines_compliance' => $this->isFiveNinesCompliant() ? 1 : 0,
            'highper_framework_health_checks_total' => $this->stats['total_health_checks'],
            'highper_framework_health_check_duration_avg' => $this->stats['avg_check_time'] * 1000, // ms
            'highper_framework_failures_detected_total' => $this->stats['failures_detected'],
            'highper_framework_recoveries_confirmed_total' => $this->stats['recoveries_confirmed']
        ];

        // Add component-specific metrics
        foreach ($this->componentMonitors as $name => $monitor) {
            $componentMetrics = $monitor->getMetrics();
            foreach ($componentMetrics as $key => $value) {
                $metrics["highper_framework_component_{$name}_{$key}"] = $value;
            }
        }

        return $metrics;
    }

    public function getHealthHistory(int $limit = 100): array
    {
        return array_slice($this->healthHistory, -$limit);
    }

    public function startMonitoring(): void
    {
        $this->monitoringActive = true;
        $this->stats['monitoring_start_time'] = time();
        
        $this->logger->info('Health monitoring started');
        
        // In a real implementation, this would start background monitoring
        // For now, we track that monitoring is active
    }

    public function stopMonitoring(): void
    {
        $this->monitoringActive = false;
        $this->logger->info('Health monitoring stopped');
    }

    private function checkComponentHealth(): array
    {
        $componentHealth = [];
        
        foreach ($this->componentMonitors as $name => $monitor) {
            try {
                $startTime = microtime(true);
                $health = $monitor->checkHealth();
                $duration = microtime(true) - $startTime;
                
                $componentHealth[$name] = array_merge($health, [
                    'check_duration_ms' => round($duration * 1000, 2),
                    'last_checked' => time()
                ]);

                // Detect failures and recoveries
                $this->detectStatusChanges($name, $health);

            } catch (\Throwable $e) {
                $componentHealth[$name] = [
                    'status' => 'error',
                    'healthy' => false,
                    'error' => $e->getMessage(),
                    'last_checked' => time()
                ];
                
                $this->stats['component_check_errors']++;
                $this->logger->error("Component health check failed: {$name}", [
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $componentHealth;
    }

    private function checkSystemHealth(): array
    {
        $systemHealth = [];
        
        // Memory usage
        $memoryUsage = memory_get_usage(true);
        $memoryPeak = memory_get_peak_usage(true);
        $memoryLimit = $this->getMemoryLimit();
        $memoryPercentage = $memoryLimit > 0 ? ($memoryUsage / $memoryLimit) * 100 : 0;
        
        $systemHealth['memory'] = [
            'status' => $memoryPercentage < 80 ? 'healthy' : ($memoryPercentage < 95 ? 'warning' : 'critical'),
            'usage_bytes' => $memoryUsage,
            'usage_mb' => round($memoryUsage / 1024 / 1024, 2),
            'peak_mb' => round($memoryPeak / 1024 / 1024, 2),
            'percentage' => round($memoryPercentage, 2),
            'limit_mb' => round($memoryLimit / 1024 / 1024, 2)
        ];

        // Load average (Unix systems)
        if (function_exists('sys_getloadavg')) {
            $loadAvg = sys_getloadavg();
            $systemHealth['load_average'] = [
                'status' => $loadAvg[0] < 2.0 ? 'healthy' : ($loadAvg[0] < 5.0 ? 'warning' : 'critical'),
                '1min' => round($loadAvg[0], 2),
                '5min' => round($loadAvg[1], 2),
                '15min' => round($loadAvg[2], 2)
            ];
        }

        // Disk space (current directory)
        $diskFree = disk_free_space('.');
        $diskTotal = disk_total_space('.');
        if ($diskFree !== false && $diskTotal !== false) {
            $diskPercentage = (($diskTotal - $diskFree) / $diskTotal) * 100;
            $systemHealth['disk'] = [
                'status' => $diskPercentage < 85 ? 'healthy' : ($diskPercentage < 95 ? 'warning' : 'critical'),
                'free_gb' => round($diskFree / 1024 / 1024 / 1024, 2),
                'total_gb' => round($diskTotal / 1024 / 1024 / 1024, 2),
                'used_percentage' => round($diskPercentage, 2)
            ];
        }

        // PHP version and extensions
        $systemHealth['php'] = [
            'status' => version_compare(PHP_VERSION, '8.2.0', '>=') ? 'healthy' : 'warning',
            'version' => PHP_VERSION,
            'uv_extension' => extension_loaded('uv'),
            'ffi_extension' => extension_loaded('ffi'),
            'opcache_enabled' => function_exists('opcache_get_status') && opcache_get_status() !== false
        ];

        return $systemHealth;
    }

    private function calculateOverallHealth(array $componentHealth, array $systemHealth): array
    {
        $totalComponents = count($componentHealth) + count($systemHealth);
        if ($totalComponents === 0) {
            return [
                'status' => 'unknown',
                'health_percentage' => 0,
                'five_nines_compliance' => false,
                'healthy_components' => 0,
                'total_components' => 0
            ];
        }

        $healthyCount = 0;
        
        // Count healthy components
        foreach ($componentHealth as $health) {
            if (($health['healthy'] ?? false) && ($health['status'] ?? '') === 'healthy') {
                $healthyCount++;
            }
        }
        
        // Count healthy system components
        foreach ($systemHealth as $health) {
            if (($health['status'] ?? '') === 'healthy') {
                $healthyCount++;
            }
        }

        $healthPercentage = ($healthyCount / $totalComponents) * 100;
        $fiveNinesCompliant = $healthPercentage >= $this->config['health_threshold'];
        
        return [
            'status' => $this->determineOverallStatus($healthPercentage),
            'health_percentage' => round($healthPercentage, 3),
            'five_nines_compliance' => $fiveNinesCompliant,
            'healthy_components' => $healthyCount,
            'total_components' => $totalComponents,
            'uptime_score' => $this->calculateUptimeScore()
        ];
    }

    private function detectStatusChanges(string $componentName, array $currentHealth): void
    {
        static $previousStates = [];
        
        $currentStatus = $currentHealth['status'] ?? 'unknown';
        $previousStatus = $previousStates[$componentName] ?? null;
        
        if ($previousStatus !== null && $previousStatus !== $currentStatus) {
            $this->triggerIntegrationCallback('on_status_change', [
                'component' => $componentName,
                'previous_status' => $previousStatus,
                'current_status' => $currentStatus,
                'timestamp' => time()
            ]);

            if ($currentStatus === 'error' || $currentStatus === 'critical') {
                $this->stats['failures_detected']++;
                $this->triggerIntegrationCallback('on_failure_detected', [
                    'component' => $componentName,
                    'status' => $currentStatus,
                    'health_data' => $currentHealth
                ]);
            } elseif ($currentStatus === 'healthy' && in_array($previousStatus, ['error', 'critical'])) {
                $this->stats['recoveries_confirmed']++;
                $this->triggerIntegrationCallback('on_recovery_confirmed', [
                    'component' => $componentName,
                    'previous_status' => $previousStatus,
                    'health_data' => $currentHealth
                ]);
            }
        }
        
        $previousStates[$componentName] = $currentStatus;
    }

    private function recordHealthHistory(array $healthData): void
    {
        $this->healthHistory[] = $healthData;
        
        // Maintain history limit
        $maxHistory = $this->config['history_retention'];
        if (count($this->healthHistory) > $maxHistory) {
            $this->healthHistory = array_slice($this->healthHistory, -$maxHistory);
        }
    }

    private function triggerIntegrationCallback(string $event, array $data): void
    {
        if (!$this->config['enable_integration_hooks'] || 
            !isset($this->integrationCallbacks[$event]) ||
            $this->integrationCallbacks[$event] === null) {
            return;
        }

        try {
            $callback = $this->integrationCallbacks[$event];
            $callback($data, $this->externalMonitor);
        } catch (\Throwable $e) {
            $this->logger->error("Integration callback failed: {$event}", [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function getCurrentHealthPercentage(): float
    {
        $latestHealth = end($this->healthHistory);
        return $latestHealth['overall']['health_percentage'] ?? 0.0;
    }

    private function isFiveNinesCompliant(): bool
    {
        $latestHealth = end($this->healthHistory);
        return $latestHealth['overall']['five_nines_compliance'] ?? false;
    }

    private function calculateUptimeScore(): float
    {
        if (empty($this->healthHistory)) {
            return 100.0;
        }

        $totalChecks = count($this->healthHistory);
        $healthyChecks = 0;
        
        foreach ($this->healthHistory as $check) {
            if (($check['overall']['health_percentage'] ?? 0) >= $this->config['health_threshold']) {
                $healthyChecks++;
            }
        }
        
        return round(($healthyChecks / $totalChecks) * 100, 3);
    }

    private function determineOverallStatus(float $healthPercentage): string
    {
        if ($healthPercentage >= 99.999) return 'excellent';
        if ($healthPercentage >= 99.9) return 'good';
        if ($healthPercentage >= 95) return 'degraded';
        if ($healthPercentage >= 50) return 'poor';
        return 'critical';
    }

    private function getMemoryLimit(): int
    {
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit === '-1') {
            return 0; // No limit
        }
        
        return $this->parseMemoryValue($memoryLimit);
    }

    private function parseMemoryValue(string $value): int
    {
        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $number = (int) substr($value, 0, -1);
        
        switch ($unit) {
            case 'g': return $number * 1024 * 1024 * 1024;
            case 'm': return $number * 1024 * 1024;
            case 'k': return $number * 1024;
            default: return (int) $value;
        }
    }

    private function getFrameworkVersion(): string
    {
        return '1.0.0'; // In real implementation, get from package info
    }

    private function detectRustCapabilities(): void
    {
        $this->rustAvailable = $this->ffi->isAvailable() && $this->config['enable_rust_ffi'];

        if ($this->rustAvailable) {
            $this->logger->debug('Rust FFI health monitoring capabilities detected');
        }
    }

    private function initializeRustHealthMonitor(): void
    {
        if (!$this->rustAvailable) {
            return;
        }

        // Register Rust health monitoring library
        $this->ffi->registerLibrary('health_monitor', [
            'header' => __DIR__ . '/../../rust/health_monitor/health_monitor.h',
            'lib' => __DIR__ . '/../../rust/health_monitor/target/release/libhealth_monitor.so'
        ]);
    }

    private function initializeStats(): void
    {
        $this->stats = [
            'total_health_checks' => 0,
            'total_check_time' => 0,
            'avg_check_time' => 0,
            'component_check_errors' => 0,
            'failures_detected' => 0,
            'recoveries_confirmed' => 0,
            'monitoring_start_time' => 0
        ];
    }

    public function getStats(): array
    {
        $uptime = $this->stats['monitoring_start_time'] > 0 
            ? time() - $this->stats['monitoring_start_time'] 
            : 0;

        return array_merge($this->stats, [
            'monitoring_uptime_seconds' => $uptime,
            'current_health_percentage' => $this->getCurrentHealthPercentage(),
            'five_nines_compliance' => $this->isFiveNinesCompliant(),
            'uptime_score' => $this->calculateUptimeScore(),
            'registered_components' => count($this->componentMonitors),
            'health_history_count' => count($this->healthHistory),
            'rust_available' => $this->rustAvailable,
            'external_integration_active' => $this->externalMonitor !== null
        ]);
    }

    public function isMonitoringActive(): bool
    {
        return $this->monitoringActive;
    }
}

// Component health checker interface
interface ComponentHealthChecker
{
    public function checkHealth(): array;
    public function getMetrics(): array;
}

// Implementation for Circuit Breaker monitoring
class CircuitBreakerHealthChecker implements ComponentHealthChecker
{
    private CircuitBreaker $circuitBreaker;

    public function __construct(CircuitBreaker $circuitBreaker)
    {
        $this->circuitBreaker = $circuitBreaker;
    }

    public function checkHealth(): array
    {
        $stats = $this->circuitBreaker->getStats();
        $healthMetrics = $this->circuitBreaker->getHealthMetrics();
        
        return [
            'status' => $this->circuitBreaker->isClosed() ? 'healthy' : 'degraded',
            'healthy' => $this->circuitBreaker->isClosed(),
            'state' => $this->circuitBreaker->getState(),
            'success_rate' => $healthMetrics['success_rate'],
            'availability' => $healthMetrics['availability'],
            'response_time_ms' => $healthMetrics['avg_response_time_ms']
        ];
    }

    public function getMetrics(): array
    {
        $stats = $this->circuitBreaker->getStats();
        return [
            'circuit_breaker_state' => $this->circuitBreaker->getState() === 'closed' ? 1 : 0,
            'circuit_breaker_total_calls' => $stats['total_calls'],
            'circuit_breaker_successful_calls' => $stats['successful_calls'],
            'circuit_breaker_failed_calls' => $stats['failed_calls'],
            'circuit_breaker_times_opened' => $stats['times_opened']
        ];
    }
}

// Implementation for Bulkhead monitoring
class BulkheadHealthChecker implements ComponentHealthChecker
{
    private BulkheadIsolation $bulkhead;

    public function __construct(BulkheadIsolation $bulkhead)
    {
        $this->bulkhead = $bulkhead;
    }

    public function checkHealth(): array
    {
        $healthStatus = $this->bulkhead->healthCheck();
        $overallHealth = $healthStatus['overall'];
        
        return [
            'status' => $overallHealth['status'],
            'healthy' => $overallHealth['five_nines_compliance'],
            'health_percentage' => $overallHealth['health_percentage'],
            'healthy_components' => $overallHealth['healthy_components'],
            'total_components' => $overallHealth['total_components']
        ];
    }

    public function getMetrics(): array
    {
        $stats = $this->bulkhead->getStats();
        return [
            'bulkhead_isolation_successes' => $stats['isolation_successes'],
            'bulkhead_isolation_failures' => $stats['isolation_failures'],
            'bulkhead_resource_contentions' => $stats['resource_contentions'],
            'bulkhead_total_bulkheads' => $stats['total_bulkheads']
        ];
    }
}

// Implementation for Self-Healing monitoring
class SelfHealingHealthChecker implements ComponentHealthChecker
{
    private SelfHealingManager $selfHealing;

    public function __construct(SelfHealingManager $selfHealing)
    {
        $this->selfHealing = $selfHealing;
    }

    public function checkHealth(): array
    {
        $stats = $this->selfHealing->getStats();
        
        return [
            'status' => $this->selfHealing->isRunning() ? 'healthy' : 'stopped',
            'healthy' => $this->selfHealing->isRunning() && $stats['five_nines_compliance'],
            'success_rate' => $stats['success_rate'],
            'healing_success_rate' => $stats['healing_success_rate'],
            'uptime_seconds' => $stats['uptime_seconds']
        ];
    }

    public function getMetrics(): array
    {
        $stats = $this->selfHealing->getStats();
        return [
            'self_healing_operations_attempted' => $stats['operations_attempted'],
            'self_healing_successful_operations' => $stats['successful_operations'],
            'self_healing_recovered_operations' => $stats['recovered_operations'],
            'self_healing_triggered' => $stats['healing_triggered'],
            'self_healing_successful_healings' => $stats['successful_healings']
        ];
    }
}