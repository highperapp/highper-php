<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Tests\Unit\Foundation\Environment;

use HighPerApp\HighPer\Foundation\Environment\DefaultEnvironmentHandler;
use HighPerApp\HighPer\Contracts\EnvironmentManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * DefaultEnvironmentHandler Unit Tests
 * 
 * Tests for the default environment management implementation
 */
class DefaultEnvironmentHandlerTest extends TestCase
{
    private DefaultEnvironmentHandler $handler;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new DefaultEnvironmentHandler();
        
        // Clear environment for clean tests
        unset($_ENV['TEST_VAR']);
        unset($_SERVER['TEST_VAR']);
    }
    
    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(EnvironmentManagerInterface::class, $this->handler);
    }
    
    public function testSetAndGet(): void
    {
        $this->handler->set('TEST_VAR', 'test_value');
        
        $this->assertEquals('test_value', $this->handler->get('TEST_VAR'));
        $this->assertEquals('test_value', $_ENV['TEST_VAR']);
        $this->assertEquals('test_value', $_SERVER['TEST_VAR']);
    }
    
    public function testGetWithDefault(): void
    {
        $result = $this->handler->get('NONEXISTENT_VAR', 'default_value');
        $this->assertEquals('default_value', $result);
    }
    
    public function testHas(): void
    {
        $this->assertFalse($this->handler->has('TEST_VAR'));
        
        $this->handler->set('TEST_VAR', 'value');
        $this->assertTrue($this->handler->has('TEST_VAR'));
    }
    
    public function testBooleanTransformation(): void
    {
        $this->handler->set('BOOL_TRUE', 'true');
        $this->handler->set('BOOL_FALSE', 'false');
        $this->handler->set('NULL_VAL', 'null');
        
        $this->assertTrue($this->handler->get('BOOL_TRUE'));
        $this->assertFalse($this->handler->get('BOOL_FALSE'));
        $this->assertNull($this->handler->get('NULL_VAL'));
    }
    
    public function testNumericTransformation(): void
    {
        $this->handler->set('INT_VAL', '123');
        $this->handler->set('FLOAT_VAL', '123.45');
        
        $this->assertSame(123, $this->handler->get('INT_VAL'));
        $this->assertSame(123.45, $this->handler->get('FLOAT_VAL'));
    }
    
    public function testConfigMapping(): void
    {
        $mapping = ['TEST_KEY' => 'test.config.path'];
        $this->handler->setConfigMapping($mapping);
        
        $this->assertEquals($mapping, $this->handler->getConfigMapping());
    }
    
    public function testValidateEnvironmentEmpty(): void
    {
        $errors = $this->handler->validateEnvironment();
        $this->assertEmpty($errors);
    }
    
    public function testReset(): void
    {
        $this->handler->set('TEST_VAR', 'value');
        $this->handler->get('TEST_VAR'); // This should cache the value
        
        $this->handler->reset();
        
        // The cache should be cleared, but environment variables should remain
        $this->assertEquals('value', $this->handler->get('TEST_VAR'));
    }
    
    public function testCustomLoader(): void
    {
        $customLoader = function ($handler) {
            $handler->set('CUSTOM_VAR', 'custom_value');
        };
        
        $handler = new DefaultEnvironmentHandler($customLoader);
        $handler->load();
        
        $this->assertEquals('custom_value', $handler->get('CUSTOM_VAR'));
    }
    
    public function testCustomLoaderWithMapping(): void
    {
        $mapping = ['APP_NAME' => 'app.name'];
        $customLoader = function ($handler) {
            $handler->set('APP_NAME', 'TestApp');
        };
        
        $handler = new DefaultEnvironmentHandler($customLoader, $mapping);
        $handler->load();
        
        $this->assertEquals('TestApp', $handler->get('APP_NAME'));
        $this->assertEquals($mapping, $handler->getConfigMapping());
    }
}