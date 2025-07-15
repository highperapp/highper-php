<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Foundation\Environment;

use HighPerApp\HighPer\Contracts\EnvironmentManagerInterface;

/**
 * Static Environment Manager
 * 
 * Provides static access to environment management functionality.
 * Template-agnostic design with customizable behavior per template.
 */
class EnvironmentManager
{
    private static ?EnvironmentManagerInterface $handler = null;
    private static bool $initialized = false;
    
    public static function initialize(?callable $customLoader = null, array $configMapping = []): void
    {
        if (self::$initialized) {
            return;
        }
        
        self::$handler = new DefaultEnvironmentHandler($customLoader, $configMapping);
        self::$handler->load();
        self::$initialized = true;
    }
    
    public static function get(string $key, mixed $default = null): mixed
    {
        self::ensureInitialized();
        return self::$handler->get($key, $default);
    }
    
    public static function set(string $key, mixed $value): void
    {
        self::ensureInitialized();
        self::$handler->set($key, $value);
    }
    
    public static function has(string $key): bool
    {
        self::ensureInitialized();
        return self::$handler->has($key);
    }
    
    public static function validate(): array
    {
        self::ensureInitialized();
        return self::$handler->validateEnvironment();
    }
    
    public static function getConfigMapping(): array
    {
        self::ensureInitialized();
        return self::$handler->getConfigMapping();
    }
    
    public static function setConfigMapping(array $mapping): void
    {
        self::ensureInitialized();
        if (method_exists(self::$handler, 'setConfigMapping')) {
            self::$handler->setConfigMapping($mapping);
        }
    }
    
    public static function reset(): void
    {
        self::$handler = null;
        self::$initialized = false;
    }
    
    public static function setHandler(EnvironmentManagerInterface $handler): void
    {
        self::$handler = $handler;
        self::$initialized = true;
    }
    
    private static function ensureInitialized(): void
    {
        if (!self::$initialized) {
            self::initialize();
        }
    }
}