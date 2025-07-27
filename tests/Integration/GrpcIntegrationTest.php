<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Tests\Integration;

use PHPUnit\Framework\TestCase;
use HighPerApp\HighPer\ServiceProvider\GrpcServiceProvider;
use HighPerApp\HighPer\GRPC\GrpcServerFactory;
use HighPerApp\HighPer\GRPC\GrpcServer;
use HighPerApp\HighPer\GRPC\Engines\HybridEngine;
use HighPerApp\HighPer\GRPC\ServiceProvider\GrpcServiceProvider as BaseGrpcServiceProvider;
use HighPerApp\HighPer\Container\Container;
use Psr\Log\NullLogger;

/**
 * Test gRPC integration with HighPer framework
 */
class GrpcIntegrationTest extends TestCase
{
    private Container $container;
    private GrpcServiceProvider $provider;

    protected function setUp(): void
    {
        $this->container = new Container();
        
        // Mock configuration
        $this->container->singleton('config', function() {
            return new class {
                public function get(string $key, $default = null) {
                    $config = [
                        'grpc.host' => '0.0.0.0',
                        'grpc.port' => 9090,
                        'grpc.worker_processes' => 2,
                        'grpc.parallel_workers' => 1,
                        'grpc.engine.rust_acceleration' => false,
                        'grpc.circuit_breaker.enabled' => true,
                        'grpc.retry.enabled' => true,
                    ];
                    
                    return $config[$key] ?? $default;
                }
            };
        });
        
        $this->container->singleton(LoggerInterface::class, function() {
            return new NullLogger();
        });
        
        $this->provider = new GrpcServiceProvider($this->container);
    }

    public function testGrpcServiceProviderRegistration(): void
    {
        $this->provider->register();
        
        // Test that gRPC components are registered
        $this->assertTrue($this->container->has('grpc.factory'));
        $this->assertTrue($this->container->has('grpc.server'));
        $this->assertTrue($this->container->has('grpc.engine'));
        $this->assertTrue($this->container->has('grpc.protocol_handler'));
        $this->assertTrue($this->container->has('grpc.circuit_breaker'));
        $this->assertTrue($this->container->has('grpc.retry_handler'));
        $this->assertTrue($this->container->has('grpc.serializer'));
    }

    public function testGrpcServerFactoryCreation(): void
    {
        $this->provider->register();
        
        $factory = $this->container->get('grpc.factory');
        $this->assertInstanceOf(GrpcServerFactory::class, $factory);
        
        $server = $this->container->get('grpc.server');
        $this->assertInstanceOf(GrpcServer::class, $server);
    }

    public function testGrpcEngineCreation(): void
    {
        $this->provider->register();
        
        $engine = $this->container->get('grpc.engine');
        $this->assertInstanceOf(HybridEngine::class, $engine);
        
        // Test engine is ready
        $this->assertTrue($engine->isReady());
    }

    public function testGrpcConfigurationIntegration(): void
    {
        $this->provider->register();
        
        $config = $this->container->get('grpc.config');
        $this->assertIsArray($config);
        
        // Test default configuration values
        $this->assertEquals('0.0.0.0', $config['host']);
        $this->assertEquals(9090, $config['port']);
        $this->assertEquals(2, $config['worker_processes']);
        $this->assertEquals(1, $config['parallel_workers']);
        $this->assertFalse($config['engine']['rust_acceleration']);
        $this->assertTrue($config['circuit_breaker']['enabled']);
        $this->assertTrue($config['retry']['enabled']);
    }

    public function testGrpcProviderAliases(): void
    {
        $this->provider->register();
        
        // Test class aliases work
        $this->assertTrue($this->container->has(GrpcServerFactory::class));
        $this->assertTrue($this->container->has(GrpcServer::class));
        $this->assertTrue($this->container->has(HybridEngine::class));
        
        // Test instances are the same
        $this->assertSame(
            $this->container->get('grpc.factory'),
            $this->container->get(GrpcServerFactory::class)
        );
    }

    public function testGrpcProviderMethods(): void
    {
        $this->provider->register();
        
        // Test provider methods
        $this->assertTrue($this->provider->isEnabled());
        $this->assertIsArray($this->provider->getConfig());
        $this->assertInstanceOf(GrpcServer::class, $this->provider->getServer());
        $this->assertInstanceOf(GrpcServerFactory::class, $this->provider->getFactory());
    }

    public function testGrpcProviderProvidesServices(): void
    {
        $provides = $this->provider->provides();
        $this->assertIsArray($provides);
        
        // Test key services are provided
        $this->assertContains('grpc.factory', $provides);
        $this->assertContains('grpc.server', $provides);
        $this->assertContains('grpc.engine', $provides);
        $this->assertContains(GrpcServerFactory::class, $provides);
        $this->assertContains(GrpcServer::class, $provides);
    }

    public function testGrpcServerConfiguration(): void
    {
        $this->provider->register();
        $this->provider->boot();
        
        $server = $this->container->get('grpc.server');
        $this->assertInstanceOf(GrpcServer::class, $server);
        
        // Test server info
        $info = $server->getInfo();
        $this->assertIsArray($info);
        $this->assertArrayHasKey('version', $info);
        $this->assertArrayHasKey('php_version', $info);
    }
}