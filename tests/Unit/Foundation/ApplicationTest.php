<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Tests\Unit\Foundation;

use HighPerApp\HighPer\Foundation\Application;
use HighPerApp\HighPer\Tests\TestCase;
use HighPerApp\HighPer\Contracts\ContainerInterface;
use HighPerApp\HighPer\Contracts\ConfigManagerInterface;
use HighPerApp\HighPer\Contracts\ServiceProviderInterface;

class ApplicationTest extends TestCase
{
    protected string $testBasePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testBasePath = '/tmp/highper_test_' . uniqid();
        mkdir($this->testBasePath, 0755, true);
        $this->app = new Application($this->testBasePath);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testBasePath)) {
            $this->removeDirectory($this->testBasePath);
        }
        parent::tearDown();
    }

    public function testApplicationBootstrap(): void
    {
        $this->assertInstanceOf(Application::class, $this->app);
        $this->assertEquals($this->testBasePath, $this->app->getBasePath());
        $this->assertInstanceOf(ContainerInterface::class, $this->app->getContainer());
        $this->assertInstanceOf(ConfigManagerInterface::class, $this->app->getConfig());
    }

    public function testServiceProviderRegistration(): void
    {
        $provider = new class implements ServiceProviderInterface {
            private bool $registered = false;
            private bool $booted = false;

            public function register(): void
            {
                $this->registered = true;
            }

            public function boot(): void
            {
                $this->booted = true;
            }

            public function provides(): array
            {
                return ['test.service'];
            }

            public function isRegistered(): bool
            {
                return $this->registered;
            }

            public function isBooted(): bool
            {
                return $this->booted;
            }
        };

        $this->app->registerProvider($provider);
        $this->assertTrue($provider->isRegistered());
        
        $this->app->boot();
        $this->assertTrue($provider->isBooted());
    }

    public function testEnvironmentDetection(): void
    {
        $this->assertEquals('testing', $this->app->getEnvironment());
        $this->assertTrue($this->app->isEnvironment('testing'));
        $this->assertFalse($this->app->isEnvironment('production'));
    }

    public function testDebugMode(): void
    {
        $this->assertTrue($this->app->isDebug());
    }

    public function testErrorHandling(): void
    {
        $errorHandled = false;
        $this->app->getContainer()->singleton('error.handler', function() use (&$errorHandled) {
            return function($exception) use (&$errorHandled) {
                $errorHandled = true;
            };
        });

        $this->app->handleError(new \Exception('Test error'));
        $this->assertTrue($errorHandled);
    }

    public function testConfigurationAccess(): void
    {
        $config = $this->app->getConfig();
        $this->assertNotNull($config);
        
        // Test default configuration is loaded
        $this->assertIsArray($config->get('app'));
        $this->assertEquals('testing', $config->get('app.env'));
    }

    public function testPathHelpers(): void
    {
        $this->assertEquals($this->testBasePath . '/config', $this->app->getConfigPath());
        $this->assertEquals($this->testBasePath . '/storage', $this->app->getStoragePath());
        $this->assertEquals($this->testBasePath . '/public', $this->app->getPublicPath());
    }

    public function testApplicationVersion(): void
    {
        $version = $this->app->getVersion();
        $this->assertIsString($version);
        $this->assertNotEmpty($version);
    }

    public function testTermination(): void
    {
        $terminated = false;
        $this->app->terminating(function() use (&$terminated) {
            $terminated = true;
        });

        $this->app->terminate();
        $this->assertTrue($terminated);
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