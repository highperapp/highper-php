<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Observability;

use HighPerApp\HighPer\Contracts\LoggerInterface;
use HighPerApp\HighPer\Contracts\ContainerInterface;
use HighPerApp\HighPer\Reliability\HealthMonitor;

/**
 * Observability Manager for HighPer Framework
 * 
 * Provides unified integration of three distinct observability concerns:
 * 1. Tracing Library (standalone) - Request flow tracking, OpenTelemetry
 * 2. Monitoring Library (standalone) - Metrics, dashboards, performance analytics
 * 3. Framework HealthMonitor - Component health, five nines reliability
 * 
 * Features:
 * - Unified configuration and initialization
 * - Cross-cutting observability concerns
 * - Automatic instrumentation for framework operations
 * - Integration hooks between observability layers
 * - Maintains separation of concerns while providing convenience
 */
class ObservabilityManager
{
    private ContainerInterface $container;
    private LoggerInterface $logger;
    private array $config = [];

    // Observability components (may be null if not available)
    private ?object $tracingManager = null;
    private ?object $monitoringManager = null;
    private ?HealthMonitor $healthMonitor = null;

    private array $integrationCallbacks = [];
    private array $stats = [];
    private bool $autoInstrumentationEnabled = true;

    public function __construct(
        ContainerInterface $container,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->container = $container;
        $this->logger = $logger;
        
        $this->config = array_merge([
            'tracing' => [
                'enabled' => true,
                'auto_detect' => true,
                'service_name' => 'highper-app',
                'sampling_ratio' => 1.0,
                'auto_instrumentation' => [
                    'http' => true,
                    'database' => true,
                    'cache' => true,
                    'queue' => true,
                    'reliability' => true
                ]
            ],
            'monitoring' => [
                'enabled' => true,
                'auto_detect' => true,
                'dashboard_enabled' => false, // Monitoring library handles this
                'metrics_export' => true,
                'performance_tracking' => true
            ],
            'health_monitoring' => [
                'enabled' => true,
                'check_interval' => 30,
                'integration_hooks' => true,
                'export_to_monitoring' => true,
                'export_to_tracing' => true
            ],
            'integration' => [
                'cross_correlation' => true,
                'unified_context' => true,
                'shared_metadata' => true,
                'automatic_alerting' => true
            ]
        ], $config);

        $this->initializeStats();
        $this->detectAndInitializeComponents();
        $this->setupIntegrationCallbacks();

        $this->logger->info('ObservabilityManager initialized', [
            'tracing_available' => $this->tracingManager !== null,
            'monitoring_available' => $this->monitoringManager !== null,
            'health_monitoring_available' => $this->healthMonitor !== null,
            'auto_instrumentation' => $this->autoInstrumentationEnabled
        ]);
    }

    public function startObservability(): void
    {
        // Start health monitoring
        if ($this->healthMonitor && $this->config['health_monitoring']['enabled']) {
            $this->healthMonitor->startMonitoring();
        }

        // Initialize monitoring if available
        if ($this->monitoringManager && $this->config['monitoring']['enabled']) {
            if (method_exists($this->monitoringManager, 'start')) {
                $this->monitoringManager->start();
            }
        }

        // Tracing is typically always-on, but we can configure sampling
        if ($this->tracingManager && $this->config['tracing']['enabled']) {
            // Tracing managers typically don't need explicit starting
            $this->logger->info('Tracing manager is active');
        }

        $this->stats['observability_start_time'] = time();
        $this->logger->info('Observability stack started');
    }

    public function stopObservability(): void
    {
        if ($this->healthMonitor) {
            $this->healthMonitor->stopMonitoring();
        }

        if ($this->monitoringManager && method_exists($this->monitoringManager, 'stop')) {
            $this->monitoringManager->stop();
        }

        $this->logger->info('Observability stack stopped');
    }

    // Unified operation tracking across all observability layers
    public function traceOperation(string $operationName, callable $operation, array $context = []): mixed
    {
        $correlationId = $this->generateCorrelationId();
        $startTime = microtime(true);
        
        // Start tracing span if available
        $span = null;
        if ($this->tracingManager && $this->config['tracing']['enabled']) {
            $span = $this->startTraceSpan($operationName, $correlationId, $context);
        }

        // Start monitoring if available
        $monitoringContext = null;
        if ($this->monitoringManager && $this->config['monitoring']['performance_tracking']) {
            $monitoringContext = $this->startMonitoringTimer($operationName, $correlationId);
        }

        try {
            // Execute operation
            $result = $operation($correlationId);
            
            $this->recordSuccessfulOperation($operationName, $startTime, $correlationId);
            
            return $result;

        } catch (\Throwable $e) {
            $this->recordFailedOperation($operationName, $startTime, $e, $correlationId);
            throw $e;
            
        } finally {
            // Cleanup tracing
            if ($span) {
                $this->finishTraceSpan($span, $correlationId);
            }
            
            // Cleanup monitoring
            if ($monitoringContext) {
                $this->finishMonitoringTimer($monitoringContext, $correlationId);
            }
        }
    }

    // Health check integration across all layers
    public function performComprehensiveHealthCheck(): array
    {
        $healthData = [
            'timestamp' => time(),
            'correlation_id' => $this->generateCorrelationId(),
            'framework_health' => [],
            'tracing_health' => [],
            'monitoring_health' => [],
            'integration_health' => []
        ];

        // Framework health monitoring
        if ($this->healthMonitor) {
            $healthData['framework_health'] = $this->healthMonitor->performHealthCheck();
        }

        // Tracing system health
        if ($this->tracingManager) {
            $healthData['tracing_health'] = $this->checkTracingHealth();
        }

        // Monitoring system health
        if ($this->monitoringManager) {
            $healthData['monitoring_health'] = $this->checkMonitoringHealth();
        }

        // Integration health
        $healthData['integration_health'] = $this->checkIntegrationHealth();

        // Calculate overall observability health
        $healthData['overall'] = $this->calculateOverallObservabilityHealth($healthData);

        return $healthData;
    }

    // Get unified metrics from all observability layers
    public function getUnifiedMetrics(): array
    {
        $metrics = [];

        // Framework health metrics
        if ($this->healthMonitor) {
            $healthMetrics = $this->healthMonitor->getHealthMetrics();
            foreach ($healthMetrics as $key => $value) {
                $metrics[$key] = $value;
            }
        }

        // Monitoring metrics (if available)
        if ($this->monitoringManager && method_exists($this->monitoringManager, 'getMetrics')) {
            $monitoringMetrics = $this->monitoringManager->getMetrics();
            foreach ($monitoringMetrics as $key => $value) {
                $metrics["monitoring_{$key}"] = $value;
            }
        }

        // Tracing metrics (if available)
        if ($this->tracingManager && method_exists($this->tracingManager, 'getMetrics')) {
            $tracingMetrics = $this->tracingManager->getMetrics();
            foreach ($tracingMetrics as $key => $value) {
                $metrics["tracing_{$key}"] = $value;
            }
        }

        // Integration metrics
        $metrics = array_merge($metrics, [
            'observability_operations_traced' => $this->stats['operations_traced'],
            'observability_operations_monitored' => $this->stats['operations_monitored'],
            'observability_health_checks_performed' => $this->stats['health_checks_performed'],
            'observability_integration_callbacks_triggered' => $this->stats['integration_callbacks_triggered']
        ]);

        return $metrics;
    }

    // Register reliability components for monitoring
    public function registerReliabilityComponent(string $name, object $component): void
    {
        if (!$this->healthMonitor) {
            return;
        }

        // Register with health monitor based on component type
        $componentClass = get_class($component);
        
        if (str_contains($componentClass, 'CircuitBreaker')) {
            $this->healthMonitor->registerCircuitBreakerMonitor($name, $component);
        } elseif (str_contains($componentClass, 'BulkheadIsolation')) {
            $this->healthMonitor->registerBulkheadMonitor($name, $component);
        } elseif (str_contains($componentClass, 'SelfHealingManager')) {
            $this->healthMonitor->registerSelfHealingMonitor($name, $component);
        } else {
            // Generic component monitor
            if (method_exists($component, 'checkHealth')) {
                $this->healthMonitor->registerComponentMonitor($name, new GenericComponentHealthChecker($component));
            }
        }

        $this->logger->debug("Reliability component registered for observability: {$name}");
    }

    // Integration with external monitoring systems
    public function integrateWithExternalMonitoring(?object $externalMonitor = null): void
    {
        if (!$this->config['health_monitoring']['integration_hooks']) {
            return;
        }

        // Try to detect monitoring library if not provided
        if ($externalMonitor === null) {
            $externalMonitor = $this->detectExternalMonitor();
        }

        if ($externalMonitor && $this->healthMonitor) {
            $this->healthMonitor->integrateWithExternalMonitor($externalMonitor, [
                'on_health_check' => function (array $healthData, object $monitor) {
                    $this->exportHealthToMonitoring($healthData, $monitor);
                },
                'on_failure_detected' => function (array $failureData, object $monitor) {
                    $this->exportFailureToMonitoring($failureData, $monitor);
                },
                'on_recovery_confirmed' => function (array $recoveryData, object $monitor) {
                    $this->exportRecoveryToMonitoring($recoveryData, $monitor);
                }
            ]);
        }
    }

    // Private methods
    private function detectAndInitializeComponents(): void
    {
        // Detect tracing library
        if ($this->config['tracing']['auto_detect']) {
            $this->tracingManager = $this->detectTracingManager();
        }

        // Detect monitoring library
        if ($this->config['monitoring']['auto_detect']) {
            $this->monitoringManager = $this->detectMonitoringManager();
        }

        // Initialize framework health monitor
        if ($this->config['health_monitoring']['enabled']) {
            $this->healthMonitor = new HealthMonitor(
                $this->logger,
                $this->container->has('ffi.manager') ? $this->container->get('ffi.manager') : null,
                $this->config['health_monitoring']
            );
        }
    }

    private function detectTracingManager(): ?object
    {
        // Try to detect tracing library classes
        $tracingClasses = [
            '\\HighPerApp\\HighPer\\Tracing\\Core\\EnhancedTracingManager',
            '\\HighPerApp\\HighPer\\Tracing\\TracingManager'
        ];

        foreach ($tracingClasses as $class) {
            if (class_exists($class)) {
                try {
                    return new $class($this->config['tracing']);
                } catch (\Throwable $e) {
                    $this->logger->warning("Failed to initialize tracing manager: {$class}", [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        $this->logger->info('Tracing library not detected, tracing features disabled');
        return null;
    }

    private function detectMonitoringManager(): ?object
    {
        // Try to detect monitoring library classes
        $monitoringClasses = [
            '\\HighPerApp\\HighPer\\Monitoring\\Core\\PerformanceMonitor',
            '\\HighPerApp\\HighPer\\Monitoring\\MonitoringManager'
        ];

        foreach ($monitoringClasses as $class) {
            if (class_exists($class)) {
                try {
                    return new $class($this->container, $this->config['monitoring']);
                } catch (\Throwable $e) {
                    $this->logger->warning("Failed to initialize monitoring manager: {$class}", [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        $this->logger->info('Monitoring library not detected, monitoring features disabled');
        return null;
    }

    private function detectExternalMonitor(): ?object
    {
        // Try to get monitoring manager from container
        if ($this->container->has('monitoring.manager')) {
            return $this->container->get('monitoring.manager');
        }

        return $this->monitoringManager;
    }

    private function setupIntegrationCallbacks(): void
    {
        if (!$this->config['integration']['cross_correlation']) {
            return;
        }

        $this->integrationCallbacks = [
            'on_trace_started' => function (string $operationName, string $correlationId) {
                $this->stats['operations_traced']++;
                
                // Export to monitoring if available
                if ($this->monitoringManager && method_exists($this->monitoringManager, 'recordMetric')) {
                    $this->monitoringManager->recordMetric('traces_started', 1, [
                        'operation' => $operationName,
                        'correlation_id' => $correlationId
                    ]);
                }
            },
            
            'on_monitoring_timer_started' => function (string $operationName, string $correlationId) {
                $this->stats['operations_monitored']++;
            },
            
            'on_health_check_performed' => function () {
                $this->stats['health_checks_performed']++;
            }
        ];
    }

    private function startTraceSpan(string $operationName, string $correlationId, array $context): ?object
    {
        if (!$this->tracingManager) {
            return null;
        }

        try {
            // Different tracing managers may have different APIs
            if (method_exists($this->tracingManager, 'createInternalSpan')) {
                return $this->tracingManager->createInternalSpan($operationName, [
                    'correlation_id' => $correlationId,
                    'context' => $context
                ]);
            } elseif (method_exists($this->tracingManager, 'startSpan')) {
                return $this->tracingManager->startSpan($operationName, $context);
            }

            return null;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to start trace span', [
                'operation' => $operationName,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function finishTraceSpan(?object $span, string $correlationId): void
    {
        if (!$span) {
            return;
        }

        try {
            if (method_exists($span, 'finish')) {
                $span->finish();
            } elseif (method_exists($span, 'end')) {
                $span->end();
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to finish trace span', [
                'correlation_id' => $correlationId,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function startMonitoringTimer(string $operationName, string $correlationId): ?array
    {
        if (!$this->monitoringManager || !method_exists($this->monitoringManager, 'startTimer')) {
            return null;
        }

        try {
            return [
                'timer_id' => $this->monitoringManager->startTimer($operationName),
                'operation' => $operationName,
                'correlation_id' => $correlationId,
                'start_time' => microtime(true)
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to start monitoring timer', [
                'operation' => $operationName,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function finishMonitoringTimer(?array $context, string $correlationId): void
    {
        if (!$context || !$this->monitoringManager) {
            return;
        }

        try {
            if (method_exists($this->monitoringManager, 'endTimer')) {
                $this->monitoringManager->endTimer($context['timer_id']);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to finish monitoring timer', [
                'correlation_id' => $correlationId,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function recordSuccessfulOperation(string $operationName, float $startTime, string $correlationId): void
    {
        $duration = microtime(true) - $startTime;
        $this->stats['successful_operations']++;
        $this->stats['total_operation_time'] += $duration;

        $this->triggerIntegrationCallback('on_operation_success', [
            'operation' => $operationName,
            'duration' => $duration,
            'correlation_id' => $correlationId
        ]);
    }

    private function recordFailedOperation(string $operationName, float $startTime, \Throwable $e, string $correlationId): void
    {
        $duration = microtime(true) - $startTime;
        $this->stats['failed_operations']++;
        $this->stats['total_operation_time'] += $duration;

        $this->triggerIntegrationCallback('on_operation_failure', [
            'operation' => $operationName,
            'duration' => $duration,
            'error' => $e->getMessage(),
            'correlation_id' => $correlationId
        ]);
    }

    private function checkTracingHealth(): array
    {
        if (!$this->tracingManager) {
            return ['status' => 'disabled', 'healthy' => false];
        }

        // Basic health check for tracing
        return [
            'status' => 'active',
            'healthy' => true,
            'manager_class' => get_class($this->tracingManager),
            'sampling_enabled' => $this->config['tracing']['sampling_ratio'] > 0
        ];
    }

    private function checkMonitoringHealth(): array
    {
        if (!$this->monitoringManager) {
            return ['status' => 'disabled', 'healthy' => false];
        }

        // Basic health check for monitoring
        return [
            'status' => 'active',
            'healthy' => true,
            'manager_class' => get_class($this->monitoringManager),
            'dashboard_enabled' => $this->config['monitoring']['dashboard_enabled']
        ];
    }

    private function checkIntegrationHealth(): array
    {
        $componentsActive = 0;
        $totalComponents = 3; // tracing, monitoring, health

        if ($this->tracingManager) $componentsActive++;
        if ($this->monitoringManager) $componentsActive++;
        if ($this->healthMonitor) $componentsActive++;

        return [
            'status' => $componentsActive > 0 ? 'active' : 'inactive',
            'healthy' => $componentsActive >= 1, // At least one component should be active
            'active_components' => $componentsActive,
            'total_components' => $totalComponents,
            'integration_percentage' => round(($componentsActive / $totalComponents) * 100, 1)
        ];
    }

    private function calculateOverallObservabilityHealth(array $healthData): array
    {
        $healthyComponents = 0;
        $totalComponents = 0;

        // Check each layer
        foreach (['framework_health', 'tracing_health', 'monitoring_health', 'integration_health'] as $layer) {
            if (!empty($healthData[$layer])) {
                $totalComponents++;
                if ($healthData[$layer]['healthy'] ?? false) {
                    $healthyComponents++;
                }
            }
        }

        $healthPercentage = $totalComponents > 0 ? ($healthyComponents / $totalComponents) * 100 : 0;

        return [
            'status' => $healthPercentage >= 75 ? 'healthy' : ($healthPercentage >= 50 ? 'degraded' : 'unhealthy'),
            'health_percentage' => round($healthPercentage, 2),
            'healthy_components' => $healthyComponents,
            'total_components' => $totalComponents,
            'observability_coverage' => $this->calculateObservabilityCoverage()
        ];
    }

    private function calculateObservabilityCoverage(): array
    {
        return [
            'tracing' => $this->tracingManager !== null,
            'monitoring' => $this->monitoringManager !== null,
            'health_monitoring' => $this->healthMonitor !== null,
            'coverage_percentage' => round((
                ($this->tracingManager !== null ? 1 : 0) +
                ($this->monitoringManager !== null ? 1 : 0) +
                ($this->healthMonitor !== null ? 1 : 0)
            ) / 3 * 100, 1)
        ];
    }

    private function exportHealthToMonitoring(array $healthData, object $monitor): void
    {
        // Export health data to monitoring system
        if (method_exists($monitor, 'recordHealthCheck')) {
            $monitor->recordHealthCheck($healthData);
        }
    }

    private function exportFailureToMonitoring(array $failureData, object $monitor): void
    {
        // Export failure data to monitoring system
        if (method_exists($monitor, 'recordFailure')) {
            $monitor->recordFailure($failureData);
        }
    }

    private function exportRecoveryToMonitoring(array $recoveryData, object $monitor): void
    {
        // Export recovery data to monitoring system
        if (method_exists($monitor, 'recordRecovery')) {
            $monitor->recordRecovery($recoveryData);
        }
    }

    private function triggerIntegrationCallback(string $event, array $data): void
    {
        if (isset($this->integrationCallbacks[$event])) {
            try {
                $this->integrationCallbacks[$event]($data);
                $this->stats['integration_callbacks_triggered']++;
            } catch (\Throwable $e) {
                $this->logger->error("Integration callback failed: {$event}", [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    private function generateCorrelationId(): string
    {
        return uniqid('obs_', true);
    }

    private function initializeStats(): void
    {
        $this->stats = [
            'operations_traced' => 0,
            'operations_monitored' => 0,
            'successful_operations' => 0,
            'failed_operations' => 0,
            'health_checks_performed' => 0,
            'integration_callbacks_triggered' => 0,
            'total_operation_time' => 0,
            'observability_start_time' => 0
        ];
    }

    public function getStats(): array
    {
        $uptime = $this->stats['observability_start_time'] > 0 
            ? time() - $this->stats['observability_start_time'] 
            : 0;

        $totalOperations = $this->stats['successful_operations'] + $this->stats['failed_operations'];
        $successRate = $totalOperations > 0 
            ? round($this->stats['successful_operations'] / $totalOperations * 100, 2)
            : 100;

        return array_merge($this->stats, [
            'uptime_seconds' => $uptime,
            'success_rate' => $successRate,
            'avg_operation_time' => $totalOperations > 0 
                ? round($this->stats['total_operation_time'] / $totalOperations * 1000, 2) // ms
                : 0,
            'tracing_available' => $this->tracingManager !== null,
            'monitoring_available' => $this->monitoringManager !== null,
            'health_monitoring_available' => $this->healthMonitor !== null,
            'auto_instrumentation_enabled' => $this->autoInstrumentationEnabled
        ]);
    }

    public function isObservabilityActive(): bool
    {
        return $this->tracingManager !== null || 
               $this->monitoringManager !== null || 
               $this->healthMonitor !== null;
    }
}

// Generic component health checker for unknown component types
class GenericComponentHealthChecker implements \HighPerApp\HighPer\Reliability\ComponentHealthChecker
{
    private object $component;

    public function __construct(object $component)
    {
        $this->component = $component;
    }

    public function checkHealth(): array
    {
        if (method_exists($this->component, 'checkHealth')) {
            return $this->component->checkHealth();
        }

        if (method_exists($this->component, 'isHealthy')) {
            $healthy = $this->component->isHealthy();
            return [
                'status' => $healthy ? 'healthy' : 'unhealthy',
                'healthy' => $healthy
            ];
        }

        return [
            'status' => 'unknown',
            'healthy' => true // Assume healthy if no health check method
        ];
    }

    public function getMetrics(): array
    {
        if (method_exists($this->component, 'getMetrics')) {
            return $this->component->getMetrics();
        }

        if (method_exists($this->component, 'getStats')) {
            return $this->component->getStats();
        }

        return [];
    }
}