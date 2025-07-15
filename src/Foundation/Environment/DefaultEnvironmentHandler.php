<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Foundation\Environment;

use HighPerApp\HighPer\Contracts\EnvironmentManagerInterface;

/**
 * Default Environment Manager Implementation
 * 
 * High-performance environment variable management with template-agnostic design.
 * Supports custom loaders and configuration mapping per template needs.
 */
class DefaultEnvironmentHandler implements EnvironmentManagerInterface
{
    private array $configMapping = [];
    private array $cache = [];
    private bool $cacheEnabled = true;
    private $customLoader = null;
    
    public function __construct(?callable $customLoader = null, array $configMapping = [])
    {
        $this->customLoader = $customLoader;
        $this->configMapping = $configMapping;
    }
    
    public function get(string $key, mixed $default = null): mixed
    {
        if ($this->cacheEnabled && isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        
        $value = $this->resolveValue($key, $default);
        $value = $this->transformValue($value);
        
        if ($this->cacheEnabled) {
            $this->cache[$key] = $value;
        }
        
        return $value;
    }
    
    public function set(string $key, mixed $value): void
    {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        
        if ($this->cacheEnabled) {
            $this->cache[$key] = $this->transformValue($value);
        }
    }
    
    public function has(string $key): bool
    {
        return isset($_ENV[$key]) || isset($_SERVER[$key]);
    }
    
    public function load(): void
    {
        if ($this->customLoader) {
            ($this->customLoader)($this);
            return;
        }
        
        $envFile = $this->findEnvFile();
        
        if ($envFile && file_exists($envFile)) {
            $this->loadFromFile($envFile);
        }
    }
    
    public function getConfigMapping(): array
    {
        return $this->configMapping;
    }
    
    public function setConfigMapping(array $mapping): void
    {
        $this->configMapping = $mapping;
    }
    
    public function validateEnvironment(): array
    {
        $errors = [];
        $required = $this->getRequiredVariables();
        
        foreach ($required as $variable) {
            if (!$this->has($variable)) {
                $errors[] = "Required environment variable '{$variable}' is not set";
            }
        }
        
        return $errors;
    }
    
    public function loadFromFile(string $path): void
    {
        $this->loadEnvFile($path);
    }
    
    public function reset(): void
    {
        $this->cache = [];
    }
    
    private function getRequiredVariables(): array
    {
        return [];
    }
    
    private function resolveValue(string $key, mixed $default): mixed
    {
        return $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default;
    }
    
    private function transformValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        
        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $this->parseNumericValue($value)
        };
    }
    
    private function parseNumericValue(string $value): string|int|float
    {
        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }
        
        return $value;
    }
    
    private function findEnvFile(): ?string
    {
        $candidates = [
            getcwd() . '/.env',
            __DIR__ . '/../../../../.env',
            __DIR__ . '/../../../.env',
        ];
        
        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }
        
        return null;
    }
    
    public function loadEnvFile(string $path): void
    {
        if (class_exists('\Dotenv\Dotenv')) {
            try {
                $dotenv = \Dotenv\Dotenv::createImmutable(dirname($path));
                $dotenv->safeLoad();
                return;
            } catch (\Throwable $e) {
                // Fall back to simple loader if Dotenv fails
            }
        }
        
        $this->loadSimpleEnvFile($path);
    }
    
    protected function loadSimpleEnvFile(string $path): void
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            if (str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = $this->parseEnvValue(trim($value));
            
            if (!isset($_ENV[$key])) {
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
    
    protected function parseEnvValue(string $value): string
    {
        $value = trim($value, '"\'');
        
        if (preg_match('/\$\{([^}]+)\}/', $value, $matches)) {
            $envVar = $matches[1];
            $replacement = $_ENV[$envVar] ?? $_SERVER[$envVar] ?? '';
            $value = str_replace($matches[0], $replacement, $value);
        }
        
        return $value;
    }
}