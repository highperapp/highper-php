<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Foundation;

use HighPerApp\HighPer\Contracts\ConfigManagerInterface;
use Dotenv\Dotenv;

/**
 * High-Performance Configuration Manager
 * 
 * Optimized for larger concurrency scenarios with minimal overhead.
 * Supports nested configuration, environment variables, and fast lookups.
 */
class ConfigManager implements ConfigManagerInterface
{
    private array $config = [];
    private array $cache = [];
    private string $environment = 'development';
    private bool $debug = true;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->detectEnvironment();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        // Use cache for faster lookups
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $value = $this->getNestedValue($key, $default);
        $this->cache[$key] = $value;
        
        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $this->setNestedValue($key, $value);
        $this->cache[$key] = $value;
    }

    public function has(string $key): bool
    {
        return $this->getNestedValue($key, '__HIGHPER_NOT_FOUND__') !== '__HIGHPER_NOT_FOUND__';
    }

    public function load(array $config): void
    {
        $this->config = array_merge_recursive($this->config, $config);
        $this->clearCache();
    }

    public function loadFromFile(string $path): void
    {
        if (!file_exists($path)) {
            throw new \InvalidArgumentException("Configuration file not found: {$path}");
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        
        switch ($extension) {
            case 'php':
                $config = require $path;
                break;
            case 'json':
                $config = json_decode(file_get_contents($path), true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \InvalidArgumentException("Invalid JSON in configuration file: {$path}");
                }
                break;
            default:
                throw new \InvalidArgumentException("Unsupported configuration file format: {$extension}");
        }

        if (!is_array($config)) {
            throw new \InvalidArgumentException("Configuration file must return an array: {$path}");
        }

        $this->load($config);
    }

    public function loadEnvironment(): void
    {
        // Load .env file if it exists
        $envFile = getcwd() . '/.env';
        if (file_exists($envFile)) {
            $dotenv = Dotenv::createImmutable(dirname($envFile));
            $dotenv->safeLoad();
        }

        // Load environment-specific configuration
        $this->loadEnvironmentSpecificConfig();
        
        // Override with environment variables
        $this->loadEnvironmentVariables();
    }

    public function all(): array
    {
        return $this->config;
    }

    public function getNamespace(string $namespace): array
    {
        return $this->get($namespace, []);
    }

    public function remove(string $key): void
    {
        $this->removeNestedValue($key);
        unset($this->cache[$key]);
    }

    public function clear(): void
    {
        $this->config = [];
        $this->clearCache();
    }

    public function getEnvironment(): string
    {
        return $this->environment;
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    /**
     * Set environment name
     */
    public function setEnvironment(string $environment): void
    {
        $this->environment = $environment;
        $this->debug = in_array($environment, ['development', 'dev', 'testing', 'test']);
    }

    /**
     * Clear configuration cache
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }

    /**
     * Get configuration statistics
     */
    public function getStats(): array
    {
        return [
            'config_keys' => $this->countNestedKeys($this->config),
            'cache_size' => count($this->cache),
            'environment' => $this->environment,
            'debug' => $this->debug,
            'memory_usage' => strlen(serialize($this->config))
        ];
    }

    private function getNestedValue(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $nestedKey) {
            if (!is_array($value) || !array_key_exists($nestedKey, $value)) {
                return $default;
            }
            $value = $value[$nestedKey];
        }

        return $value;
    }

    private function setNestedValue(string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $config = &$this->config;

        foreach ($keys as $i => $nestedKey) {
            if ($i === count($keys) - 1) {
                $config[$nestedKey] = $value;
            } else {
                if (!isset($config[$nestedKey]) || !is_array($config[$nestedKey])) {
                    $config[$nestedKey] = [];
                }
                $config = &$config[$nestedKey];
            }
        }
    }

    private function removeNestedValue(string $key): void
    {
        $keys = explode('.', $key);
        $config = &$this->config;

        foreach ($keys as $i => $nestedKey) {
            if ($i === count($keys) - 1) {
                unset($config[$nestedKey]);
            } else {
                if (!isset($config[$nestedKey]) || !is_array($config[$nestedKey])) {
                    return;
                }
                $config = &$config[$nestedKey];
            }
        }
    }

    private function detectEnvironment(): void
    {
        $env = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'development';
        $this->setEnvironment($env);
    }

    private function loadEnvironmentSpecificConfig(): void
    {
        $configFiles = [
            "config/{$this->environment}.php",
            "config/app.{$this->environment}.php"
        ];

        foreach ($configFiles as $file) {
            if (file_exists($file)) {
                $this->loadFromFile($file);
            }
        }
    }

    private function loadEnvironmentVariables(): void
    {
        // Map common environment variables to configuration
        $envMappings = [
            'APP_DEBUG' => 'debug',
            'APP_URL' => 'app.url',
            'APP_NAME' => 'app.name',
            'APP_KEY' => 'app.key',
            'LOG_LEVEL' => 'logging.level',
            'LOG_CHANNEL' => 'logging.default',
            'CACHE_DRIVER' => 'cache.default',
            'SESSION_DRIVER' => 'session.driver',
            'QUEUE_CONNECTION' => 'queue.default',
            'DB_CONNECTION' => 'database.default',
            'DB_HOST' => 'database.connections.mysql.host',
            'DB_PORT' => 'database.connections.mysql.port',
            'DB_DATABASE' => 'database.connections.mysql.database',
            'DB_USERNAME' => 'database.connections.mysql.username',
            'DB_PASSWORD' => 'database.connections.mysql.password',
            'REDIS_HOST' => 'database.redis.default.host',
            'REDIS_PORT' => 'database.redis.default.port',
            'REDIS_PASSWORD' => 'database.redis.default.password'
        ];

        foreach ($envMappings as $envKey => $configKey) {
            $value = $_ENV[$envKey] ?? $_SERVER[$envKey] ?? null;
            if ($value !== null) {
                // Convert string values to appropriate types
                $value = $this->convertEnvironmentValue($value);
                $this->set($configKey, $value);
            }
        }

        // Update debug setting
        $debug = $_ENV['APP_DEBUG'] ?? $_SERVER['APP_DEBUG'] ?? null;
        if ($debug !== null) {
            $this->debug = in_array(strtolower($debug), ['true', '1', 'yes', 'on']);
        }
    }

    private function convertEnvironmentValue(string $value): mixed
    {
        $lowercaseValue = strtolower($value);
        
        // Boolean values
        if (in_array($lowercaseValue, ['true', 'false'])) {
            return $lowercaseValue === 'true';
        }
        
        // Null values
        if (in_array($lowercaseValue, ['null', 'nil', ''])) {
            return null;
        }
        
        // Numeric values
        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }
        
        return $value;
    }

    private function countNestedKeys(array $array): int
    {
        $count = 0;
        foreach ($array as $value) {
            $count++;
            if (is_array($value)) {
                $count += $this->countNestedKeys($value);
            }
        }
        return $count;
    }
}