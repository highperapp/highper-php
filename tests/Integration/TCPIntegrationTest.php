<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Tests\Integration;

use HighPerApp\HighPer\Tests\TestCase;
use HighPerApp\HighPer\Bootstrap\ServerBootstrap;

/**
 * TCP Package Integration Tests for HighPer Framework
 */
class TCPIntegrationTest extends TestCase
{
    public function testTCPPackageDiscovery(): void
    {
        $app = $this->createApplication();
        
        // Check if TCP package is discovered
        $container = $app->getContainer();
        
        // LibraryLoader should detect TCP service provider
        if (class_exists('\\HighPerApp\\HighPer\\TCP\\TCPServiceProvider')) {
            $this->assertTrue($container->has('tcp.provider'), 'TCP provider should be registered');
            
            $tcpProvider = $container->get('tcp.provider');
            $this->assertInstanceOf('\\HighPerApp\\HighPer\\TCP\\TCPServiceProvider', $tcpProvider);
            
            echo "\nTCP Package Discovery: ✓\n";
            echo "TCP Server Available: " . ($tcpProvider->isServerAvailable() ? '✓' : '✗') . "\n";
            echo "TCP Client Available: " . ($tcpProvider->isClientAvailable() ? '✓' : '✗') . "\n";
        } else {
            $this->markTestSkipped('TCP package not available for integration testing');
        }
    }

    public function testTCPServiceRegistration(): void
    {
        if (!class_exists('\\HighPerApp\\HighPer\\TCP\\TCPServiceProvider')) {
            $this->markTestSkipped('TCP package not available');
        }
        
        $app = $this->createApplication();
        $container = $app->getContainer();
        
        // Test TCP service registration
        $this->assertTrue($container->has('tcp.server'), 'TCP server should be registered');
        $this->assertTrue($container->has('tcp.client.pool'), 'TCP client pool should be registered');
        $this->assertTrue($container->has('tcp.manager'), 'TCP manager should be registered');
        
        // Test service instantiation
        $tcpServer = $container->get('tcp.server');
        $tcpClientPool = $container->get('tcp.client.pool');
        $tcpManager = $container->get('tcp.manager');
        
        $this->assertNotNull($tcpServer);
        $this->assertNotNull($tcpClientPool);
        $this->assertNotNull($tcpManager);
        
        echo "\nTCP Service Registration: ✓\n";
        echo "TCP Server: " . get_class($tcpServer) . "\n";
        echo "TCP Client Pool: " . get_class($tcpClientPool) . "\n";
        echo "TCP Manager: " . get_class($tcpManager) . "\n";
    }

    public function testServerBootstrapIntegration(): void
    {
        if (!class_exists('\\HighPerApp\\HighPer\\TCP\\TCPServiceProvider')) {
            $this->markTestSkipped('TCP package not available');
        }
        
        $app = $this->createTestApplication();
        $bootstrap = new ServerBootstrap();
        
        // Test bootstrap integration
        $this->assertTrue($bootstrap->canBootstrap($app), 'Bootstrap should be able to run');
        
        $bootstrap->bootstrap($app);
        
        $container = $app->getContainer();
        $this->assertTrue($container->has('server'), 'Server should be registered');
        
        $server = $container->get('server');
        $supportedProtocols = $server->getSupportedProtocols();
        
        $this->assertIsArray($supportedProtocols);
        $this->assertContains('http', $supportedProtocols);
        
        echo "\nServer Bootstrap Integration: ✓\n";
        echo "Supported Protocols: " . implode(', ', $supportedProtocols) . "\n";
    }

    public function testTCPConfigurationIntegration(): void
    {
        if (!class_exists('\\HighPerApp\\HighPer\\TCP\\TCPServiceProvider')) {
            $this->markTestSkipped('TCP package not available');
        }
        
        $config = [
            'tcp' => [
                'server' => [
                    'host' => '127.0.0.1',
                    'port' => 8888,
                    'max_connections' => 1000
                ],
                'client' => [
                    'max_connections' => 100,
                    'circuit_breaker' => [
                        'enabled' => true,
                        'failure_threshold' => 5
                    ]
                ]
            ]
        ];
        
        $app = $this->createApplication($config);
        $container = $app->getContainer();
        
        if ($container->has('tcp.provider')) {
            $tcpProvider = $container->get('tcp.provider');
            $providerConfig = $tcpProvider->getConfig();
            
            $this->assertEquals('127.0.0.1', $providerConfig['server']['host']);
            $this->assertEquals(8888, $providerConfig['server']['port']);
            $this->assertEquals(1000, $providerConfig['server']['max_connections']);
            $this->assertTrue($providerConfig['client']['circuit_breaker']['enabled']);
            
            echo "\nTCP Configuration Integration: ✓\n";
            echo "Server Host: {$providerConfig['server']['host']}\n";
            echo "Server Port: {$providerConfig['server']['port']}\n";
            echo "Max Connections: {$providerConfig['server']['max_connections']}\n";
        }
    }

    public function testHealthCheckIntegration(): void
    {
        if (!class_exists('\\HighPerApp\\HighPer\\TCP\\TCPServiceProvider')) {
            $this->markTestSkipped('TCP package not available');
        }
        
        $app = $this->createApplication();
        $container = $app->getContainer();
        
        if ($container->has('health')) {
            $healthChecker = $container->get('health');
            $healthStatus = $healthChecker->check();
            
            $this->assertIsArray($healthStatus);
            
            // Check if TCP health checks are registered
            if (isset($healthStatus['tcp_server']) || isset($healthStatus['tcp_client_pool'])) {
                echo "\nTCP Health Check Integration: ✓\n";
                
                if (isset($healthStatus['tcp_server'])) {
                    echo "TCP Server Health: " . ($healthStatus['tcp_server']['healthy'] ? '✓' : '✗') . "\n";
                }
                
                if (isset($healthStatus['tcp_client_pool'])) {
                    echo "TCP Client Pool Health: " . ($healthStatus['tcp_client_pool']['healthy'] ? '✓' : '✗') . "\n";
                }
            }
        }
    }

    public function testMetricsCollectionIntegration(): void
    {
        if (!class_exists('\\HighPerApp\\HighPer\\TCP\\TCPServiceProvider')) {
            $this->markTestSkipped('TCP package not available');
        }
        
        $app = $this->createApplication();
        $container = $app->getContainer();
        
        if ($container->has('monitoring')) {
            $monitoring = $container->get('monitoring');
            
            // Collect metrics
            $metrics = $monitoring->collectMetrics();
            
            $this->assertIsArray($metrics);
            
            // Check if TCP metrics are included
            if (isset($metrics['tcp_server']) || isset($metrics['tcp_client_pool'])) {
                echo "\nTCP Metrics Collection Integration: ✓\n";
                
                if (isset($metrics['tcp_server'])) {
                    echo "TCP Server Metrics: Available\n";
                }
                
                if (isset($metrics['tcp_client_pool'])) {
                    echo "TCP Client Pool Metrics: Available\n";
                }
            }
        }
    }

    public function testEnvironmentVariableConfiguration(): void
    {
        // Set test environment variables
        $_ENV['TCP_HOST'] = '0.0.0.0';
        $_ENV['TCP_PORT'] = '9999';
        $_ENV['TCP_MAX_CONNECTIONS'] = '2000';
        $_ENV['TCP_CLIENT_MAX_CONNECTIONS'] = '200';
        $_ENV['TCP_RUST_ACCELERATION'] = 'false';
        
        if (!class_exists('\\HighPerApp\\HighPer\\TCP\\TCPServiceProvider')) {
            $this->markTestSkipped('TCP package not available');
        }
        
        $app = $this->createApplication();
        $container = $app->getContainer();
        
        if ($container->has('tcp.provider')) {
            $tcpProvider = $container->get('tcp.provider');
            $config = $tcpProvider->getConfig();
            
            $this->assertEquals('0.0.0.0', $config['server']['host']);
            $this->assertEquals(9999, $config['server']['port']);
            $this->assertEquals(2000, $config['server']['max_connections']);
            $this->assertEquals(200, $config['client']['max_connections']);
            $this->assertFalse($config['server']['rust_acceleration']);
            
            echo "\nEnvironment Variable Configuration: ✓\n";
            echo "Host from ENV: {$config['server']['host']}\n";
            echo "Port from ENV: {$config['server']['port']}\n";
            echo "Max Connections from ENV: {$config['server']['max_connections']}\n";
        }
        
        // Clean up environment variables
        unset($_ENV['TCP_HOST'], $_ENV['TCP_PORT'], $_ENV['TCP_MAX_CONNECTIONS'], 
              $_ENV['TCP_CLIENT_MAX_CONNECTIONS'], $_ENV['TCP_RUST_ACCELERATION']);
    }

    public function testPerformanceStackIntegration(): void
    {
        if (!class_exists('\\HighPerApp\\HighPer\\TCP\\TCPServiceProvider')) {
            $this->markTestSkipped('TCP package not available');
        }
        
        $app = $this->createApplication();
        $container = $app->getContainer();
        
        // Test integration with performance components
        $this->assertTrue($container->has('connection_pools'), 'Connection pools should be available');
        $this->assertTrue($container->has('memory'), 'Memory manager should be available');
        
        if ($container->has('tcp.client.pool')) {
            $tcpClientPool = $container->get('tcp.client.pool');
            
            // TCP client pool should integrate with connection pool manager
            $poolNames = $tcpClientPool->getPoolNames();
            $this->assertIsArray($poolNames);
            $this->assertContains('default', $poolNames);
            
            echo "\nPerformance Stack Integration: ✓\n";
            echo "Connection Pool Manager: Available\n";
            echo "Memory Manager: Available\n";
            echo "TCP Pool Names: " . implode(', ', $poolNames) . "\n";
        }
    }

    public function testReliabilityStackIntegration(): void
    {
        if (!class_exists('\\HighPerApp\\HighPer\\TCP\\TCPServiceProvider')) {
            $this->markTestSkipped('TCP package not available');
        }
        
        $app = $this->createApplication();
        $container = $app->getContainer();
        
        if ($container->has('tcp.client.pool')) {
            $tcpClientPool = $container->get('tcp.client.pool');
            
            // Test circuit breaker integration
            $stats = $tcpClientPool->getPoolStats('default');
            $this->assertIsArray($stats);
            $this->assertArrayHasKey('circuit_breaker_state', $stats);
            
            // Test health monitoring
            $health = $tcpClientPool->checkPoolHealth('default');
            $this->assertIsBool($health);
            
            echo "\nReliability Stack Integration: ✓\n";
            echo "Circuit Breaker State: {$stats['circuit_breaker_state']}\n";
            echo "Pool Health: " . ($health ? '✓' : '✗') . "\n";
        }
    }

    public function testProtocolRouterIntegration(): void
    {
        if (!class_exists('\\HighPerApp\\HighPer\\TCP\\TCPServiceProvider')) {
            $this->markTestSkipped('TCP package not available');
        }
        
        $app = $this->createApplication();
        $container = $app->getContainer();
        
        // Test protocol router registration
        if ($container->has('protocol.router')) {
            $router = $container->get('protocol.router');
            $this->assertNotNull($router);
            
            // Test supported protocols
            $supportedProtocols = $router->getSupportedProtocols();
            $this->assertIsArray($supportedProtocols);
            $this->assertContains('tcp', $supportedProtocols);
            
            // Test routing statistics
            $stats = $router->getStatistics();
            $this->assertIsArray($stats);
            $this->assertArrayHasKey('supported_protocols', $stats);
            
            echo "\nProtocol Router Integration: ✓\n";
            echo "Supported Protocols: " . implode(', ', $supportedProtocols) . "\n";
            echo "Routing Mode: " . ($stats['routing_mode'] ?? 'unknown') . "\n";
        }
    }

    public function testProtocolHandlerIntegration(): void
    {
        if (!class_exists('\\HighPerApp\\HighPer\\TCP\\TCPServiceProvider')) {
            $this->markTestSkipped('TCP package not available');
        }
        
        $app = $this->createApplication();
        $container = $app->getContainer();
        
        // Test TCP protocol handler registration
        if ($container->has('tcp.protocol.handler')) {
            $handler = $container->get('tcp.protocol.handler');
            $this->assertNotNull($handler);
            
            // Test protocol handling capabilities
            $this->assertTrue($handler->canHandle('tcp'));
            $this->assertTrue($handler->canHandle('tcp_tls'));
            $this->assertFalse($handler->canHandle('http'));
            
            // Test handler statistics
            $stats = $handler->getStatistics();
            $this->assertIsArray($stats);
            $this->assertArrayHasKey('supported_protocols', $stats);
            
            echo "\nProtocol Handler Integration: ✓\n";
            echo "Handler Name: " . $handler->getName() . "\n";
            echo "Can Handle TCP: ✓\n";
            echo "Can Handle TCP TLS: ✓\n";
        }
    }

    public function testMultiProtocolConfigurationIntegration(): void
    {
        $config = [
            'server' => [
                'mode' => 'security_segregated',
                'protocol_segregation' => [
                    'enabled' => true,
                    'non_secure' => [
                        'protocols' => ['http', 'ws', 'tcp'],
                        'port' => 8080
                    ],
                    'secure' => [
                        'protocols' => ['https', 'wss', 'tcp_tls'],
                        'port' => 8443
                    ]
                ]
            ]
        ];
        
        if (!class_exists('\\HighPerApp\\HighPer\\TCP\\TCPServiceProvider')) {
            $this->markTestSkipped('TCP package not available');
        }
        
        $app = $this->createApplication($config);
        $container = $app->getContainer();
        
        if ($container->has('server.config.manager')) {
            $configManager = $container->get('server.config.manager');
            
            $this->assertEquals('security_segregated', $configManager->getMode());
            $this->assertTrue($configManager->isProtocolSegregationEnabled());
            $this->assertEquals(8080, $configManager->getPortForProtocol('tcp', false));
            $this->assertEquals(8443, $configManager->getPortForProtocol('tcp', true));
            
            echo "\nMulti-Protocol Configuration Integration: ✓\n";
            echo "Server Mode: " . $configManager->getMode() . "\n";
            echo "Protocol Segregation: " . ($configManager->isProtocolSegregationEnabled() ? 'Enabled' : 'Disabled') . "\n";
            echo "TCP Port: " . $configManager->getPortForProtocol('tcp', false) . "\n";
            echo "TCP TLS Port: " . $configManager->getPortForProtocol('tcp', true) . "\n";
        }
    }

}