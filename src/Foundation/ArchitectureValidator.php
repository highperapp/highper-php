<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Foundation;

use HighPerApp\HighPer\Contracts\LoggerInterface;

/**
 * Architecture Configuration Validator
 * 
 * Validates and optimizes configuration for Hybrid Multi-Process + Async architecture
 */
class ArchitectureValidator
{
    private LoggerInterface $logger;
    private array $systemCapabilities;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->systemCapabilities = $this->detectSystemCapabilities();
    }

    public function validateConfiguration(array $config): array
    {
        $validated = $config;
        
        // Validate worker configuration
        $validated = $this->validateWorkerConfig($validated);
        
        // Validate event loop configuration
        $validated = $this->validateEventLoopConfig($validated);
        
        // Validate server configuration
        $validated = $this->validateServerConfig($validated);
        
        // Validate zero-downtime configuration
        $validated = $this->validateZeroDowntimeConfig($validated);
        
        // Apply C10M optimizations if enabled
        if ($validated['server']['c10m_enabled'] ?? false) {
            $validated = $this->applyC10MOptimizations($validated);
        }
        
        // Apply Rust FFI optimizations if enabled
        if ($validated['server']['rust_enabled'] ?? false) {
            $validated = $this->applyRustOptimizations($validated);
        }
        
        $this->logValidationResults($validated);
        
        return $validated;
    }

    private function validateWorkerConfig(array $config): array
    {
        $workers = $config['workers'] ?? [];
        
        // Validate worker count
        $workerCount = $workers['count'] ?? $this->systemCapabilities['cpu_cores'];
        $maxRecommended = $this->systemCapabilities['cpu_cores'] * 2;
        
        if ($workerCount > $maxRecommended) {
            $this->logger->warning('Worker count exceeds recommended maximum', [
                'requested' => $workerCount,
                'recommended_max' => $maxRecommended,
                'cpu_cores' => $this->systemCapabilities['cpu_cores']
            ]);
        }
        
        $config['workers']['count'] = min($workerCount, $maxRecommended);
        
        // Validate memory limit
        $memoryLimit = $workers['memory_limit'] ?? '256M';
        $config['workers']['memory_limit'] = $this->validateMemoryLimit($memoryLimit);
        
        // Set optimal connection limits
        $config['workers']['max_connections_per_worker'] = $workers['max_connections_per_worker'] ?? 2500;
        $config['workers']['restart_threshold'] = $workers['restart_threshold'] ?? 10000;
        
        return $config;
    }

    private function validateEventLoopConfig(array $config): array
    {
        $eventLoop = $config['event_loop'] ?? [];
        
        // Check if UV extension is available
        $uvAvailable = $this->systemCapabilities['uv_available'];
        if (($eventLoop['uv_enabled'] ?? false) && !$uvAvailable) {
            $this->logger->warning('UV extension requested but not available, falling back to RevoltPHP');
            $config['event_loop']['uv_enabled'] = false;
        }
        
        // Set optimal thresholds
        $config['event_loop']['thresholds'] = [
            'connections' => $eventLoop['thresholds']['connections'] ?? 1000,
            'timers' => $eventLoop['thresholds']['timers'] ?? 100,
            'file_ops' => $eventLoop['thresholds']['file_ops'] ?? 50
        ];
        
        $config['event_loop']['auto_switch'] = $eventLoop['auto_switch'] ?? true;
        
        return $config;
    }

    private function validateServerConfig(array $config): array
    {
        $server = $config['server'] ?? [];
        
        // Validate port configuration
        if ($server['mode'] === 'dedicated_ports') {
            $ports = $server['ports'] ?? [];
            $config['server']['ports'] = [
                'http' => $ports['http'] ?? 8080,
                'ws' => $ports['ws'] ?? 8081
            ];
        } else {
            $config['server']['port'] = $server['port'] ?? 8080;
        }
        
        // Validate protocols
        $protocols = $server['protocols'] ?? ['http'];
        $validProtocols = ['http', 'https', 'ws', 'wss', 'grpc'];
        $config['server']['protocols'] = array_intersect($protocols, $validProtocols);
        
        return $config;
    }

    private function validateZeroDowntimeConfig(array $config): array
    {
        $zeroDowntime = $config['zero_downtime'] ?? [];
        
        if ($zeroDowntime['enabled'] ?? false) {
            // Validate deployment strategy
            $strategy = $zeroDowntime['deployment_strategy'] ?? 'blue_green';
            $validStrategies = ['blue_green', 'rolling'];
            
            if (!in_array($strategy, $validStrategies)) {
                $this->logger->warning('Invalid deployment strategy, using blue_green', [
                    'requested' => $strategy,
                    'valid_strategies' => $validStrategies
                ]);
                $strategy = 'blue_green';
            }
            
            $config['zero_downtime']['deployment_strategy'] = $strategy;
            $config['zero_downtime']['graceful_shutdown_timeout'] = $zeroDowntime['graceful_shutdown_timeout'] ?? 30;
        }
        
        return $config;
    }

    private function applyC10MOptimizations(array $config): array
    {
        $this->logger->info('Applying C10M optimizations');
        
        // Increase connection limits for C10M
        $config['workers']['max_connections_per_worker'] = 10000;
        $config['workers']['restart_threshold'] = 50000;
        
        // Optimize event loop thresholds
        $config['event_loop']['thresholds']['connections'] = 5000;
        $config['event_loop']['thresholds']['timers'] = 500;
        
        // Enable UV if available
        if ($this->systemCapabilities['uv_available']) {
            $config['event_loop']['uv_enabled'] = true;
        }
        
        // Memory optimization
        if (!isset($config['workers']['memory_limit']) || $config['workers']['memory_limit'] === '256M') {
            $config['workers']['memory_limit'] = '512M';
        }
        
        return $config;
    }

    private function applyRustOptimizations(array $config): array
    {
        if (!$this->systemCapabilities['ffi_available']) {
            $this->logger->warning('Rust FFI optimizations requested but FFI extension not available');
            $config['server']['rust_enabled'] = false;
            return $config;
        }
        
        $this->logger->info('Applying Rust FFI optimizations');
        
        // Enable UV event loop for better Rust integration
        if ($this->systemCapabilities['uv_available']) {
            $config['event_loop']['uv_enabled'] = true;
        }
        
        // Optimize for Rust integration
        $config['rust'] = [
            'enabled' => true,
            'libraries' => [
                'highper_http' => true,
                'highper_websocket' => true,
                'highper_crypto' => true
            ]
        ];
        
        return $config;
    }

    private function validateMemoryLimit(string $memoryLimit): string
    {
        // Convert memory limit to bytes for validation
        $bytes = $this->parseMemoryLimit($memoryLimit);
        $systemMemory = $this->systemCapabilities['total_memory'];
        
        // Ensure memory limit doesn't exceed 25% of system memory per worker
        $maxPerWorker = (int) ($systemMemory * 0.25);
        
        if ($bytes > $maxPerWorker) {
            $recommendedLimit = $this->formatBytes($maxPerWorker);
            $this->logger->warning('Memory limit too high, adjusting to recommended value', [
                'requested' => $memoryLimit,
                'recommended' => $recommendedLimit,
                'system_memory' => $this->formatBytes($systemMemory)
            ]);
            return $recommendedLimit;
        }
        
        return $memoryLimit;
    }

    private function parseMemoryLimit(string $limit): int
    {
        $unit = strtoupper(substr($limit, -1));
        $value = (int) substr($limit, 0, -1);
        
        switch ($unit) {
            case 'G':
                return $value * 1024 * 1024 * 1024;
            case 'M':
                return $value * 1024 * 1024;
            case 'K':
                return $value * 1024;
            default:
                return (int) $limit;
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024 * 1024), 1) . 'G';
        } elseif ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024)) . 'M';
        } else {
            return round($bytes / 1024) . 'K';
        }
    }

    private function detectSystemCapabilities(): array
    {
        return [
            'cpu_cores' => (int) shell_exec('nproc') ?: 4,
            'total_memory' => $this->getTotalMemory(),
            'uv_available' => extension_loaded('uv'),
            'ffi_available' => extension_loaded('ffi'),
            'pcntl_available' => function_exists('pcntl_fork'),
            'opcache_available' => extension_loaded('opcache'),
            'php_version' => PHP_VERSION
        ];
    }

    private function getTotalMemory(): int
    {
        // Try to get system memory in bytes
        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo && preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $matches)) {
            return (int) $matches[1] * 1024; // Convert KB to bytes
        }
        
        // Fallback: assume 4GB
        return 4 * 1024 * 1024 * 1024;
    }

    private function logValidationResults(array $config): void
    {
        $this->logger->info('Architecture configuration validated', [
            'workers' => $config['workers']['count'],
            'memory_per_worker' => $config['workers']['memory_limit'],
            'max_connections_per_worker' => $config['workers']['max_connections_per_worker'],
            'c10m_enabled' => $config['server']['c10m_enabled'] ?? false,
            'rust_enabled' => $config['server']['rust_enabled'] ?? false,
            'uv_enabled' => $config['event_loop']['uv_enabled'] ?? false,
            'zero_downtime_enabled' => $config['zero_downtime']['enabled'] ?? false,
            'server_mode' => $config['server']['mode'] ?? 'single_port_multiplexing'
        ]);
    }

    public function getSystemCapabilities(): array
    {
        return $this->systemCapabilities;
    }

    public function generateOptimalConfig(): array
    {
        $caps = $this->systemCapabilities;
        
        return [
            'workers' => [
                'count' => $caps['cpu_cores'],
                'memory_limit' => '512M',
                'max_connections_per_worker' => 2500,
                'restart_threshold' => 10000
            ],
            'event_loop' => [
                'uv_enabled' => $caps['uv_available'],
                'auto_switch' => true,
                'thresholds' => [
                    'connections' => 1000,
                    'timers' => 100,
                    'file_ops' => 50
                ]
            ],
            'server' => [
                'mode' => 'single_port_multiplexing',
                'c10m_enabled' => false,
                'rust_enabled' => $caps['ffi_available']
            ],
            'zero_downtime' => [
                'enabled' => true,
                'deployment_strategy' => 'blue_green',
                'graceful_shutdown_timeout' => 30
            ]
        ];
    }
}