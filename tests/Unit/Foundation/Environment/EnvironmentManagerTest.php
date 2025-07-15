<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Tests\Unit\Foundation\Environment;

use HighPerApp\HighPer\Foundation\Environment\EnvironmentManager;
use HighPerApp\HighPer\Foundation\Environment\DefaultEnvironmentHandler;
use HighPerApp\HighPer\Contracts\EnvironmentManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * EnvironmentManager Unit Tests
 * 
 * Tests for the static environment manager
 */
class EnvironmentManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        EnvironmentManager::reset();
        
        // Clear test environment variables
        unset($_ENV['TEST_VAR']);
        unset($_SERVER['TEST_VAR']);
    }
    
    protected function tearDown(): void
    {
        EnvironmentManager::reset();
        parent::tearDown();
    }
    
    public function testInitializeWithDefaults(): void
    {
        EnvironmentManager::initialize();
        
        $this->assertTrue(true); // If we get here, initialization worked
    }
    
    public function testGetAndSet(): void
    {
        EnvironmentManager::initialize();
        EnvironmentManager::set('TEST_VAR', 'test_value');
        
        $this->assertEquals('test_value', EnvironmentManager::get('TEST_VAR'));
    }
    
    public function testGetWithDefault(): void
    {
        EnvironmentManager::initialize();
        
        $result = EnvironmentManager::get('NONEXISTENT_VAR', 'default_value');
        $this->assertEquals('default_value', $result);
    }
    
    public function testHas(): void
    {
        EnvironmentManager::initialize();
        
        $this->assertFalse(EnvironmentManager::has('TEST_VAR'));
        
        EnvironmentManager::set('TEST_VAR', 'value');
        $this->assertTrue(EnvironmentManager::has('TEST_VAR'));
    }
    
    public function testValidate(): void
    {
        EnvironmentManager::initialize();
        
        $errors = EnvironmentManager::validate();
        $this->assertIsArray($errors);
    }
    
    public function testConfigMapping(): void
    {
        $mapping = ['TEST_KEY' => 'test.config'];
        EnvironmentManager::initialize(null, $mapping);
        
        $this->assertEquals($mapping, EnvironmentManager::getConfigMapping());
    }
    
    public function testSetConfigMapping(): void
    {
        EnvironmentManager::initialize();
        
        $mapping = ['NEW_KEY' => 'new.config'];
        EnvironmentManager::setConfigMapping($mapping);
        
        $this->assertEquals($mapping, EnvironmentManager::getConfigMapping());
    }
    
    public function testCustomLoader(): void
    {
        $customLoader = function ($handler) {
            $handler->set('CUSTOM_VAR', 'custom_value');
        };
        
        EnvironmentManager::initialize($customLoader);
        
        $this->assertEquals('custom_value', EnvironmentManager::get('CUSTOM_VAR'));
    }
    
    public function testSetHandler(): void
    {
        $handler = new DefaultEnvironmentHandler();
        $handler->set('HANDLER_VAR', 'handler_value');
        
        EnvironmentManager::setHandler($handler);
        
        $this->assertEquals('handler_value', EnvironmentManager::get('HANDLER_VAR'));
    }
    
    public function testReset(): void
    {
        EnvironmentManager::initialize();
        EnvironmentManager::set('TEST_VAR', 'value');
        
        EnvironmentManager::reset();
        
        // After reset, should need to initialize again
        EnvironmentManager::initialize();
        $this->assertEquals('value', EnvironmentManager::get('TEST_VAR')); // Should still be in $_ENV
    }
    
    public function testInitializeOnlyOnce(): void
    {
        EnvironmentManager::initialize();
        EnvironmentManager::set('TEST_VAR', 'first_value');
        
        // Second initialization should be ignored
        EnvironmentManager::initialize();
        
        $this->assertEquals('first_value', EnvironmentManager::get('TEST_VAR'));
    }
}