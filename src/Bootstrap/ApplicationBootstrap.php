<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Bootstrap;

use HighPerApp\HighPer\Contracts\BootstrapInterface;
use HighPerApp\HighPer\Contracts\ApplicationInterface;

/**
 * Application Bootstrap
 * 
 * Bootstraps core application services before server initialization.
 * Sets up error handling, logging, and configuration.
 */
class ApplicationBootstrap implements BootstrapInterface
{
    public function bootstrap(ApplicationInterface $app): void
    {
        $logger = $app->getLogger();
        $config = $app->getConfig();

        $logger->info('Bootstrapping application core');

        // Setup error handling
        $this->setupErrorHandling($app);

        // Configure PHP settings for high performance
        $this->optimizePhpSettings($config);

        // Setup signal handlers for graceful shutdown
        $this->setupSignalHandlers($app);

        // Load additional configuration
        $this->loadAdditionalConfiguration($app);

        $logger->info('Application bootstrap completed');
    }

    public function getPriority(): int
    {
        return 10; // Very high priority - must run first
    }

    public function canBootstrap(ApplicationInterface $app): bool
    {
        return true; // Always can bootstrap
    }

    public function getDependencies(): array
    {
        return []; // No dependencies - runs first
    }

    public function getConfig(): array
    {
        return [
            'app' => [
                'name' => 'HighPer Framework',
                'version' => '2.0.0',
                'timezone' => 'UTC'
            ],
            'performance' => [
                'memory_limit' => '512M',
                'max_execution_time' => 0,
                'opcache_enabled' => true
            ]
        ];
    }

    public function shutdown(): void
    {
        // Cleanup if needed
    }

    private function setupErrorHandling(ApplicationInterface $app): void
    {
        $logger = $app->getLogger();
        $config = $app->getConfig();

        // Setup error handler
        set_error_handler(function($severity, $message, $file, $line) use ($logger) {
            $logger->error('PHP Error', [
                'severity' => $severity,
                'message' => $message,
                'file' => $file,
                'line' => $line
            ]);
            
            return false; // Let PHP handle it too
        });

        // Setup exception handler
        set_exception_handler(function(\Throwable $exception) use ($logger) {
            $logger->critical('Uncaught Exception', [
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString()
            ]);
        });

        // Setup fatal error handler
        register_shutdown_function(function() use ($logger) {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
                $logger->critical('Fatal Error', [
                    'message' => $error['message'],
                    'file' => $error['file'],
                    'line' => $error['line']
                ]);
            }
        });

        $logger->debug('Error handling configured');
    }

    private function optimizePhpSettings(object $config): void
    {
        // Memory optimization
        $memoryLimit = $config->get('performance.memory_limit', '512M');
        ini_set('memory_limit', $memoryLimit);

        // Execution time for long-running server
        $maxExecutionTime = $config->get('performance.max_execution_time', 0);
        ini_set('max_execution_time', (string) $maxExecutionTime);

        // Timezone
        $timezone = $config->get('app.timezone', 'UTC');
        date_default_timezone_set($timezone);

        // Disable output buffering for async operations
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // Optimize garbage collection for long-running processes
        gc_enable();
        
        // Set realpath cache for better performance
        ini_set('realpath_cache_size', '4096K');
        ini_set('realpath_cache_ttl', '600');
    }

    private function setupSignalHandlers(ApplicationInterface $app): void
    {
        if (!extension_loaded('pcntl')) {
            return; // Skip if pcntl not available
        }

        $logger = $app->getLogger();

        // Graceful shutdown on SIGTERM/SIGINT
        pcntl_signal(SIGTERM, function($signal) use ($app, $logger) {
            $logger->info('Received SIGTERM, shutting down gracefully');
            $app->shutdown();
            exit(0);
        });

        pcntl_signal(SIGINT, function($signal) use ($app, $logger) {
            $logger->info('Received SIGINT, shutting down gracefully');
            $app->shutdown();
            exit(0);
        });

        // Reload configuration on SIGHUP
        pcntl_signal(SIGHUP, function($signal) use ($app, $logger) {
            $logger->info('Received SIGHUP, reloading configuration');
            $app->getConfig()->loadEnvironment();
        });

        $logger->debug('Signal handlers configured');
    }

    private function loadAdditionalConfiguration(ApplicationInterface $app): void
    {
        $config = $app->getConfig();
        $logger = $app->getLogger();

        // Load configuration files based on environment
        $environment = $config->getEnvironment();
        $configFiles = [
            "config/app.php",
            "config/{$environment}.php",
            "config/server.php",
            "config/packages.php"
        ];

        foreach ($configFiles as $file) {
            if (file_exists($file)) {
                try {
                    $config->loadFromFile($file);
                    $logger->debug("Loaded configuration file: {$file}");
                } catch (\Throwable $e) {
                    $logger->warning("Failed to load configuration file: {$file}", [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }
}