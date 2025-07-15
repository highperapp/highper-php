<?php

declare(strict_types=1);

/**
 * HighPer Framework Global Helper Functions
 * 
 * Universal helper functions available to all HighPer-based applications
 */

if (! function_exists('env')) {
    /**
     * Get environment variable with optional default value
     * 
     * Uses the generic EnvironmentManager for consistent environment handling
     * across all HighPer framework templates while maintaining backward compatibility
     */
    function env(string $key, mixed $default = null): mixed
    {
        try {
            if (class_exists('\HighPerApp\HighPer\Foundation\Environment\EnvironmentManager')) {
                return \HighPerApp\HighPer\Foundation\Environment\EnvironmentManager::get($key, $default);
            }
        } catch (\Throwable $e) {
            // Fall back to legacy implementation if EnvironmentManager fails
        }

        // Legacy fallback implementation
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? $default;

        if ($value === null) {
            return $default;
        }

        // Convert string boolean values
        if (is_string($value)) {
            $lower = strtolower($value);
            if ($lower === 'true') {
                return true;
            }
            if ($lower === 'false') {
                return false;
            }
            if ($lower === 'null') {
                return null;
            }
        }

        return $value;
    }
}

if (! function_exists('container')) {
    /**
     * Get the global container instance or resolve a binding
     * 
     * @param string|null $abstract The abstract type to resolve
     * @return mixed The container instance or resolved binding
     * @throws RuntimeException If container is not available
     */
    function container(?string $abstract = null): mixed
    {
        static $container = null;

        // Get container from global application if available
        if ($container === null) {
            if (function_exists('app')) {
                try {
                    $app = app();
                    if (method_exists($app, 'getContainer')) {
                        $container = $app->getContainer();
                    }
                } catch (\Throwable $e) {
                    throw new RuntimeException('Container not available: ' . $e->getMessage());
                }
            }
        }

        if ($container === null) {
            throw new RuntimeException('Container not initialized. Ensure application is bootstrapped.');
        }

        if ($abstract === null) {
            return $container;
        }

        return $container->get($abstract);
    }
}

if (! function_exists('framework_config')) {
    /**
     * Get framework configuration value
     * 
     * This is a framework-agnostic configuration helper that doesn't assume
     * specific file paths. Applications should implement their own config() helper
     * that references application-specific configuration files.
     */
    function framework_config(string $key, mixed $default = null): mixed
    {
        try {
            $container = container();
            if ($container->has('HighPerApp\\HighPer\\Contracts\\ConfigManagerInterface')) {
                $configManager = $container->get('HighPerApp\\HighPer\\Contracts\\ConfigManagerInterface');
                if (method_exists($configManager, 'get')) {
                    return $configManager->get($key, $default);
                }
            }
        } catch (\Throwable $e) {
            // Silently fall back to default if config manager not available
        }

        return $default;
    }
}

if (! function_exists('logger')) {
    /**
     * Get the framework logger instance
     * 
     * @param string|null $channel Optional channel name
     * @return mixed Logger instance
     * @throws RuntimeException If logger is not available
     */
    function logger(?string $channel = null): mixed
    {
        try {
            $container = container();
            if ($container->has('HighPerApp\\HighPer\\Contracts\\LoggerInterface')) {
                $logger = $container->get('HighPerApp\\HighPer\\Contracts\\LoggerInterface');
                
                if ($channel !== null && method_exists($logger, 'channel')) {
                    return $logger->channel($channel);
                }
                
                return $logger;
            }
        } catch (\Throwable $e) {
            throw new RuntimeException('Logger not available: ' . $e->getMessage());
        }

        throw new RuntimeException('Logger not initialized. Ensure application is bootstrapped.');
    }
}

if (! function_exists('router')) {
    /**
     * Get the framework router instance
     * 
     * @return mixed Router instance
     * @throws RuntimeException If router is not available
     */
    function router(): mixed
    {
        try {
            $container = container();
            if ($container->has('HighPerApp\\HighPer\\Contracts\\RouterInterface')) {
                return $container->get('HighPerApp\\HighPer\\Contracts\\RouterInterface');
            }
        } catch (\Throwable $e) {
            throw new RuntimeException('Router not available: ' . $e->getMessage());
        }

        throw new RuntimeException('Router not initialized. Ensure application is bootstrapped.');
    }
}

if (! function_exists('is_blueprint_environment')) {
    /**
     * Check if the application is running in Blueprint environment
     * 
     * @return bool True if running in Blueprint environment
     */
    function is_blueprint_environment(): bool
    {
        // Check for Blueprint-specific environment variables
        if (env('HIGHPER_BLUEPRINT', false) || env('BLUEPRINT_ENV', false)) {
            return true;
        }

        // Check if Blueprint application class is being used
        if (class_exists('HighPerApp\\Blueprint\\Application')) {
            return true;
        }

        // Check for Blueprint-specific directory structure
        $blueprintPaths = [
            getcwd() . '/app/Application.php',
            dirname(getcwd()) . '/blueprint/app/Application.php',
            '/home/infy/blueprint/app/Application.php'
        ];

        foreach ($blueprintPaths as $path) {
            if (file_exists($path)) {
                $content = file_get_contents($path);
                if ($content && strpos($content, 'namespace HighPerApp\\Blueprint') !== false) {
                    return true;
                }
            }
        }

        return false;
    }
}

if (! function_exists('blueprint_config_path')) {
    /**
     * Get the Blueprint configuration file path
     * 
     * @param string $file Configuration file name
     * @return string Full path to configuration file
     */
    function blueprint_config_path(string $file = 'app.php'): string
    {
        // Try different possible Blueprint paths
        $blueprintPaths = [
            getcwd() . '/config/' . $file,
            dirname(getcwd()) . '/blueprint/config/' . $file,
            '/home/infy/blueprint/config/' . $file
        ];

        foreach ($blueprintPaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        // Fallback to current directory
        return getcwd() . '/config/' . $file;
    }
}

if (! function_exists('blueprint_env')) {
    /**
     * Get environment variable with Blueprint-specific fallbacks
     * 
     * @param string $key Environment variable key
     * @param mixed $default Default value
     * @return mixed Environment variable value or default
     */
    function blueprint_env(string $key, mixed $default = null): mixed
    {
        // First try standard env() function
        $value = env($key, $default);
        
        // If running in Blueprint and value is default, try Blueprint-specific config
        if ($value === $default && is_blueprint_environment()) {
            try {
                $configPath = blueprint_config_path();
                if (file_exists($configPath)) {
                    $config = require $configPath;
                    
                    // Try to map environment keys to config paths
                    $keyMappings = [
                        'APP_NAME' => 'app.name',
                        'APP_ENV' => 'app.env',
                        'APP_DEBUG' => 'app.debug',
                        'APP_URL' => 'app.url',
                        'DB_HOST' => 'database.connections.mysql.host',
                        'DB_PORT' => 'database.connections.mysql.port',
                        'DB_DATABASE' => 'database.connections.mysql.database',
                        'DB_USERNAME' => 'database.connections.mysql.username',
                        'DB_PASSWORD' => 'database.connections.mysql.password',
                        'CACHE_DRIVER' => 'cache.default',
                        'SERVER_HOST' => 'server.host',
                        'SERVER_PORT' => 'server.port',
                        'REDIS_HOST' => 'database.redis.host',
                        'REDIS_PORT' => 'database.redis.port',
                    ];
                    
                    if (isset($keyMappings[$key])) {
                        $configKeyPath = $keyMappings[$key];
                        $keys = explode('.', $configKeyPath);
                        $configValue = $config;
                        
                        foreach ($keys as $segment) {
                            if (is_array($configValue) && array_key_exists($segment, $configValue)) {
                                $configValue = $configValue[$segment];
                            } else {
                                $configValue = null;
                                break;
                            }
                        }
                        
                        if ($configValue !== null) {
                            return $configValue;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Silently fall back to default value
            }
        }
        
        return $value;
    }
}