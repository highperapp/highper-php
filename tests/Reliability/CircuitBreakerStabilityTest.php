<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Tests\Reliability;

use HighPerApp\HighPer\Resilience\CircuitBreaker;
use HighPerApp\HighPer\Tests\TestCase;
use HighPerApp\HighPer\Bootstrap\ServerBootstrap;

class CircuitBreakerStabilityTest extends TestCase
{
    protected array $services;

    protected function setUp(): void
    {
        parent::setUp();
        $this->services = [];
    }

    public function testLongRunningStability(): void
    {
        $breaker = new CircuitBreaker('long-running-service', [
            'failure_threshold' => 5,
            'timeout' => 10 // 10 second timeout for faster testing
        ]);
        
        $successCount = 0;
        $failureCount = 0;
        $circuitOpenCount = 0;
        
        // Simulate 24 hours of operation (compressed to seconds)
        for ($i = 0; $i < 1000; $i++) {
            try {
                $result = $breaker->call(function() use ($i) {
                    // Simulate intermittent failures (10% failure rate)
                    if ($i % 10 === 0) {
                        throw new \RuntimeException('Simulated service failure');
                    }
                    return 'success';
                });
                
                if ($result === 'success') {
                    $successCount++;
                }
                
            } catch (\RuntimeException $e) {
                $failureCount++;
            } catch (\Exception $e) {
                // Circuit breaker exception
                $circuitOpenCount++;
                
                // Wait for circuit to potentially close
                if ($circuitOpenCount % 10 === 0) {
                    sleep(1); // Simulate waiting
                }
            }
        }
        
        $stats = $breaker->getStats();
        
        $this->assertGreaterThan(800, $successCount); // Should have high success rate
        $this->assertLessThan(200, $failureCount); // Should have limited failures
        $this->assertGreaterThan(0, $circuitOpenCount); // Circuit should have opened at some point
        $this->assertLessThan(50, $stats['failure_rate']); // Overall failure rate should be manageable
    }

    public function testConcurrentCircuitBreakers(): void
    {
        $breakers = [];
        $results = [];
        
        // Create multiple circuit breakers for different services
        for ($i = 0; $i < 10; $i++) {
            $breakers[$i] = new CircuitBreaker("service-{$i}", [
                'failure_threshold' => 3,
                'timeout' => 5
            ]);
        }
        
        // Simulate concurrent requests across all services
        for ($request = 0; $request < 100; $request++) {
            $serviceId = $request % 10;
            $breaker = $breakers[$serviceId];
            
            try {
                $result = $breaker->call(function() use ($serviceId, $request) {
                    // Different failure patterns for different services
                    if ($serviceId % 3 === 0 && $request % 5 === 0) {
                        throw new \RuntimeException("Service {$serviceId} failure");
                    }
                    return "success-{$serviceId}";
                });
                
                $results[$serviceId][] = $result;
                
            } catch (\Exception $e) {
                $results[$serviceId][] = 'failure';
            }
        }
        
        // Verify each service handled requests appropriately
        foreach ($breakers as $i => $breaker) {
            $stats = $breaker->getStats();
            $this->assertGreaterThan(0, $stats['total_calls']);
            
            if ($i % 3 === 0) {
                // Services with failures should have some circuit breaker activations
                $this->assertGreaterThan(0, $stats['failure_count']);
            } else {
                // Services without failures should have low failure counts
                $this->assertLessThan(2, $stats['failure_count']);
            }
        }
    }

    public function testMemoryUsageStability(): void
    {
        $initialMemory = memory_get_usage(true);
        
        $breaker = new CircuitBreaker('memory-test-service', [
            'failure_threshold' => 10,
            'timeout' => 1
        ]);
        
        // Execute many operations to test memory stability
        for ($i = 0; $i < 1000; $i++) {
            try {
                $breaker->call(function() use ($i) {
                    // Create some temporary data
                    $data = str_repeat('x', 1000);
                    
                    if ($i % 50 === 0) {
                        throw new \RuntimeException('Memory test failure');
                    }
                    
                    return $data;
                });
            } catch (\Exception $e) {
                // Handle exceptions
            }
            
            // Force garbage collection periodically
            if ($i % 100 === 0) {
                gc_collect_cycles();
            }
        }
        
        $finalMemory = memory_get_usage(true);
        $memoryIncrease = $finalMemory - $initialMemory;
        
        // Memory increase should be minimal (less than 1MB)
        $this->assertLessThan(1024 * 1024, $memoryIncrease);
    }

    public function testRecoveryPatterns(): void
    {
        $breaker = new CircuitBreaker('recovery-service', [
            'failure_threshold' => 3,
            'timeout' => 2
        ]);
        
        $recoveryAttempts = 0;
        $successfulRecoveries = 0;
        
        // Simulate service degradation and recovery cycles
        for ($cycle = 0; $cycle < 5; $cycle++) {
            // Degrade service (trigger circuit opening)
            for ($i = 0; $i < 5; $i++) {
                try {
                    $breaker->call(function() {
                        throw new \RuntimeException('Service degraded');
                    });
                } catch (\Exception $e) {
                    // Expected failures
                }
            }
            
            $this->assertEquals('open', $breaker->getState());
            
            // Wait for recovery window
            sleep(3);
            
            // Attempt recovery
            $recoveryAttempts++;
            try {
                $result = $breaker->call(function() {
                    return 'service recovered';
                });
                
                if ($result === 'service recovered') {
                    $successfulRecoveries++;
                    $this->assertEquals('closed', $breaker->getState());
                }
            } catch (\Exception $e) {
                // Recovery failed, circuit should reopen
            }
        }
        
        $this->assertEquals(5, $recoveryAttempts);
        $this->assertGreaterThan(0, $successfulRecoveries);
    }

    public function testLoadBalancingWithCircuitBreakers(): void
    {
        $servers = [
            'server1' => new CircuitBreaker('server1', ['failure_threshold' => 3, 'timeout' => 5]),
            'server2' => new CircuitBreaker('server2', ['failure_threshold' => 3, 'timeout' => 5]),
            'server3' => new CircuitBreaker('server3', ['failure_threshold' => 3, 'timeout' => 5])
        ];
        
        $serverStates = [
            'server1' => 'healthy',
            'server2' => 'unhealthy', // Will fail requests
            'server3' => 'healthy'
        ];
        
        $requestResults = [];
        
        // Simulate load balancing with circuit breakers
        for ($request = 0; $request < 100; $request++) {
            $availableServers = [];
            
            foreach ($servers as $name => $breaker) {
                if ($breaker->canExecute()) {
                    $availableServers[] = $name;
                }
            }
            
            if (empty($availableServers)) {
                $requestResults[] = 'no_servers_available';
                continue;
            }
            
            // Round-robin among available servers
            $serverName = $availableServers[$request % count($availableServers)];
            $breaker = $servers[$serverName];
            
            try {
                $result = $breaker->call(function() use ($serverName, $serverStates) {
                    if ($serverStates[$serverName] === 'unhealthy') {
                        throw new \RuntimeException("Server {$serverName} is down");
                    }
                    return "response_from_{$serverName}";
                });
                
                $requestResults[] = $result;
                
            } catch (\Exception $e) {
                $requestResults[] = 'request_failed';
            }
        }
        
        // Verify load balancing behavior
        $successfulRequests = array_filter($requestResults, function($result) {
            return strpos($result, 'response_from_') === 0;
        });
        
        $this->assertGreaterThan(50, count($successfulRequests)); // Should have many successful requests
        
        // Verify unhealthy server circuit is open
        $this->assertEquals('open', $servers['server2']->getState());
        
        // Verify healthy servers are still available
        $this->assertEquals('closed', $servers['server1']->getState());
        $this->assertEquals('closed', $servers['server3']->getState());
    }

    public function testFallbackChainStability(): void
    {
        $primaryBreaker = new CircuitBreaker('primary-service', [
            'failure_threshold' => 2,
            'timeout' => 3
        ]);
        
        $secondaryBreaker = new CircuitBreaker('secondary-service', [
            'failure_threshold' => 2,
            'timeout' => 3
        ]);
        
        $results = [];
        
        // Simulate primary service failures leading to fallback usage
        for ($i = 0; $i < 50; $i++) {
            try {
                // Try primary service first
                $result = $primaryBreaker->call(function() use ($i) {
                    if ($i < 20) {
                        throw new \RuntimeException('Primary service down');
                    }
                    return 'primary_success';
                });
                
                $results[] = $result;
                
            } catch (\Exception $e) {
                // Fall back to secondary service
                try {
                    $result = $secondaryBreaker->call(function() use ($i) {
                        if ($i >= 10 && $i < 15) {
                            throw new \RuntimeException('Secondary service overloaded');
                        }
                        return 'secondary_success';
                    });
                    
                    $results[] = $result;
                    
                } catch (\Exception $e) {
                    $results[] = 'all_services_failed';
                }
            }
        }
        
        // Verify fallback behavior
        $primarySuccesses = count(array_filter($results, fn($r) => $r === 'primary_success'));
        $secondarySuccesses = count(array_filter($results, fn($r) => $r === 'secondary_success'));
        $totalFailures = count(array_filter($results, fn($r) => $r === 'all_services_failed'));
        
        $this->assertGreaterThan(20, $primarySuccesses); // Primary should recover
        $this->assertGreaterThan(15, $secondarySuccesses); // Secondary should handle fallbacks
        $this->assertLessThan(10, $totalFailures); // Total failures should be minimal
    }

    public function testGracefulDegradation(): void
    {
        $breaker = new CircuitBreaker('degrading-service', [
            'failure_threshold' => 5,
            'timeout' => 2
        ]);
        
        $responseQuality = [];
        
        // Simulate gradual service degradation
        for ($i = 0; $i < 100; $i++) {
            $failureRate = min($i / 50, 0.8); // Gradually increase failure rate to 80%
            
            try {
                $result = $breaker->callWithFallback(
                    function() use ($failureRate) {
                        if (mt_rand(0, 100) < ($failureRate * 100)) {
                            throw new \RuntimeException('Service degraded');
                        }
                        return 'high_quality_response';
                    },
                    function() {
                        return 'degraded_response'; // Fallback with lower quality
                    }
                );
                
                $responseQuality[] = $result;
                
            } catch (\Exception $e) {
                $responseQuality[] = 'no_response';
            }
        }
        
        // Verify graceful degradation
        $highQuality = count(array_filter($responseQuality, fn($r) => $r === 'high_quality_response'));
        $degraded = count(array_filter($responseQuality, fn($r) => $r === 'degraded_response'));
        $noResponse = count(array_filter($responseQuality, fn($r) => $r === 'no_response'));
        
        $this->assertGreaterThan(20, $highQuality); // Should have some high quality responses
        $this->assertGreaterThan(30, $degraded); // Should provide degraded responses when possible
        $this->assertLessThan(20, $noResponse); // Should minimize complete failures
    }

    public function testTCPProtocolCircuitBreakerIntegration(): void
    {
        if (!class_exists('\\HighPerApp\\HighPer\\TCP\\TCPServiceProvider')) {
            $this->markTestSkipped('TCP package not available');
        }
        
        $app = $this->createApplication([
            'server' => [
                'dedicated_ports' => [
                    'tcp' => [
                        'circuit_breaker' => [
                            'enabled' => true,
                            'failure_threshold' => 3,
                            'recovery_timeout' => 5,
                            'half_open_max_calls' => 2,
                        ]
                    ]
                ]
            ]
        ]);
        
        $container = $app->getContainer();
        
        if ($container->has('tcp.client.pool')) {
            $tcpClientPool = $container->get('tcp.client.pool');
            
            // Test circuit breaker behavior with TCP connections
            $successCount = 0;
            $failureCount = 0;
            $circuitOpenCount = 0;
            
            // Simulate TCP connection failures
            for ($i = 0; $i < 20; $i++) {
                try {
                    // Simulate connection attempt
                    $connection = $tcpClientPool->getConnection('test_pool', [
                        'host' => '127.0.0.1',
                        'port' => 9999, // Non-existent port to trigger failures
                        'timeout' => 1
                    ]);
                    
                    if ($connection) {
                        $successCount++;
                    }
                } catch (\Exception $e) {
                    if (strpos($e->getMessage(), 'Circuit breaker is open') !== false) {
                        $circuitOpenCount++;
                    } else {
                        $failureCount++;
                    }
                }
                
                // Small delay between attempts
                usleep(100000); // 100ms
            }
            
            // Verify circuit breaker engaged
            $this->assertGreaterThan(0, $circuitOpenCount, 'Circuit breaker should have engaged');
            $this->assertLessThan(20, $failureCount, 'Circuit breaker should have prevented some failures');
            
            // Test recovery behavior
            sleep(6); // Wait for recovery timeout
            
            // Attempt connection again to test half-open state
            $recoveryAttempts = 0;
            for ($i = 0; $i < 3; $i++) {
                try {
                    $connection = $tcpClientPool->getConnection('test_pool', [
                        'host' => '127.0.0.1',
                        'port' => 9999,
                        'timeout' => 1
                    ]);
                    $recoveryAttempts++;
                } catch (\Exception $e) {
                    // Expected - connection will still fail but circuit breaker should allow limited attempts
                }
            }
            
            // Verify circuit breaker allowed recovery attempts
            $this->assertGreaterThan(0, $recoveryAttempts, 'Circuit breaker should allow recovery attempts');
            
            echo "\nTCP Protocol Circuit Breaker Integration: ✓\n";
            echo "Success Count: $successCount\n";
            echo "Failure Count: $failureCount\n";
            echo "Circuit Open Count: $circuitOpenCount\n";
            echo "Recovery Attempts: $recoveryAttempts\n";
        }
    }

    public function testProtocolSegregationReliability(): void
    {
        $app = $this->createApplication([
            'server' => [
                'mode' => 'security_segregated',
                'protocol_segregation' => [
                    'enabled' => true,
                    'non_secure' => [
                        'protocols' => ['http', 'tcp'],
                        'port' => 8080
                    ],
                    'secure' => [
                        'protocols' => ['https', 'tcp_tls'],
                        'port' => 8443
                    ]
                ]
            ]
        ]);
        
        $container = $app->getContainer();
        
        if ($container->has('protocol.router')) {
            $router = $container->get('protocol.router');
            
            // Test protocol isolation under failure conditions
            $nonSecureFailures = 0;
            $secureFailures = 0;
            
            // Simulate failures in non-secure protocols
            for ($i = 0; $i < 10; $i++) {
                try {
                    // Simulate non-secure protocol failure
                    $mockConnection = $this->createMockConnection('tcp', 8080, false);
                    $handler = $router->route($mockConnection);
                    
                    if (!$handler) {
                        $nonSecureFailures++;
                    }
                } catch (\Exception $e) {
                    $nonSecureFailures++;
                }
            }
            
            // Simulate secure protocol requests (should not be affected)
            for ($i = 0; $i < 10; $i++) {
                try {
                    $mockConnection = $this->createMockConnection('tcp_tls', 8443, true);
                    $handler = $router->route($mockConnection);
                    
                    if (!$handler) {
                        $secureFailures++;
                    }
                } catch (\Exception $e) {
                    $secureFailures++;
                }
            }
            
            // Verify protocol isolation
            $this->assertLessThan(5, $secureFailures, 'Secure protocols should be isolated from non-secure failures');
            
            echo "\nProtocol Segregation Reliability: ✓\n";
            echo "Non-secure Failures: $nonSecureFailures\n";
            echo "Secure Failures: $secureFailures\n";
            echo "Isolation Effectiveness: " . (($nonSecureFailures > $secureFailures) ? '✓' : '✗') . "\n";
        }
    }
    
    private function createMockConnection(string $protocol, int $port, bool $isSecure): object
    {
        return new class($protocol, $port, $isSecure) {
            public function __construct(
                private string $protocol,
                private int $port,
                private bool $isSecure
            ) {}
            
            public function getId(): string { return 'mock_' . uniqid(); }
            public function getLocalPort(): int { return $this->port; }
            public function getRemoteAddress(): string { return '127.0.0.1'; }
            public function getTransport(): string { return $this->isSecure ? 'tcp_tls' : 'tcp'; }
            public function peek(int $length, int $timeout = 5000): string { 
                return $this->isSecure ? "\x16\x03\x01" : "GET / HTTP/1.1\r\n"; 
            }
            public function read(): string { return ''; }
            public function write(string $data): int { return strlen($data); }
            public function close(): void {}
            public function isOpen(): bool { return true; }
            public function isTlsEnabled(): bool { return $this->isSecure; }
            public function isQuic(): bool { return false; }
            public function readStream(): string { return ''; }
            public function writeStream(string $data): int { return strlen($data); }
        };
    }
}