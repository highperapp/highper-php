<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Tests\Integration\Environment;

use HighPerApp\HighPer\Foundation\Environment\EnvironmentManager;
use PHPUnit\Framework\TestCase;

/**
 * Template Integration Tests
 * 
 * Tests environment integration across different template configurations
 */
class TemplateIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        EnvironmentManager::reset();
        
        // Clear all test environment variables for clean testing
        $testVars = ['APP_ENV', 'APP_DEBUG', 'SERVER_HOST', 'SERVER_PORT', 'SERVER_WORKERS', 
                    'MEMORY_LIMIT', 'LOG_LEVEL', 'MONITORING_ENABLED', 'SECURITY_LEVEL'];
        
        foreach ($testVars as $var) {
            unset($_ENV[$var]);
            unset($_SERVER[$var]);
        }
    }
    
    protected function tearDown(): void
    {
        EnvironmentManager::reset();
        
        // Clean up environment variables
        unset($_ENV['APP_ENV']);
        unset($_ENV['APP_DEBUG']);
        unset($_ENV['SERVER_HOST']);
        unset($_ENV['SERVER_PORT']);
        unset($_ENV['SERVER_WORKERS']);
        unset($_ENV['MEMORY_LIMIT']);
        unset($_ENV['LOG_LEVEL']);
        unset($_ENV['MONITORING_ENABLED']);
        unset($_ENV['SECURITY_LEVEL']);
        
        parent::tearDown();
    }
    
    public function testBlueprintStyleConfiguration(): void
    {
        // Simulate Blueprint template initialization
        EnvironmentManager::initialize(
            customLoader: function ($handler) {
                // Blueprint defaults
                $defaults = [
                    'APP_ENV' => 'production',
                    'APP_DEBUG' => 'false',
                    'SERVER_HOST' => '0.0.0.0',
                    'SERVER_PORT' => '8080',
                    'MONITORING_ENABLED' => 'true',
                    'SECURITY_LEVEL' => 'high',
                ];
                
                foreach ($defaults as $key => $value) {
                    if (!$handler->has($key)) {
                        $handler->set($key, $value);
                    }
                }
            },
            configMapping: [
                'APP_ENV' => 'app.env',
                'APP_DEBUG' => 'app.debug',
                'SERVER_HOST' => 'server.host',
                'SERVER_PORT' => 'server.port',
                'MONITORING_ENABLED' => 'monitoring.enabled',
                'SECURITY_LEVEL' => 'security.level',
            ]
        );
        
        // Test Blueprint-style values
        $this->assertEquals('production', EnvironmentManager::get('APP_ENV'));
        $this->assertFalse(EnvironmentManager::get('APP_DEBUG'));
        $this->assertEquals('0.0.0.0', EnvironmentManager::get('SERVER_HOST'));
        $this->assertEquals(8080, EnvironmentManager::get('SERVER_PORT'));
        $this->assertTrue(EnvironmentManager::get('MONITORING_ENABLED'));
        $this->assertEquals('high', EnvironmentManager::get('SECURITY_LEVEL'));
        
        // Test config mapping
        $mapping = EnvironmentManager::getConfigMapping();
        $this->assertEquals('app.env', $mapping['APP_ENV']);
        $this->assertEquals('monitoring.enabled', $mapping['MONITORING_ENABLED']);
    }
    
    public function testNanoStyleConfiguration(): void
    {
        // Simulate Nano template initialization
        EnvironmentManager::initialize(
            customLoader: function ($handler) {
                // Nano defaults
                $defaults = [
                    'APP_ENV' => 'development',
                    'SERVER_HOST' => '0.0.0.0',
                    'SERVER_PORT' => '8080',
                    'SERVER_WORKERS' => '1',
                    'MEMORY_LIMIT' => '2M',
                    'LOG_LEVEL' => 'info',
                ];
                
                foreach ($defaults as $key => $value) {
                    if (!$handler->has($key)) {
                        $handler->set($key, $value);
                    }
                }
            },
            configMapping: [
                'APP_ENV' => 'env',
                'SERVER_HOST' => 'host',
                'SERVER_PORT' => 'port',
                'SERVER_WORKERS' => 'workers',
                'MEMORY_LIMIT' => 'memory_limit',
                'LOG_LEVEL' => 'log_level',
            ]
        );
        
        // Test Nano-style values
        $this->assertEquals('development', EnvironmentManager::get('APP_ENV'));
        $this->assertEquals('0.0.0.0', EnvironmentManager::get('SERVER_HOST'));
        $this->assertEquals(8080, EnvironmentManager::get('SERVER_PORT'));
        $this->assertEquals(1, EnvironmentManager::get('SERVER_WORKERS'));
        $this->assertEquals('2M', EnvironmentManager::get('MEMORY_LIMIT'));
        $this->assertEquals('info', EnvironmentManager::get('LOG_LEVEL'));
        
        // Test config mapping
        $mapping = EnvironmentManager::getConfigMapping();
        $this->assertEquals('env', $mapping['APP_ENV']);
        $this->assertEquals('workers', $mapping['SERVER_WORKERS']);
    }
    
    public function testEnvironmentOverride(): void
    {
        // Set environment variable before initialization
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['SERVER_PORT'] = '9000';
        
        EnvironmentManager::initialize(
            customLoader: function ($handler) {
                $defaults = [
                    'APP_ENV' => 'production',
                    'SERVER_PORT' => '8080',
                ];
                
                foreach ($defaults as $key => $value) {
                    if (!$handler->has($key)) {
                        $handler->set($key, $value);
                    }
                }
            }
        );
        
        // Environment variables should take precedence over defaults
        $this->assertEquals('testing', EnvironmentManager::get('APP_ENV'));
        $this->assertEquals(9000, EnvironmentManager::get('SERVER_PORT'));
        
        // Clean up
        unset($_ENV['APP_ENV']);
        unset($_ENV['SERVER_PORT']);
    }
    
    public function testValidationWithRequiredVariables(): void
    {
        EnvironmentManager::initialize();
        
        // Basic validation should pass (no required variables)
        $errors = EnvironmentManager::validate();
        $this->assertEmpty($errors);
    }
    
    public function testHelpersIntegration(): void
    {
        EnvironmentManager::initialize();
        EnvironmentManager::set('HELPER_TEST', 'helper_value');
        
        // Test that env() function works with EnvironmentManager
        $this->assertEquals('helper_value', env('HELPER_TEST'));
        $this->assertEquals('default', env('NONEXISTENT', 'default'));
    }
}