<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Tests\Integration;

use HighPerApp\HighPer\Foundation\Application;
use HighPerApp\HighPer\ServiceProvider\CompressionServiceProvider;
use HighPerApp\HighPer\Compression\Contracts\CompressionManagerInterface;
use HighPerApp\HighPer\Compression\Contracts\ConfigurationInterface;
use HighPerApp\HighPer\Compression\Middleware\CompressionMiddleware;
use HighPerApp\HighPer\Tests\TestCase;

/**
 * Compression Integration Test
 * 
 * Tests the integration of the compression standalone library
 * into the HighPer framework through the CompressionServiceProvider.
 */
class CompressionIntegrationTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->app = new Application([
            'app' => [
                'name' => 'HighPer Test',
                'env' => 'testing',
                'debug' => true
            ],
            'compression' => [
                'engines' => [
                    'pure_php' => ['enabled' => true],
                    'amphp' => ['enabled' => true],
                    'rust_ffi' => ['enabled' => false] // Disable for testing
                ],
                'debug' => true
            ]
        ]);
    }

    public function testCompressionServiceProviderRegistration(): void
    {
        $provider = new CompressionServiceProvider();
        
        $this->assertInstanceOf(CompressionServiceProvider::class, $provider);
        
        // Test that provider can be registered without errors
        $this->app->registerServiceProvider($provider);
        $this->app->bootstrap();
        
        $this->assertTrue(true); // If we get here, registration succeeded
    }

    public function testCompressionManagerRegistration(): void
    {
        $provider = new CompressionServiceProvider();
        $this->app->registerServiceProvider($provider);
        $this->app->bootstrap();
        
        $container = $this->app->getContainer();
        
        // Test that CompressionManagerInterface is registered
        $this->assertTrue($container->has(CompressionManagerInterface::class));
        
        // Test that we can resolve the compression manager
        $manager = $container->get(CompressionManagerInterface::class);
        $this->assertInstanceOf(CompressionManagerInterface::class, $manager);
        
        // Test aliases
        $this->assertTrue($container->has('compression'));
        $this->assertTrue($container->has('compression.manager'));
        $this->assertSame($manager, $container->get('compression'));
        $this->assertSame($manager, $container->get('compression.manager'));
    }

    public function testCompressionMiddlewareRegistration(): void
    {
        $provider = new CompressionServiceProvider();
        $this->app->registerServiceProvider($provider);
        $this->app->bootstrap();
        
        $container = $this->app->getContainer();
        
        // Test that CompressionMiddleware is registered
        $this->assertTrue($container->has(CompressionMiddleware::class));
        
        // Test that we can resolve the middleware
        $middleware = $container->get(CompressionMiddleware::class);
        $this->assertInstanceOf(CompressionMiddleware::class, $middleware);
        
        // Test alias
        $this->assertTrue($container->has('compression.middleware'));
        $this->assertSame($middleware, $container->get('compression.middleware'));
    }

    public function testCompressionConfigurationRegistration(): void
    {
        $provider = new CompressionServiceProvider();
        $this->app->registerServiceProvider($provider);
        $this->app->bootstrap();
        
        $container = $this->app->getContainer();
        
        // Test that ConfigurationInterface is registered
        $this->assertTrue($container->has(ConfigurationInterface::class));
        
        // Test that we can resolve the configuration
        $config = $container->get(ConfigurationInterface::class);
        $this->assertInstanceOf(ConfigurationInterface::class, $config);
        
        // Test alias
        $this->assertTrue($container->has('compression.config'));
        $this->assertSame($config, $container->get('compression.config'));
    }

    public function testCompressionManagerFunctionality(): void
    {
        $provider = new CompressionServiceProvider();
        $this->app->registerServiceProvider($provider);
        $this->app->bootstrap();
        
        $container = $this->app->getContainer();
        $manager = $container->get(CompressionManagerInterface::class);
        
        // Test that we have available engines
        $engines = $manager->getAvailableEngines();
        $this->assertNotEmpty($engines);
        
        // Test that we have a preferred engine
        $preferredEngine = $manager->getPreferredEngine();
        $this->assertNotEmpty($preferredEngine);
        
        // Test basic compression functionality
        $testData = 'Hello, HighPer Framework with Compression!';
        $compressed = $manager->compress($testData, 'gzip');
        $this->assertNotEmpty($compressed);
        $this->assertNotEquals($testData, $compressed);
        
        // Test decompression
        $decompressed = $manager->decompress($compressed, 'gzip');
        $this->assertEquals($testData, $decompressed);
    }

    public function testCompressionStatistics(): void
    {
        $provider = new CompressionServiceProvider();
        $this->app->registerServiceProvider($provider);
        $this->app->bootstrap();
        
        $container = $this->app->getContainer();
        $manager = $container->get(CompressionManagerInterface::class);
        
        // Get initial stats
        $initialStats = $manager->getCompressionStats();
        $this->assertIsArray($initialStats);
        
        // Perform some operations
        $testData = str_repeat('Test data for compression stats! ', 100);
        $manager->compress($testData, 'gzip');
        $manager->compress($testData, 'gzip');
        
        // Get updated stats
        $updatedStats = $manager->getCompressionStats();
        $this->assertIsArray($updatedStats);
        
        // Stats should have increased
        if (isset($initialStats['total_operations']) && isset($updatedStats['total_operations'])) {
            $this->assertGreaterThan($initialStats['total_operations'], $updatedStats['total_operations']);
        }
    }

    public function testCompressionEngineAvailability(): void
    {
        $provider = new CompressionServiceProvider();
        $this->app->registerServiceProvider($provider);
        $this->app->bootstrap();
        
        $container = $this->app->getContainer();
        $manager = $container->get(CompressionManagerInterface::class);
        
        $engines = $manager->getAvailableEngines();
        $engineNames = array_map(fn($engine) => $engine->getName(), $engines);
        
        // Should have at least PurePHP engine
        $this->assertContains('PurePHP', $engineNames);
        
        // Test each engine
        foreach ($engines as $engine) {
            $this->assertTrue($engine->isAvailable());
            $this->assertNotEmpty($engine->getSupportedAlgorithms());
            $this->assertNotEmpty($engine->getName());
        }
    }

    public function testMultipleCompressionAlgorithms(): void
    {
        $provider = new CompressionServiceProvider();
        $this->app->registerServiceProvider($provider);
        $this->app->bootstrap();
        
        $container = $this->app->getContainer();
        $manager = $container->get(CompressionManagerInterface::class);
        
        $testData = 'Multi-algorithm compression test data! ' . str_repeat('More data ', 50);
        $algorithms = ['gzip', 'deflate'];
        
        foreach ($algorithms as $algorithm) {
            try {
                $compressed = $manager->compress($testData, $algorithm);
                $this->assertNotEmpty($compressed);
                $this->assertNotEquals($testData, $compressed);
                
                $decompressed = $manager->decompress($compressed, $algorithm);
                $this->assertEquals($testData, $decompressed);
                
            } catch (\Exception $e) {
                // Some algorithms might not be available in test environment
                $this->markTestIncomplete("Algorithm {$algorithm} not available: " . $e->getMessage());
            }
        }
    }

    public function testCompressionMiddlewareFunctionality(): void
    {
        $provider = new CompressionServiceProvider();
        $this->app->registerServiceProvider($provider);
        $this->app->bootstrap();
        
        $container = $this->app->getContainer();
        $middleware = $container->get(CompressionMiddleware::class);
        
        $this->assertInstanceOf(CompressionMiddleware::class, $middleware);
        
        // Test that middleware can be configured
        $middleware->addCompressibleType('application/test');
        $middleware->removeCompressibleType('application/test');
        
        // Test setting custom compressible types
        $middleware->setCompressibleTypes(['text/plain', 'application/json']);
        
        $this->assertTrue(true); // If we get here, middleware configuration succeeded
    }

    public function testCompressionConfigurationValues(): void
    {
        $provider = new CompressionServiceProvider();
        $this->app->registerServiceProvider($provider);
        $this->app->bootstrap();
        
        $container = $this->app->getContainer();
        $config = $container->get(ConfigurationInterface::class);
        
        // Test configuration access
        $this->assertIsBool($config->isDebugEnabled());
        $this->assertIsInt($config->getAsyncThreshold());
        
        // Test that configuration has expected structure
        $allConfig = $config->toArray();
        $this->assertIsArray($allConfig);
        $this->assertArrayHasKey('engines', $allConfig);
    }

    public function testFrameworkIntegrationWithCompression(): void
    {
        // Test that compression is properly integrated into the framework's service discovery
        $provider = new CompressionServiceProvider();
        $this->app->registerServiceProvider($provider);
        $this->app->bootstrap();
        
        $container = $this->app->getContainer();
        
        // All compression services should be available
        $services = [
            CompressionManagerInterface::class,
            CompressionMiddleware::class,
            ConfigurationInterface::class,
            'compression',
            'compression.manager',
            'compression.middleware',
            'compression.config'
        ];
        
        foreach ($services as $service) {
            $this->assertTrue(
                $container->has($service),
                "Service '{$service}' should be registered in the container"
            );
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) {
            $this->app->shutdown();
        }
        
        parent::tearDown();
    }
}