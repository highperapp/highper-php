<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Tests\Unit\Foundation;

use HighPerApp\HighPer\Foundation\ConfigManager;
use HighPerApp\HighPer\Tests\TestCase;

class ConfigManagerTest extends TestCase
{
    protected ConfigManager $config;
    protected string $testConfigPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testConfigPath = '/tmp/highper_config_' . uniqid();
        mkdir($this->testConfigPath, 0755, true);
        $this->config = new ConfigManager($this->testConfigPath);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testConfigPath)) {
            $this->removeDirectory($this->testConfigPath);
        }
        parent::tearDown();
    }

    public function testBasicConfigOperations(): void
    {
        $this->config->set('test.key', 'value');
        $this->assertEquals('value', $this->config->get('test.key'));
        
        $this->assertTrue($this->config->has('test.key'));
        $this->assertFalse($this->config->has('non.existent'));
    }

    public function testNestedConfigAccess(): void
    {
        $this->config->set('app.database.host', 'localhost');
        $this->config->set('app.database.port', 3306);
        
        $this->assertEquals('localhost', $this->config->get('app.database.host'));
        $this->assertEquals(3306, $this->config->get('app.database.port'));
        
        $database = $this->config->get('app.database');
        $this->assertIsArray($database);
        $this->assertEquals('localhost', $database['host']);
        $this->assertEquals(3306, $database['port']);
    }

    public function testDefaultValues(): void
    {
        $this->assertEquals('default', $this->config->get('non.existent', 'default'));
        $this->assertNull($this->config->get('non.existent'));
    }

    public function testNamespaceOperations(): void
    {
        $this->config->setNamespace('database', [
            'host' => 'localhost',
            'port' => 3306,
            'username' => 'root'
        ]);
        
        $namespace = $this->config->getNamespace('database');
        $this->assertIsArray($namespace);
        $this->assertEquals('localhost', $namespace['host']);
        $this->assertEquals(3306, $namespace['port']);
        $this->assertEquals('root', $namespace['username']);
    }

    public function testConfigFileLoading(): void
    {
        // Create test config file
        $appConfig = [
            'name' => 'HighPer Test',
            'env' => 'testing',
            'debug' => true
        ];
        
        file_put_contents(
            $this->testConfigPath . '/app.php',
            '<?php return ' . var_export($appConfig, true) . ';'
        );
        
        $this->config->loadFile('app');
        
        $this->assertEquals('HighPer Test', $this->config->get('app.name'));
        $this->assertEquals('testing', $this->config->get('app.env'));
        $this->assertTrue($this->config->get('app.debug'));
    }

    public function testEnvironmentVariableSubstitution(): void
    {
        $_ENV['TEST_HOST'] = 'test.example.com';
        $_ENV['TEST_PORT'] = '8080';
        
        $this->config->set('server.host', '${TEST_HOST}');
        $this->config->set('server.port', '${TEST_PORT}');
        
        $this->assertEquals('test.example.com', $this->config->get('server.host'));
        $this->assertEquals('8080', $this->config->get('server.port'));
        
        unset($_ENV['TEST_HOST'], $_ENV['TEST_PORT']);
    }

    public function testConfigCaching(): void
    {
        $this->config->set('cache.test', 'cached_value');
        
        // Test that cached value is returned
        $this->assertEquals('cached_value', $this->config->get('cache.test'));
        
        // Test cache invalidation
        $this->config->invalidate('cache.test');
        $this->config->set('cache.test', 'new_value');
        $this->assertEquals('new_value', $this->config->get('cache.test'));
    }

    public function testAllConfiguration(): void
    {
        $this->config->set('app.name', 'Test App');
        $this->config->set('database.host', 'localhost');
        
        $all = $this->config->all();
        $this->assertIsArray($all);
        $this->assertArrayHasKey('app', $all);
        $this->assertArrayHasKey('database', $all);
        $this->assertEquals('Test App', $all['app']['name']);
        $this->assertEquals('localhost', $all['database']['host']);
    }

    public function testConfigMerging(): void
    {
        $this->config->set('app.features', ['feature1', 'feature2']);
        $this->config->merge('app', [
            'features' => ['feature3'],
            'new_setting' => 'value'
        ]);
        
        $features = $this->config->get('app.features');
        $this->assertContains('feature1', $features);
        $this->assertContains('feature2', $features);
        $this->assertContains('feature3', $features);
        $this->assertEquals('value', $this->config->get('app.new_setting'));
    }

    public function testConfigValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->config->set('', 'invalid_key');
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}