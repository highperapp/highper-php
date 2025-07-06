<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\ServiceProvider;

use HighPerApp\HighPer\Contracts\ServiceProviderInterface;
use HighPerApp\HighPer\Contracts\ContainerInterface;
use HighPerApp\HighPer\Contracts\LoggerInterface;

/**
 * Validator Service Provider for HighPer Framework
 * 
 * Integrates the standalone highperapp/validator library into HighPer Framework
 * providing high-performance data validation with Rust FFI acceleration.
 * 
 * Features from Validator Library:
 * - Rust FFI acceleration for ultra-fast validation performance
 * - Comprehensive validation types (email, URL, credit card, phone, IP)
 * - Batch validation with parallel processing
 * - Regex caching for performance optimization
 * - Memory-safe operations with transparent fallbacks
 */
class ValidatorServiceProvider implements ServiceProviderInterface
{
    private array $config = [];
    private bool $validatorAvailable = false;
    private array $registeredServices = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'auto_detect' => true,
            'enable_rust_ffi' => true,
            'enable_parallel' => true,
            'enable_caching' => true,
            'lazy_loading' => true,
            'batch_size' => 1000,
            'cache_size' => 10000
        ], $config);

        $this->detectValidatorAvailability();
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

        if (!$this->validatorAvailable) {
            if ($this->config['auto_detect']) {
                $this->registerNullValidatorServices($this->container);
            }
            return;
        }

        $this->registerCoreValidator($this->container);
        $this->registerValidationRules($this->container);
        $this->registerParallelValidator($this->container);
        $this->registerValidationMiddleware($this->container);
    }

    public function boot(): void
    {
        if ($this->container === null) {
            throw new \RuntimeException('Container must be set before calling boot()');
        }

        if (!$this->validatorAvailable) {
            $logger = $this->getLogger($this->container);
            $logger?->info('HighPer Validator services not available', [
                'reason' => 'highperapp/validator package not installed',
                'suggestion' => 'Run: composer require highperapp/validator'
            ]);
            return;
        }

        $this->initializeRustFFI($this->container);
        $this->warmupValidationCache($this->container);
        $this->registerValidationAliases($this->container);
    }

    private function detectValidatorAvailability(): void
    {
        // Check if highperapp/validator is installed
        $this->validatorAvailable = class_exists('\\HighPerApp\\HighPer\\Validator\\ValidatorManager');
        
        if ($this->validatorAvailable) {
            $this->registeredServices[] = 'validator_core';
        }
    }

    private function registerCoreValidator(ContainerInterface $container): void
    {
        // Core Validator Manager
        $container->singleton('validator.manager', function() use ($container) {
            $config = $this->getValidatorConfiguration();
            $logger = $this->getLogger($container);
            
            return new \HighPerApp\HighPer\Validator\ValidatorManager($config, $logger);
        });

        // Rust FFI Validator Engine
        $container->singleton('validator.rust_engine', function() use ($container) {
            $config = $this->getRustFFIConfiguration();
            $logger = $this->getLogger($container);
            
            return new \HighPerApp\HighPer\Validator\Engines\RustFFIValidator($config, $logger);
        });

        // PHP Fallback Validator
        $container->singleton('validator.php_engine', function() {
            $config = $this->getPHPValidatorConfiguration();
            
            return new \HighPerApp\HighPer\Validator\Engines\PHPValidator($config);
        });

        // Validation Factory
        $container->singleton('validator.factory', function() use ($container) {
            $manager = $container->get('validator.manager');
            
            return new \HighPerApp\HighPer\Validator\ValidationFactory($manager);
        });

        $this->registeredServices[] = 'core_validator';
    }

    private function registerValidationRules(ContainerInterface $container): void
    {
        // Email Validator
        $container->singleton('validator.email', function() use ($container) {
            $rustEngine = $container->get('validator.rust_engine');
            $phpEngine = $container->get('validator.php_engine');
            
            return new \HighPerApp\HighPer\Validator\Rules\EmailValidator($rustEngine, $phpEngine);
        });

        // URL Validator
        $container->singleton('validator.url', function() use ($container) {
            $rustEngine = $container->get('validator.rust_engine');
            $phpEngine = $container->get('validator.php_engine');
            
            return new \HighPerApp\HighPer\Validator\Rules\UrlValidator($rustEngine, $phpEngine);
        });

        // Credit Card Validator
        $container->singleton('validator.credit_card', function() use ($container) {
            $rustEngine = $container->get('validator.rust_engine');
            $phpEngine = $container->get('validator.php_engine');
            
            return new \HighPerApp\HighPer\Validator\Rules\CreditCardValidator($rustEngine, $phpEngine);
        });

        // Phone Number Validator
        $container->singleton('validator.phone', function() use ($container) {
            $rustEngine = $container->get('validator.rust_engine');
            $phpEngine = $container->get('validator.php_engine');
            
            return new \HighPerApp\HighPer\Validator\Rules\PhoneValidator($rustEngine, $phpEngine);
        });

        // IP Address Validator
        $container->singleton('validator.ip', function() use ($container) {
            $rustEngine = $container->get('validator.rust_engine');
            $phpEngine = $container->get('validator.php_engine');
            
            return new \HighPerApp\HighPer\Validator\Rules\IpValidator($rustEngine, $phpEngine);
        });

        // JSON Validator
        $container->singleton('validator.json', function() use ($container) {
            $rustEngine = $container->get('validator.rust_engine');
            $phpEngine = $container->get('validator.php_engine');
            
            return new \HighPerApp\HighPer\Validator\Rules\JsonValidator($rustEngine, $phpEngine);
        });

        // Regex Validator
        $container->singleton('validator.regex', function() use ($container) {
            $rustEngine = $container->get('validator.rust_engine');
            $phpEngine = $container->get('validator.php_engine');
            $config = $this->getRegexValidatorConfiguration();
            
            return new \HighPerApp\HighPer\Validator\Rules\RegexValidator($rustEngine, $phpEngine, $config);
        });

        $this->registeredServices[] = 'validation_rules';
    }

    private function registerParallelValidator(ContainerInterface $container): void
    {
        if (!$this->config['enable_parallel']) {
            return;
        }

        // Parallel Validator
        $container->singleton('validator.parallel', function() use ($container) {
            $manager = $container->get('validator.manager');
            $config = $this->getParallelValidatorConfiguration();
            
            return new \HighPerApp\HighPer\Validator\Parallel\ParallelValidator($manager, $config);
        });

        // Batch Validator
        $container->singleton('validator.batch', function() use ($container) {
            $parallelValidator = $container->get('validator.parallel');
            $config = $this->getBatchValidatorConfiguration();
            
            return new \HighPerApp\HighPer\Validator\Batch\BatchValidator($parallelValidator, $config);
        });

        $this->registeredServices[] = 'parallel_validation';
    }

    private function registerValidationMiddleware(ContainerInterface $container): void
    {
        if (!$container->has('http.server')) {
            return;
        }

        // Request Validation Middleware
        $container->singleton('middleware.validate_request', function() use ($container) {
            return new class($container) {
                private ContainerInterface $container;

                public function __construct(ContainerInterface $container)
                {
                    $this->container = $container;
                }

                public function __invoke($request, $handler)
                {
                    $validator = $this->container->get('validator.factory');
                    
                    // Validate common request components
                    $validationRules = $this->getValidationRules($request);
                    
                    if (!empty($validationRules)) {
                        $validator = $validator->make($this->extractRequestData($request), $validationRules);
                        
                        if ($validator->fails()) {
                            return new \Amp\Http\Server\Response(
                                400,
                                ['Content-Type' => 'application/json'],
                                json_encode([
                                    'error' => 'Validation failed',
                                    'errors' => $validator->errors()
                                ])
                            );
                        }
                    }

                    return $handler($request);
                }

                private function getValidationRules($request): array
                {
                    // Extract validation rules from request attributes or route
                    return $request->getAttribute('validation_rules', []);
                }

                private function extractRequestData($request): array
                {
                    $data = [];
                    
                    // Extract query parameters
                    parse_str($request->getUri()->getQuery(), $data);
                    
                    // Extract form data if available
                    $body = $request->getBody()->buffer();
                    if ($body && str_contains($request->getHeader('content-type')[0] ?? '', 'application/x-www-form-urlencoded')) {
                        parse_str($body, $formData);
                        $data = array_merge($data, $formData);
                    }
                    
                    return $data;
                }
            };
        });

        // JSON Validation Middleware
        $container->singleton('middleware.validate_json', function() use ($container) {
            return new class($container) {
                private ContainerInterface $container;

                public function __construct(ContainerInterface $container)
                {
                    $this->container = $container;
                }

                public function __invoke($request, $handler)
                {
                    $contentType = $request->getHeader('content-type')[0] ?? '';
                    
                    if (str_contains($contentType, 'application/json')) {
                        $jsonValidator = $this->container->get('validator.json');
                        $body = $request->getBody()->buffer();
                        
                        if (!$jsonValidator->validate($body)) {
                            return new \Amp\Http\Server\Response(
                                400,
                                ['Content-Type' => 'application/json'],
                                json_encode(['error' => 'Invalid JSON payload'])
                            );
                        }
                    }

                    return $handler($request);
                }
            };
        });

        $this->registeredServices[] = 'validation_middleware';
    }

    private function registerNullValidatorServices(ContainerInterface $container): void
    {
        $nullValidator = new class {
            public function validate($data, array $rules = []): bool
            {
                return true; // Always pass when validator not available
            }

            public function make($data, array $rules): object
            {
                return new class {
                    public function fails(): bool { return false; }
                    public function errors(): array { return []; }
                };
            }

            public function __call($method, $args)
            {
                throw new \RuntimeException(
                    'Validation operations not available. Install highperapp/validator package.'
                );
            }
        };

        $container->singleton('validator.manager', fn() => $nullValidator);
        $container->singleton('validator.factory', fn() => $nullValidator);

        $this->registeredServices[] = 'null_validator_services';
    }

    private function initializeRustFFI(ContainerInterface $container): void
    {
        if (!$this->config['enable_rust_ffi']) {
            return;
        }

        try {
            $rustEngine = $container->get('validator.rust_engine');
            if (method_exists($rustEngine, 'isAvailable') && $rustEngine->isAvailable()) {
                $logger = $this->getLogger($container);
                $logger?->info('Rust FFI validator engine initialized successfully');
            }
        } catch (\Throwable $e) {
            $logger = $this->getLogger($container);
            $logger?->warning('Rust FFI validator engine initialization failed', [
                'error' => $e->getMessage(),
                'fallback' => 'Using PHP implementations'
            ]);
        }
    }

    private function warmupValidationCache(ContainerInterface $container): void
    {
        if (!$this->config['enable_caching']) {
            return;
        }

        try {
            // Warmup common validation patterns
            $regexValidator = $container->get('validator.regex');
            
            $commonPatterns = [
                'email' => '/^[^\s@]+@[^\s@]+\.[^\s@]+$/',
                'url' => '/^https?:\/\/[^\s\/$.?#].[^\s]*$/',
                'phone' => '/^\+?[1-9]\d{1,14}$/'
            ];

            foreach ($commonPatterns as $name => $pattern) {
                $regexValidator->warmupPattern($name, $pattern);
            }

            $logger = $this->getLogger($container);
            $logger?->debug('Validation cache warmed up', [
                'patterns' => count($commonPatterns)
            ]);

        } catch (\Throwable $e) {
            $logger = $this->getLogger($container);
            $logger?->warning('Failed to warmup validation cache', [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function registerValidationAliases(ContainerInterface $container): void
    {
        // Create convenient aliases for common validators
        $container->alias('validator.manager', 'validator');
        $container->alias('validator.factory', 'validation');
    }

    private function getValidatorConfiguration(): array
    {
        return [
            'rust_ffi_enabled' => (bool) ($_ENV['VALIDATOR_RUST_FFI'] ?? $this->config['enable_rust_ffi']),
            'parallel_enabled' => (bool) ($_ENV['VALIDATOR_PARALLEL'] ?? $this->config['enable_parallel']),
            'cache_enabled' => (bool) ($_ENV['VALIDATOR_CACHE'] ?? $this->config['enable_caching']),
            'cache_size' => (int) ($_ENV['VALIDATOR_CACHE_SIZE'] ?? $this->config['cache_size']),
            'batch_size' => (int) ($_ENV['VALIDATOR_BATCH_SIZE'] ?? $this->config['batch_size']),
            'performance_monitoring' => (bool) ($_ENV['VALIDATOR_MONITORING'] ?? true)
        ];
    }

    private function getRustFFIConfiguration(): array
    {
        return [
            'library_path' => $_ENV['VALIDATOR_RUST_PATH'] ?? null,
            'memory_limit' => (int) ($_ENV['VALIDATOR_MEMORY_LIMIT'] ?? 1024 * 1024), // 1MB
            'enable_debug' => (bool) ($_ENV['VALIDATOR_DEBUG'] ?? false),
            'simd_enabled' => (bool) ($_ENV['VALIDATOR_SIMD'] ?? true)
        ];
    }

    private function getPHPValidatorConfiguration(): array
    {
        return [
            'enable_regex_cache' => (bool) ($_ENV['VALIDATOR_PHP_CACHE'] ?? true),
            'max_regex_cache' => (int) ($_ENV['VALIDATOR_PHP_CACHE_SIZE'] ?? 1000),
            'strict_mode' => (bool) ($_ENV['VALIDATOR_STRICT'] ?? false)
        ];
    }

    private function getParallelValidatorConfiguration(): array
    {
        return [
            'max_workers' => (int) ($_ENV['VALIDATOR_PARALLEL_WORKERS'] ?? 4),
            'chunk_size' => (int) ($_ENV['VALIDATOR_PARALLEL_CHUNK_SIZE'] ?? 100),
            'timeout' => (int) ($_ENV['VALIDATOR_PARALLEL_TIMEOUT'] ?? 30)
        ];
    }

    private function getBatchValidatorConfiguration(): array
    {
        return [
            'max_batch_size' => (int) ($_ENV['VALIDATOR_BATCH_MAX_SIZE'] ?? $this->config['batch_size']),
            'auto_flush' => (bool) ($_ENV['VALIDATOR_BATCH_AUTO_FLUSH'] ?? true),
            'flush_interval' => (int) ($_ENV['VALIDATOR_BATCH_FLUSH_INTERVAL'] ?? 5)
        ];
    }

    private function getRegexValidatorConfiguration(): array
    {
        return [
            'cache_enabled' => $this->config['enable_caching'],
            'cache_size' => (int) ($_ENV['VALIDATOR_REGEX_CACHE_SIZE'] ?? 500),
            'compile_patterns' => (bool) ($_ENV['VALIDATOR_REGEX_COMPILE'] ?? true)
        ];
    }

    private function getLogger(ContainerInterface $container): ?LoggerInterface
    {
        return $container->has(LoggerInterface::class) 
            ? $container->get(LoggerInterface::class) 
            : null;
    }

    public function provides(): array
    {
        $services = [
            'validator',
            'validation',
            'validator.manager',
            'validator.factory'
        ];

        if ($this->validatorAvailable) {
            $services = array_merge($services, [
                'validator.rust_engine',
                'validator.php_engine',
                'validator.email',
                'validator.url',
                'validator.credit_card',
                'validator.phone',
                'validator.ip',
                'validator.json',
                'validator.regex',
                'middleware.validate_request',
                'middleware.validate_json'
            ]);

            if ($this->config['enable_parallel']) {
                $services = array_merge($services, [
                    'validator.parallel',
                    'validator.batch'
                ]);
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

    public function isValidatorAvailable(): bool
    {
        return $this->validatorAvailable;
    }

    public function getConfiguration(): array
    {
        return [
            'validator_available' => $this->validatorAvailable,
            'registered_services' => $this->registeredServices,
            'configuration' => $this->config
        ];
    }
}