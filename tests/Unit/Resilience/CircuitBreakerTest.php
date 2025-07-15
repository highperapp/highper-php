<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Tests\Unit\Resilience;

use HighPerApp\HighPer\Resilience\CircuitBreaker;
use HighPerApp\HighPer\Tests\TestCase;

class CircuitBreakerTest extends TestCase
{
    protected CircuitBreaker $circuitBreaker;
    protected array $config;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->config = [
            'failure_threshold' => 5,
            'timeout' => 60,
            'expected_exceptions' => [\RuntimeException::class]
        ];
        
        $this->circuitBreaker = new CircuitBreaker('test-service', $this->config);
    }

    public function testInitialClosedState(): void
    {
        $this->assertEquals('closed', $this->circuitBreaker->getState());
        $this->assertEquals(0, $this->circuitBreaker->getFailureCount());
        $this->assertTrue($this->circuitBreaker->canExecute());
    }

    public function testSuccessfulExecution(): void
    {
        $result = $this->circuitBreaker->call(function() {
            return 'success';
        });
        
        $this->assertEquals('success', $result);
        $this->assertEquals('closed', $this->circuitBreaker->getState());
        $this->assertEquals(0, $this->circuitBreaker->getFailureCount());
    }

    public function testFailureHandling(): void
    {
        try {
            $this->circuitBreaker->call(function() {
                throw new \RuntimeException('Service failed');
            });
        } catch (\RuntimeException $e) {
            // Expected exception
        }
        
        $this->assertEquals('closed', $this->circuitBreaker->getState());
        $this->assertEquals(1, $this->circuitBreaker->getFailureCount());
    }

    public function testCircuitOpening(): void
    {
        // Trigger failures up to threshold
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->circuitBreaker->call(function() {
                    throw new \RuntimeException('Service failed');
                });
            } catch (\RuntimeException $e) {
                // Expected exception
            }
        }
        
        $this->assertEquals('open', $this->circuitBreaker->getState());
        $this->assertFalse($this->circuitBreaker->canExecute());
    }

    public function testOpenCircuitBlocking(): void
    {
        // Open the circuit
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->circuitBreaker->call(function() {
                    throw new \RuntimeException('Service failed');
                });
            } catch (\RuntimeException $e) {
                // Expected exception
            }
        }
        
        $this->expectException(\HighPerApp\HighPer\Exceptions\CircuitBreakerException::class);
        $this->circuitBreaker->call(function() {
            return 'should not execute';
        });
    }

    public function testHalfOpenTransition(): void
    {
        // Open the circuit
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->circuitBreaker->call(function() {
                    throw new \RuntimeException('Service failed');
                });
            } catch (\RuntimeException $e) {
                // Expected exception
            }
        }
        
        $this->assertEquals('open', $this->circuitBreaker->getState());
        
        // Simulate timeout passage
        $this->circuitBreaker->setLastFailureTime(time() - 61);
        
        // Should transition to half-open
        $this->assertTrue($this->circuitBreaker->canExecute());
        $this->assertEquals('half-open', $this->circuitBreaker->getState());
    }

    public function testHalfOpenSuccessfulRecovery(): void
    {
        // Open the circuit
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->circuitBreaker->call(function() {
                    throw new \RuntimeException('Service failed');
                });
            } catch (\RuntimeException $e) {
                // Expected exception
            }
        }
        
        // Force half-open state
        $this->circuitBreaker->setLastFailureTime(time() - 61);
        $this->circuitBreaker->canExecute(); // Triggers half-open
        
        // Successful call should close the circuit
        $result = $this->circuitBreaker->call(function() {
            return 'recovered';
        });
        
        $this->assertEquals('recovered', $result);
        $this->assertEquals('closed', $this->circuitBreaker->getState());
        $this->assertEquals(0, $this->circuitBreaker->getFailureCount());
    }

    public function testHalfOpenFailureReopening(): void
    {
        // Open the circuit
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->circuitBreaker->call(function() {
                    throw new \RuntimeException('Service failed');
                });
            } catch (\RuntimeException $e) {
                // Expected exception
            }
        }
        
        // Force half-open state
        $this->circuitBreaker->setLastFailureTime(time() - 61);
        $this->circuitBreaker->canExecute(); // Triggers half-open
        
        // Failed call should reopen the circuit
        try {
            $this->circuitBreaker->call(function() {
                throw new \RuntimeException('Still failing');
            });
        } catch (\RuntimeException $e) {
            // Expected exception
        }
        
        $this->assertEquals('open', $this->circuitBreaker->getState());
        $this->assertFalse($this->circuitBreaker->canExecute());
    }

    public function testIgnoreUnexpectedExceptions(): void
    {
        try {
            $this->circuitBreaker->call(function() {
                throw new \InvalidArgumentException('Unexpected exception');
            });
        } catch (\InvalidArgumentException $e) {
            // Expected exception
        }
        
        // Should not count as failure since it's not in expected_exceptions
        $this->assertEquals('closed', $this->circuitBreaker->getState());
        $this->assertEquals(0, $this->circuitBreaker->getFailureCount());
    }

    public function testFallbackExecution(): void
    {
        // Open the circuit
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->circuitBreaker->call(function() {
                    throw new \RuntimeException('Service failed');
                });
            } catch (\RuntimeException $e) {
                // Expected exception
            }
        }
        
        $result = $this->circuitBreaker->callWithFallback(
            function() {
                return 'should not execute';
            },
            function() {
                return 'fallback result';
            }
        );
        
        $this->assertEquals('fallback result', $result);
    }

    public function testCircuitBreakerStatistics(): void
    {
        // Execute some successful calls
        for ($i = 0; $i < 3; $i++) {
            $this->circuitBreaker->call(function() {
                return 'success';
            });
        }
        
        // Execute some failed calls
        for ($i = 0; $i < 2; $i++) {
            try {
                $this->circuitBreaker->call(function() {
                    throw new \RuntimeException('Service failed');
                });
            } catch (\RuntimeException $e) {
                // Expected exception
            }
        }
        
        $stats = $this->circuitBreaker->getStats();
        
        $this->assertArrayHasKey('state', $stats);
        $this->assertArrayHasKey('failure_count', $stats);
        $this->assertArrayHasKey('success_count', $stats);
        $this->assertArrayHasKey('total_calls', $stats);
        $this->assertArrayHasKey('failure_rate', $stats);
        $this->assertArrayHasKey('last_failure_time', $stats);
        
        $this->assertEquals('closed', $stats['state']);
        $this->assertEquals(2, $stats['failure_count']);
        $this->assertEquals(3, $stats['success_count']);
        $this->assertEquals(5, $stats['total_calls']);
        $this->assertEquals(40.0, $stats['failure_rate']); // 2/5 * 100
    }

    public function testCircuitBreakerReset(): void
    {
        // Generate some failures
        for ($i = 0; $i < 3; $i++) {
            try {
                $this->circuitBreaker->call(function() {
                    throw new \RuntimeException('Service failed');
                });
            } catch (\RuntimeException $e) {
                // Expected exception
            }
        }
        
        $this->assertEquals(3, $this->circuitBreaker->getFailureCount());
        
        $this->circuitBreaker->reset();
        
        $this->assertEquals('closed', $this->circuitBreaker->getState());
        $this->assertEquals(0, $this->circuitBreaker->getFailureCount());
        $this->assertTrue($this->circuitBreaker->canExecute());
    }

    public function testConfigurableThreshold(): void
    {
        $customBreaker = new CircuitBreaker('custom-service', [
            'failure_threshold' => 2,
            'timeout' => 30
        ]);
        
        // Should open after 2 failures instead of 5
        for ($i = 0; $i < 2; $i++) {
            try {
                $customBreaker->call(function() {
                    throw new \RuntimeException('Service failed');
                });
            } catch (\RuntimeException $e) {
                // Expected exception
            }
        }
        
        $this->assertEquals('open', $customBreaker->getState());
    }

    public function testAsyncCallSupport(): void
    {
        $result = $this->circuitBreaker->callAsync(function() {
            return 'async success';
        });
        
        $this->assertEquals('async success', $result);
        $this->assertEquals('closed', $this->circuitBreaker->getState());
    }
}