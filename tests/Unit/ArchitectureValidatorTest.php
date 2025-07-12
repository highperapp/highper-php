<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use HighPerApp\HighPer\Foundation\ArchitectureValidator;
use HighPerApp\HighPer\Contracts\LoggerInterface;

/**
 * ArchitectureValidator Unit Tests
 * 
 * Tests configuration validation, optimization, and system capability detection.
 */
class ArchitectureValidatorTest extends TestCase
{
    private LoggerInterface $logger;
    private ArchitectureValidator $validator;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->validator = new ArchitectureValidator($this->logger);
    }

    public function testSystemCapabilitiesDetection(): void
    {
        $capabilities = $this->validator->getSystemCapabilities();
        
        $expectedKeys = [
            'cpu_cores',
            'total_memory',
            'uv_available',
            'ffi_available',
            'pcntl_available',
            'opcache_available',
            'php_version'
        ];
        
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $capabilities);
        }
        
        $this->assertIsInt($capabilities['cpu_cores']);
        $this->assertGreaterThan(0, $capabilities['cpu_cores']);
        $this->assertIsInt($capabilities['total_memory']);
        $this->assertGreaterThan(0, $capabilities['total_memory']);
        $this->assertIsBool($capabilities['uv_available']);
        $this->assertIsBool($capabilities['ffi_available']);
        $this->assertIsBool($capabilities['pcntl_available']);
        $this->assertIsBool($capabilities['opcache_available']);
        $this->assertIsString($capabilities['php_version']);
    }

    public function testBasicConfigurationValidation(): void
    {
        $config = [
            'workers' => [
                'count' => 4,
                'memory_limit' => '256M'
            ],
            'server' => [
                'mode' => 'single_port_multiplexing',
                'port' => 8080
            ],
            'event_loop' => [
                'uv_enabled' => false
            ]
        ];
        
        $validated = $this->validator->validateConfiguration($config);
        
        $this->assertArrayHasKey('workers', $validated);
        $this->assertArrayHasKey('server', $validated);
        $this->assertArrayHasKey('event_loop', $validated);
        
        $this->assertEquals(4, $validated['workers']['count']);
        $this->assertEquals('256M', $validated['workers']['memory_limit']);
        $this->assertEquals(8080, $validated['server']['port']);
    }

    public function testWorkerCountValidation(): void
    {
        $capabilities = $this->validator->getSystemCapabilities();
        $maxRecommended = $capabilities['cpu_cores'] * 2;
        
        // Test exceeding recommended maximum
        $config = [
            'workers' => [
                'count' => $maxRecommended + 10
            ]
        ];
        
        $this->logger->expects($this->once())
                     ->method('warning')
                     ->with(
                         $this->stringContains('Worker count exceeds recommended maximum'),
                         $this->isType('array')
                     );
        
        $validated = $this->validator->validateConfiguration($config);
        $this->assertEquals($maxRecommended, $validated['workers']['count']);
    }

    public function testMemoryLimitValidation(): void
    {
        // Test various memory limit formats
        $testCases = [
            '256M' => '256M',
            '1G' => '1G',
            '512M' => '512M',
            '2G' => '2G'
        ];
        
        foreach ($testCases as $input => $expected) {
            $config = [
                'workers' => [
                    'memory_limit' => $input
                ]
            ];
            
            $validated = $this->validator->validateConfiguration($config);
            $this->assertIsString($validated['workers']['memory_limit']);
        }
    }

    public function testUVExtensionValidation(): void
    {
        $config = [
            'event_loop' => [
                'uv_enabled' => true
            ]
        ];
        
        $validated = $this->validator->validateConfiguration($config);
        
        // Should respect actual UV availability
        $capabilities = $this->validator->getSystemCapabilities();
        $expectedUvEnabled = $capabilities['uv_available'];
        
        if (!$expectedUvEnabled) {
            $this->logger->expects($this->once())
                         ->method('warning')
                         ->with(
                             $this->stringContains('UV extension requested but not available'),
                             $this->anything()
                         );
            
            $this->validator->validateConfiguration($config);
        }
    }

    public function testDedicatedPortsConfiguration(): void
    {
        $config = [
            'server' => [
                'mode' => 'dedicated_ports',
                'ports' => [
                    'http' => 8080,
                    'ws' => 8081
                ]
            ]
        ];
        
        $validated = $this->validator->validateConfiguration($config);
        
        $this->assertEquals('dedicated_ports', $validated['server']['mode']);
        $this->assertEquals(8080, $validated['server']['ports']['http']);
        $this->assertEquals(8081, $validated['server']['ports']['ws']);
    }

    public function testProtocolValidation(): void
    {
        $config = [
            'server' => [
                'protocols' => ['http', 'ws', 'invalid_protocol', 'grpc']
            ]
        ];
        
        $validated = $this->validator->validateConfiguration($config);
        
        // Should filter out invalid protocols
        $validProtocols = ['http', 'ws', 'grpc'];
        $this->assertEquals($validProtocols, $validated['server']['protocols']);
    }

    public function testZeroDowntimeConfiguration(): void
    {
        $config = [
            'zero_downtime' => [
                'enabled' => true,
                'deployment_strategy' => 'invalid_strategy'
            ]
        ];
        
        $this->logger->expects($this->once())
                     ->method('warning')
                     ->with(
                         $this->stringContains('Invalid deployment strategy'),
                         $this->isType('array')
                     );
        
        $validated = $this->validator->validateConfiguration($config);
        
        $this->assertEquals('blue_green', $validated['zero_downtime']['deployment_strategy']);
        $this->assertEquals(30, $validated['zero_downtime']['graceful_shutdown_timeout']);
    }

    public function testC10MOptimizations(): void
    {
        $config = [
            'server' => [
                'c10m_enabled' => true
            ],
            'workers' => [
                'memory_limit' => '256M'
            ]
        ];
        
        $this->logger->expects($this->once())
                     ->method('info')
                     ->with(
                         $this->stringContains('Applying C10M optimizations'),
                         $this->anything()
                     );
        
        $validated = $this->validator->validateConfiguration($config);
        
        // Should apply C10M optimizations
        $this->assertEquals(10000, $validated['workers']['max_connections_per_worker']);
        $this->assertEquals(50000, $validated['workers']['restart_threshold']);
        $this->assertEquals(5000, $validated['event_loop']['thresholds']['connections']);
        $this->assertEquals('512M', $validated['workers']['memory_limit']);
    }

    public function testRustOptimizations(): void
    {
        $config = [
            'server' => [
                'rust_enabled' => true
            ]
        ];
        
        $capabilities = $this->validator->getSystemCapabilities();
        
        if (!$capabilities['ffi_available']) {
            $this->logger->expects($this->once())
                         ->method('warning')
                         ->with(
                             $this->stringContains('Rust FFI optimizations requested but FFI extension not available'),
                             $this->anything()
                         );
        } else {
            $this->logger->expects($this->once())
                         ->method('info')
                         ->with(
                             $this->stringContains('Applying Rust FFI optimizations'),
                             $this->anything()
                         );
        }
        
        $validated = $this->validator->validateConfiguration($config);
        
        if ($capabilities['ffi_available']) {
            $this->assertTrue($validated['rust']['enabled']);
            $this->assertArrayHasKey('libraries', $validated['rust']);
        } else {
            $this->assertFalse($validated['server']['rust_enabled']);
        }
    }

    public function testOptimalConfigGeneration(): void
    {
        $optimalConfig = $this->validator->generateOptimalConfig();
        
        $this->assertArrayHasKey('workers', $optimalConfig);
        $this->assertArrayHasKey('event_loop', $optimalConfig);
        $this->assertArrayHasKey('server', $optimalConfig);
        $this->assertArrayHasKey('zero_downtime', $optimalConfig);
        
        $capabilities = $this->validator->getSystemCapabilities();
        
        $this->assertEquals($capabilities['cpu_cores'], $optimalConfig['workers']['count']);
        $this->assertEquals($capabilities['uv_available'], $optimalConfig['event_loop']['uv_enabled']);
        $this->assertEquals($capabilities['ffi_available'], $optimalConfig['server']['rust_enabled']);
        $this->assertTrue($optimalConfig['zero_downtime']['enabled']);
    }

    public function testConfigurationLogging(): void
    {
        $config = [
            'workers' => ['count' => 4],
            'server' => ['mode' => 'single_port_multiplexing']
        ];
        
        $this->logger->expects($this->once())
                     ->method('info')
                     ->with(
                         $this->stringContains('Architecture configuration validated'),
                         $this->isType('array')
                     );
        
        $this->validator->validateConfiguration($config);
    }

    public function testMemoryLimitParsing(): void
    {
        $validator = new ArchitectureValidator($this->logger);
        $reflection = new \ReflectionClass($validator);
        $method = $reflection->getMethod('parseMemoryLimit');
        $method->setAccessible(true);
        
        $testCases = [
            '256M' => 256 * 1024 * 1024,
            '1G' => 1024 * 1024 * 1024,
            '512K' => 512 * 1024,
            '1024' => 1024
        ];
        
        foreach ($testCases as $input => $expected) {
            $result = $method->invoke($validator, $input);
            $this->assertEquals($expected, $result);
        }
    }

    public function testMemoryLimitFormatting(): void
    {
        $validator = new ArchitectureValidator($this->logger);
        $reflection = new \ReflectionClass($validator);
        $method = $reflection->getMethod('formatBytes');
        $method->setAccessible(true);
        
        $testCases = [
            1024 * 1024 * 1024 => '1G',
            256 * 1024 * 1024 => '256M',
            512 * 1024 => '512K'
        ];
        
        foreach ($testCases as $input => $expected) {
            $result = $method->invoke($validator, $input);
            $this->assertEquals($expected, $result);
        }
    }

    public function testDeploymentStrategyValidation(): void
    {
        $validStrategies = ['blue_green', 'rolling'];
        
        foreach ($validStrategies as $strategy) {
            $config = [
                'zero_downtime' => [
                    'enabled' => true,
                    'deployment_strategy' => $strategy
                ]
            ];
            
            $validated = $this->validator->validateConfiguration($config);
            $this->assertEquals($strategy, $validated['zero_downtime']['deployment_strategy']);
        }
    }

    public function testThresholdConfiguration(): void
    {
        $config = [
            'event_loop' => [
                'thresholds' => [
                    'connections' => 2000,
                    'timers' => 200,
                    'file_ops' => 100
                ]
            ]
        ];
        
        $validated = $this->validator->validateConfiguration($config);
        
        $this->assertEquals(2000, $validated['event_loop']['thresholds']['connections']);
        $this->assertEquals(200, $validated['event_loop']['thresholds']['timers']);
        $this->assertEquals(100, $validated['event_loop']['thresholds']['file_ops']);
    }
}