<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Tests\Unit\Performance;

use HighPerApp\HighPer\Performance\ConnectionPool;
use HighPerApp\HighPer\Tests\TestCase;
use HighPerApp\HighPer\Contracts\ConnectionInterface;

class ConnectionPoolTest extends TestCase
{
    protected ConnectionPool $pool;
    protected array $config;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->config = [
            'min_connections' => 2,
            'max_connections' => 10,
            'idle_timeout' => 30,
            'connection_timeout' => 5,
            'retry_attempts' => 3
        ];
        
        $this->pool = new ConnectionPool($this->config);
    }

    public function testPoolInitialization(): void
    {
        $this->assertEquals(2, $this->pool->getMinConnections());
        $this->assertEquals(10, $this->pool->getMaxConnections());
        $this->assertEquals(0, $this->pool->getActiveConnections());
        $this->assertEquals(0, $this->pool->getIdleConnections());
    }

    public function testConnectionAcquisition(): void
    {
        $connection = $this->pool->acquire();
        
        $this->assertInstanceOf(ConnectionInterface::class, $connection);
        $this->assertEquals(1, $this->pool->getActiveConnections());
        $this->assertTrue($connection->isConnected());
    }

    public function testConnectionRelease(): void
    {
        $connection = $this->pool->acquire();
        $this->pool->release($connection);
        
        $this->assertEquals(0, $this->pool->getActiveConnections());
        $this->assertEquals(1, $this->pool->getIdleConnections());
    }

    public function testConnectionReuse(): void
    {
        $connection1 = $this->pool->acquire();
        $connectionId1 = $connection1->getId();
        $this->pool->release($connection1);
        
        $connection2 = $this->pool->acquire();
        $connectionId2 = $connection2->getId();
        
        // Should reuse the same connection
        $this->assertEquals($connectionId1, $connectionId2);
    }

    public function testMaxConnectionsLimit(): void
    {
        $connections = [];
        
        // Acquire max connections
        for ($i = 0; $i < 10; $i++) {
            $connections[] = $this->pool->acquire();
        }
        
        $this->assertEquals(10, $this->pool->getActiveConnections());
        
        // Try to acquire one more - should block or throw exception
        $this->expectException(\RuntimeException::class);
        $this->pool->acquire(0.1); // Short timeout
    }

    public function testConnectionHealthCheck(): void
    {
        $connection = $this->pool->acquire();
        
        // Simulate connection failure
        $connection->disconnect();
        $this->pool->release($connection);
        
        // Next acquisition should get a new healthy connection
        $newConnection = $this->pool->acquire();
        $this->assertTrue($newConnection->isConnected());
        $this->assertNotEquals($connection->getId(), $newConnection->getId());
    }

    public function testIdleConnectionTimeout(): void
    {
        $connection = $this->pool->acquire();
        $this->pool->release($connection);
        
        $this->assertEquals(1, $this->pool->getIdleConnections());
        
        // Simulate time passage beyond idle timeout
        $this->pool->cleanupIdleConnections(31);
        
        $this->assertEquals(0, $this->pool->getIdleConnections());
    }

    public function testPoolStatistics(): void
    {
        $connection1 = $this->pool->acquire();
        $connection2 = $this->pool->acquire();
        $this->pool->release($connection1);
        
        $stats = $this->pool->getStats();
        
        $this->assertArrayHasKey('active_connections', $stats);
        $this->assertArrayHasKey('idle_connections', $stats);
        $this->assertArrayHasKey('total_created', $stats);
        $this->assertArrayHasKey('total_destroyed', $stats);
        $this->assertArrayHasKey('peak_connections', $stats);
        
        $this->assertEquals(1, $stats['active_connections']);
        $this->assertEquals(1, $stats['idle_connections']);
        $this->assertGreaterThanOrEqual(2, $stats['total_created']);
    }

    public function testConcurrentConnectionHandling(): void
    {
        $results = [];
        $tasks = [];
        
        // Create multiple concurrent tasks that acquire connections
        for ($i = 0; $i < 5; $i++) {
            $tasks[] = function() use ($i, &$results) {
                $connection = $this->pool->acquire();
                $results[$i] = $connection->getId();
                usleep(10000); // Hold connection for 10ms
                $this->pool->release($connection);
                return $connection->getId();
            };
        }
        
        $connectionIds = $this->runConcurrent($tasks);
        
        $this->assertCount(5, $connectionIds);
        $this->assertCount(5, array_unique($connectionIds)); // All should be different
    }

    public function testConnectionRetry(): void
    {
        $attempts = 0;
        
        // Mock a connection factory that fails first few times
        $this->pool->setConnectionFactory(function() use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new \RuntimeException('Connection failed');
            }
            return $this->createMockConnection();
        });
        
        $connection = $this->pool->acquire();
        $this->assertInstanceOf(ConnectionInterface::class, $connection);
        $this->assertEquals(3, $attempts);
    }

    public function testPoolShutdown(): void
    {
        $connection1 = $this->pool->acquire();
        $connection2 = $this->pool->acquire();
        $this->pool->release($connection1);
        
        $this->pool->shutdown();
        
        $this->assertEquals(0, $this->pool->getActiveConnections());
        $this->assertEquals(0, $this->pool->getIdleConnections());
        $this->assertFalse($connection1->isConnected());
        $this->assertFalse($connection2->isConnected());
    }

    public function testConnectionValidation(): void
    {
        $connection = $this->pool->acquire();
        
        // Connection should be valid when acquired
        $this->assertTrue($this->pool->validateConnection($connection));
        
        // Disconnect and validate again
        $connection->disconnect();
        $this->assertFalse($this->pool->validateConnection($connection));
    }

    public function testPoolResize(): void
    {
        // Start with 2 min connections
        $this->assertEquals(2, $this->pool->getMinConnections());
        
        // Resize pool
        $this->pool->resize(5, 20);
        
        $this->assertEquals(5, $this->pool->getMinConnections());
        $this->assertEquals(20, $this->pool->getMaxConnections());
        
        // Should have minimum connections available
        $this->assertGreaterThanOrEqual(5, $this->pool->getTotalConnections());
    }

    private function createMockConnection(): ConnectionInterface
    {
        return new class implements ConnectionInterface {
            private string $id;
            private bool $connected = true;
            
            public function __construct()
            {
                $this->id = uniqid('conn_');
            }
            
            public function getId(): string
            {
                return $this->id;
            }
            
            public function connect(): bool
            {
                $this->connected = true;
                return true;
            }
            
            public function disconnect(): void
            {
                $this->connected = false;
            }
            
            public function isConnected(): bool
            {
                return $this->connected;
            }
            
            public function getLastActivity(): float
            {
                return microtime(true);
            }
            
            public function ping(): bool
            {
                return $this->connected;
            }
        };
    }

    private function runConcurrent(array $tasks): array
    {
        $results = [];
        foreach ($tasks as $task) {
            $results[] = $task();
        }
        return $results;
    }
}