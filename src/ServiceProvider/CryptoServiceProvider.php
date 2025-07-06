<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\ServiceProvider;

use HighPerApp\HighPer\Contracts\ServiceProviderInterface;
use HighPerApp\HighPer\Contracts\ContainerInterface;
use HighPerApp\HighPer\Contracts\LoggerInterface;

/**
 * Crypto Service Provider for HighPer Framework
 * 
 * Integrates the standalone highperapp/crypto library into HighPer Framework
 * while maintaining the framework's minimal footprint principle.
 * 
 * Features:
 * - Lazy loading of crypto services
 * - Environment-based configuration
 * - Optional dependency (graceful degradation if not installed)
 * - Service registration without forcing usage
 */
class CryptoServiceProvider implements ServiceProviderInterface
{
    private array $config = [];
    private bool $cryptoAvailable = false;
    private array $registeredServices = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'auto_detect' => true,
            'enable_rust_ffi' => true,
            'enable_fallback' => true,
            'lazy_loading' => true,
            'performance_monitoring' => true
        ], $config);

        $this->detectCryptoAvailability();
    }

    private ?ContainerInterface $container = null;

    public function setContainer(ContainerInterface $container): void
    {
        $this->container = $container;
    }

    public function register(): void
    {
        if ($this->container === null) {
            throw new \RuntimeException('Container must be set before calling register()');
        }

        // Only register if crypto library is available
        if (!$this->cryptoAvailable) {
            if ($this->config['auto_detect']) {
                $this->registerNullCryptoServices($this->container);
            }
            return;
        }

        $this->registerCryptoServices($this->container);
        $this->registerAuthServices($this->container);
        $this->registerVaultIntegration($this->container);
        $this->registerPerformanceMonitoring($this->container);
    }

    public function boot(): void
    {
        if ($this->container === null) {
            throw new \RuntimeException('Container must be set before calling boot()');
        }

        if (!$this->cryptoAvailable) {
            $logger = $this->container->has(LoggerInterface::class) 
                ? $this->container->get(LoggerInterface::class) 
                : null;
            
            $logger?->info('HighPer Crypto services not available', [
                'reason' => 'highperapp/crypto package not installed',
                'suggestion' => 'Run: composer require highperapp/crypto'
            ]);
            return;
        }

        // Initialize crypto engine with environment configuration
        $this->initializeCryptoEngine($this->container);
        
        // Register middleware if HTTP server is available
        $this->registerCryptoMiddleware($this->container);
    }

    private function detectCryptoAvailability(): void
    {
        // Check if highperapp/crypto is installed
        $this->cryptoAvailable = class_exists('\\HighPerApp\\HighPer\\Crypto\\Core\\CryptographyManager');
        
        if ($this->cryptoAvailable) {
            $this->registeredServices[] = 'crypto_core';
        }
    }

    private function registerCryptoServices(ContainerInterface $container): void
    {
        // Core Cryptography Manager (lazy loaded)
        $container->singleton('crypto.manager', function() {
            $config = $this->getCryptoConfiguration();
            return new \HighPerApp\HighPer\Crypto\Core\CryptographyManager($config);
        });

        // Rust FFI Engine (lazy loaded)
        $container->singleton('crypto.rust_engine', function() {
            $config = $this->getRustFFIConfiguration();
            $logger = $this->getLogger();
            return new \HighPerApp\HighPer\Crypto\Engines\RustFFIEngine($config, $logger);
        });

        // FIPS Compliant Crypto (lazy loaded)
        $container->singleton('crypto.fips', function() {
            $config = $this->getFipsConfiguration();
            return new \HighPerApp\HighPer\Crypto\Compliance\FipsCompliantCryptography($config);
        });

        $this->registeredServices[] = 'crypto_services';
    }

    private function registerAuthServices(ContainerInterface $container): void
    {
        // Token Authentication Manager (lazy loaded)
        $container->singleton('auth.token_manager', function() {
            $config = $this->getTokenConfiguration();
            return new \HighPerApp\HighPer\Crypto\Auth\TokenAuthManager($config);
        });

        // JWT Provider (lazy loaded)
        $container->singleton('auth.jwt_provider', function() {
            $config = $this->getJWTConfiguration();
            return new \HighPerApp\HighPer\Crypto\Auth\Providers\JWTProvider($config);
        });

        // PASETO Provider (lazy loaded)
        $container->singleton('auth.paseto_provider', function() {
            $config = $this->getPasetoConfiguration();
            return new \HighPerApp\HighPer\Crypto\Auth\Providers\PasetoProvider($config);
        });

        // OAuth Provider (lazy loaded)
        $container->singleton('auth.oauth_provider', function() {
            $config = $this->getOAuthConfiguration();
            return new \HighPerApp\HighPer\Crypto\Auth\Providers\OAuthProvider($config);
        });

        $this->registeredServices[] = 'auth_services';
    }

    private function registerVaultIntegration(ContainerInterface $container): void
    {
        // Only register if Vault configuration is present
        if (!$this->isVaultConfigured()) {
            return;
        }

        $container->singleton('crypto.vault', function() {
            $config = $this->getVaultConfiguration();
            return new \HighPerApp\HighPer\Crypto\Vault\HashicorpVaultIntegration($config);
        });

        $this->registeredServices[] = 'vault_integration';
    }

    private function registerPerformanceMonitoring(ContainerInterface $container): void
    {
        if (!$this->config['performance_monitoring']) {
            return;
        }

        $container->singleton('crypto.performance_monitor', function() {
            return new class {
                private array $metrics = [];

                public function startTimer(string $operation): string
                {
                    $id = uniqid();
                    $this->metrics[$id] = [
                        'operation' => $operation,
                        'start_time' => microtime(true),
                        'memory_start' => memory_get_usage(true)
                    ];
                    return $id;
                }

                public function endTimer(string $id): array
                {
                    if (!isset($this->metrics[$id])) {
                        return [];
                    }

                    $metric = $this->metrics[$id];
                    $metric['end_time'] = microtime(true);
                    $metric['memory_end'] = memory_get_usage(true);
                    $metric['duration_ms'] = round(($metric['end_time'] - $metric['start_time']) * 1000, 3);
                    $metric['memory_used'] = $metric['memory_end'] - $metric['memory_start'];

                    unset($this->metrics[$id]);
                    return $metric;
                }

                public function getMetrics(): array
                {
                    return $this->metrics;
                }
            };
        });

        $this->registeredServices[] = 'performance_monitoring';
    }

    private function registerNullCryptoServices(ContainerInterface $container): void
    {
        // Register null object pattern services for graceful degradation
        $nullCrypto = new class {
            public function __call($method, $args) {
                throw new \RuntimeException(
                    'Crypto operations not available. Install highperapp/crypto package.'
                );
            }
        };

        $container->singleton('crypto.manager', fn() => $nullCrypto);
        $container->singleton('crypto.rust_engine', fn() => $nullCrypto);
        $container->singleton('auth.token_manager', fn() => $nullCrypto);

        $this->registeredServices[] = 'null_crypto_services';
    }

    private function initializeCryptoEngine(ContainerInterface $container): void
    {
        if (!$this->config['enable_rust_ffi']) {
            return;
        }

        // Initialize Rust FFI engine if available
        if ($container->has('crypto.rust_engine')) {
            try {
                $engine = $container->get('crypto.rust_engine');
                if (method_exists($engine, 'isAvailable') && $engine->isAvailable()) {
                    $logger = $this->getLogger();
                    $logger?->info('Rust FFI crypto engine initialized successfully');
                }
            } catch (\Throwable $e) {
                $logger = $this->getLogger();
                $logger?->warning('Rust FFI crypto engine initialization failed', [
                    'error' => $e->getMessage(),
                    'fallback' => 'Using PHP implementations'
                ]);
            }
        }
    }

    private function registerCryptoMiddleware(ContainerInterface $container): void
    {
        // Register crypto-related middleware if HTTP server is available
        if (!$container->has('http.server')) {
            return;
        }

        // JWT Authentication Middleware
        $container->singleton('middleware.jwt_auth', function() use ($container) {
            return new class($container) {
                private ContainerInterface $container;

                public function __construct(ContainerInterface $container)
                {
                    $this->container = $container;
                }

                public function __invoke($request, $handler)
                {
                    // JWT authentication middleware implementation
                    $tokenManager = $this->container->get('auth.token_manager');
                    
                    $authHeader = $request->getHeader('authorization')[0] ?? '';
                    if (!str_starts_with($authHeader, 'Bearer ')) {
                        return $handler($request);
                    }

                    $token = substr($authHeader, 7);
                    try {
                        $payload = $tokenManager->verifyToken($token);
                        $request = $request->withAttribute('auth_payload', $payload);
                    } catch (\Throwable $e) {
                        // Invalid token - continue without authentication
                    }

                    return $handler($request);
                }
            };
        });

        $this->registeredServices[] = 'crypto_middleware';
    }

    private function getCryptoConfiguration(): array
    {
        return [
            'rust_ffi_enabled' => (bool) ($_ENV['HIGHPER_CRYPTO_RUST_FFI'] ?? $this->config['enable_rust_ffi']),
            'fallback_enabled' => (bool) ($_ENV['HIGHPER_CRYPTO_FALLBACK'] ?? $this->config['enable_fallback']),
            'performance_monitoring' => $this->config['performance_monitoring'],
            'memory_limit' => (int) ($_ENV['HIGHPER_CRYPTO_MEMORY_LIMIT'] ?? 2 * 1024 * 1024),
            'library_path' => $_ENV['HIGHPER_CRYPTO_RUST_PATH'] ?? null
        ];
    }

    private function getRustFFIConfiguration(): array
    {
        return [
            'library_path' => $_ENV['HIGHPER_CRYPTO_RUST_PATH'] ?? null,
            'memory_limit' => (int) ($_ENV['HIGHPER_CRYPTO_MEMORY_LIMIT'] ?? 2 * 1024 * 1024),
            'enable_debug' => (bool) ($_ENV['HIGHPER_CRYPTO_DEBUG'] ?? false)
        ];
    }

    private function getFipsConfiguration(): array
    {
        return [
            'fips_enabled' => (bool) ($_ENV['HIGHPER_CRYPTO_FIPS'] ?? false),
            'approved_algorithms_only' => (bool) ($_ENV['HIGHPER_CRYPTO_FIPS_STRICT'] ?? false),
            'audit_logging' => (bool) ($_ENV['HIGHPER_CRYPTO_AUDIT'] ?? false)
        ];
    }

    private function getTokenConfiguration(): array
    {
        return [
            'provider' => $_ENV['HIGHPER_TOKEN_PROVIDER'] ?? 'jwt',
            'expires_in' => (int) ($_ENV['HIGHPER_TOKEN_EXPIRES_IN'] ?? 3600),
            'jwt' => $this->getJWTConfiguration(),
            'paseto' => $this->getPasetoConfiguration(),
            'oauth' => $this->getOAuthConfiguration()
        ];
    }

    private function getJWTConfiguration(): array
    {
        return [
            'enabled' => (bool) ($_ENV['HIGHPER_JWT_ENABLED'] ?? true),
            'secret' => $_ENV['HIGHPER_JWT_SECRET'] ?? '',
            'algorithm' => $_ENV['HIGHPER_JWT_ALGORITHM'] ?? 'HS256',
            'issuer' => $_ENV['HIGHPER_JWT_ISSUER'] ?? 'highper-framework',
            'audience' => $_ENV['HIGHPER_JWT_AUDIENCE'] ?? 'highper-users',
            'leeway' => (int) ($_ENV['HIGHPER_JWT_LEEWAY'] ?? 60)
        ];
    }

    private function getPasetoConfiguration(): array
    {
        return [
            'enabled' => (bool) ($_ENV['HIGHPER_PASETO_ENABLED'] ?? false),
            'version' => $_ENV['HIGHPER_PASETO_VERSION'] ?? 'v4',
            'purpose' => $_ENV['HIGHPER_PASETO_PURPOSE'] ?? 'local',
            'key' => $_ENV['HIGHPER_PASETO_KEY'] ?? '',
            'issuer' => $_ENV['HIGHPER_PASETO_ISSUER'] ?? 'highper-framework'
        ];
    }

    private function getOAuthConfiguration(): array
    {
        return [
            'enabled' => (bool) ($_ENV['HIGHPER_OAUTH_ENABLED'] ?? false),
            'client_id' => $_ENV['HIGHPER_OAUTH_CLIENT_ID'] ?? '',
            'client_secret' => $_ENV['HIGHPER_OAUTH_CLIENT_SECRET'] ?? '',
            'redirect_uri' => $_ENV['HIGHPER_OAUTH_REDIRECT_URI'] ?? '',
            'authorization_endpoint' => $_ENV['HIGHPER_OAUTH_AUTH_ENDPOINT'] ?? '',
            'token_endpoint' => $_ENV['HIGHPER_OAUTH_TOKEN_ENDPOINT'] ?? '',
            'scopes' => explode(',', $_ENV['HIGHPER_OAUTH_SCOPES'] ?? 'read'),
            'pkce_enabled' => (bool) ($_ENV['HIGHPER_OAUTH_PKCE_ENABLED'] ?? true)
        ];
    }

    private function getVaultConfiguration(): array
    {
        return [
            'enabled' => (bool) ($_ENV['HIGHPER_VAULT_ENABLED'] ?? false),
            'address' => $_ENV['HIGHPER_VAULT_ADDRESS'] ?? '',
            'token' => $_ENV['HIGHPER_VAULT_TOKEN'] ?? '',
            'namespace' => $_ENV['HIGHPER_VAULT_NAMESPACE'] ?? '',
            'mount_path' => $_ENV['HIGHPER_VAULT_MOUNT_PATH'] ?? 'secret',
            'auth_method' => $_ENV['HIGHPER_VAULT_AUTH_METHOD'] ?? 'token'
        ];
    }

    private function isVaultConfigured(): bool
    {
        return !empty($_ENV['HIGHPER_VAULT_ADDRESS']) && 
               !empty($_ENV['HIGHPER_VAULT_TOKEN']);
    }

    private function getLogger(): ?LoggerInterface
    {
        // Try to get logger from environment or return null
        return null; // Implement logger resolution if needed
    }

    public function provides(): array
    {
        $services = [
            'crypto.manager',
            'crypto.rust_engine',
            'auth.token_manager'
        ];

        if ($this->cryptoAvailable) {
            $services = array_merge($services, [
                'crypto.fips',
                'auth.jwt_provider',
                'auth.paseto_provider',
                'auth.oauth_provider',
                'middleware.jwt_auth'
            ]);

            if ($this->isVaultConfigured()) {
                $services[] = 'crypto.vault';
            }

            if ($this->config['performance_monitoring']) {
                $services[] = 'crypto.performance_monitor';
            }
        }

        return $services;
    }

    public function isRegistered(): bool
    {
        return !empty($this->registeredServices);
    }

    public function getRegisteredServices(): array
    {
        return $this->registeredServices;
    }

    public function isCryptoAvailable(): bool
    {
        return $this->cryptoAvailable;
    }

    public function getConfiguration(): array
    {
        return [
            'crypto_available' => $this->cryptoAvailable,
            'registered_services' => $this->registeredServices,
            'configuration' => $this->config
        ];
    }
}