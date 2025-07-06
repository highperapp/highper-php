<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Foundation;

use HighPerApp\HighPer\Contracts\ApplicationInterface;
use HighPerApp\HighPer\Contracts\ContainerInterface;
use HighPerApp\HighPer\Contracts\RouterInterface;
use HighPerApp\HighPer\Contracts\RouteMatchInterface;
use HighPerApp\HighPer\Contracts\ConfigManagerInterface;
use HighPerApp\HighPer\Contracts\LoggerInterface;
use HighPerApp\HighPer\Contracts\ServiceProviderInterface;
use HighPerApp\HighPer\ServiceProvider\PackageDiscovery;
use HighPerApp\HighPer\Bootstrap\ApplicationBootstrap;
use HighPerApp\HighPer\Bootstrap\ServerBootstrap;
use Revolt\EventLoop;

/**
 * High-Performance Application
 * 
 * Core application class implementing interface-driven architecture.
 * NO abstract classes, NO final keywords - everything extendable.
 * Optimized for C10M concurrency with external foundation packages.
 */
class Application implements ApplicationInterface
{
    private ContainerInterface $container;
    private RouterInterface $router;
    private ConfigManagerInterface $config;
    private LoggerInterface $logger;
    private PackageDiscovery $packageDiscovery;
    
    private array $serviceProviders = [];
    private array $bootedProviders = [];
    private bool $bootstrapped = false;
    private bool $running = false;
    private array $bootstrappers = [];

    public function __construct(array $config = [])
    {
        $this->initializeFoundationComponents($config);
        $this->packageDiscovery = new PackageDiscovery();
        
        // Add default bootstrappers
        $this->addBootstrapper(new ApplicationBootstrap());
        $this->addBootstrapper(new ServerBootstrap());
    }

    public function bootstrap(): void
    {
        if ($this->bootstrapped) {
            return;
        }

        $this->logger->info('Bootstrapping HighPer Framework application');

        // Load environment configuration
        $this->config->loadEnvironment();

        // Auto-discover and register packages
        $this->discoverAndRegisterPackages();

        // Run bootstrap process
        $this->runBootstrappers();

        // Boot all service providers
        $this->bootProviders();

        $this->bootstrapped = true;
        $this->logger->info('Application bootstrap completed', [
            'providers' => count($this->serviceProviders),
            'memory_usage' => memory_get_usage(true)
        ]);
    }

    public function run(): void
    {
        if (!$this->bootstrapped) {
            $this->bootstrap();
        }

        if ($this->running) {
            throw new \RuntimeException('Application is already running');
        }

        $this->running = true;
        $this->logger->info('Starting HighPer Framework application');

        try {
            // Start the event loop for async operations
            EventLoop::run();
        } catch (\Throwable $e) {
            $this->logger->error('Application error', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            throw $e;
        } finally {
            $this->running = false;
        }
    }

    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    public function getRouter(): RouterInterface
    {
        return $this->router;
    }

    public function getConfig(): ConfigManagerInterface
    {
        return $this->config;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    public function register(ServiceProviderInterface $provider): void
    {
        $provider->register();
        $this->serviceProviders[] = $provider;

        $this->logger->debug('Service provider registered', [
            'provider' => get_class($provider)
        ]);
    }

    public function bootProviders(): void
    {
        foreach ($this->serviceProviders as $provider) {
            if (in_array($provider, $this->bootedProviders, true)) {
                continue;
            }

            $provider->boot();
            $this->bootedProviders[] = $provider;

            $this->logger->debug('Service provider booted', [
                'provider' => get_class($provider)
            ]);
        }
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function shutdown(): void
    {
        $this->logger->info('Shutting down HighPer Framework application');

        $this->running = false;

        // Shutdown bootstrappers in reverse order
        foreach (array_reverse($this->bootstrappers) as $bootstrapper) {
            if (method_exists($bootstrapper, 'shutdown')) {
                $bootstrapper->shutdown();
            }
        }

        // Flush logger
        $this->logger->flush();

        $this->logger->info('Application shutdown completed');
    }

    /**
     * Add a bootstrapper
     */
    public function addBootstrapper(object $bootstrapper): void
    {
        $this->bootstrappers[] = $bootstrapper;
    }

    /**
     * Get application statistics
     */
    public function getStats(): array
    {
        return [
            'bootstrapped' => $this->bootstrapped,
            'running' => $this->running,
            'providers_count' => count($this->serviceProviders),
            'booted_providers_count' => count($this->bootedProviders),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'container_stats' => $this->container->getStats(),
            'router_stats' => $this->router->getStats(),
            'logger_stats' => $this->logger->getStats()
        ];
    }

    private function initializeFoundationComponents(array $config): void
    {
        // Initialize external foundation packages
        // These will be actual implementations from external packages
        
        // For now, we'll create placeholder implementations
        // In the real implementation, these would be:
        // $this->container = new \HighPerApp\HighPerContainer\Container();
        // $this->router = new \HighPerApp\HighPerRouter\Router();
        
        $this->container = new class implements ContainerInterface {
            private array $bindings = [];
            private array $instances = [];
            
            public function get(string $id): mixed
            {
                if (isset($this->instances[$id])) {
                    return $this->instances[$id];
                }
                
                if (isset($this->bindings[$id])) {
                    $concrete = $this->bindings[$id];
                    $instance = is_callable($concrete) ? $concrete() : new $concrete();
                    $this->instances[$id] = $instance;
                    return $instance;
                }
                
                throw new \Psr\Container\NotFoundExceptionInterface("Service {$id} not found");
            }
            
            public function has(string $id): bool
            {
                return isset($this->bindings[$id]) || isset($this->instances[$id]);
            }
            
            public function bind(string $id, mixed $concrete): void
            {
                $this->bindings[$id] = $concrete;
            }
            
            public function singleton(string $id, mixed $concrete): void
            {
                $this->bind($id, $concrete);
            }
            
            public function factory(string $id, callable $factory): void
            {
                $this->bind($id, $factory);
            }
            
            public function instance(string $id, object $instance): void
            {
                $this->instances[$id] = $instance;
            }
            
            public function alias(string $alias, string $id): void
            {
                $this->bindings[$alias] = fn() => $this->get($id);
            }
            
            public function bound(string $id): bool
            {
                return $this->has($id);
            }
            
            public function remove(string $id): void
            {
                unset($this->bindings[$id], $this->instances[$id]);
            }
            
            public function getStats(): array
            {
                return [
                    'bindings_count' => count($this->bindings),
                    'instances_count' => count($this->instances)
                ];
            }
        };

        $this->config = new ConfigManager($config);
        $this->logger = new AsyncLogger($this->config);
        
        // Placeholder router - will be replaced with external package
        $this->router = new class implements RouterInterface {
            private array $routes = [];
            
            public function addRoute(string $method, string $path, mixed $handler): void
            {
                $this->routes["{$method}:{$path}"] = $handler;
            }
            
            public function addRoutes(array $routes): void
            {
                foreach ($routes as $route) {
                    $this->addRoute($route['method'], $route['path'], $route['handler']);
                }
            }
            
            public function match(string $method, string $path): ?RouteMatchInterface
            {
                $key = "{$method}:{$path}";
                return isset($this->routes[$key]) ? new class($this->routes[$key], $path, $method) implements RouteMatchInterface {
                    public function __construct(
                        private mixed $handler,
                        private string $path,
                        private string $method
                    ) {}
                    
                    public function getHandler(): mixed { return $this->handler; }
                    public function getParameters(): array { return []; }
                    public function getParameter(string $name, mixed $default = null): mixed { return $default; }
                    public function hasParameter(string $name): bool { return false; }
                    public function getPath(): string { return $this->path; }
                    public function getMethod(): string { return $this->method; }
                    public function getAttributes(): array { return []; }
                } : null;
            }
            
            public function getRoutes(): array { return $this->routes; }
            public function clearCache(): void { /* no-op */ }
            public function getStats(): array { return ['routes_count' => count($this->routes)]; }
            public function setCacheOptions(array $options): void { /* no-op */ }
        };

        // Register core services in container
        $this->container->instance(ContainerInterface::class, $this->container);
        $this->container->instance(ApplicationInterface::class, $this);
        $this->container->instance(ConfigManagerInterface::class, $this->config);
        $this->container->instance(LoggerInterface::class, $this->logger);
        $this->container->instance(RouterInterface::class, $this->router);
    }

    private function discoverAndRegisterPackages(): void
    {
        $packageConfig = $this->config->get('packages', []);
        $autoDiscover = $packageConfig['auto_discover'] ?? true;

        if ($autoDiscover) {
            $installedPackages = $this->packageDiscovery->discoverInstalledPackages();
            $providers = $this->packageDiscovery->loadServiceProviders($installedPackages, $this);

            foreach ($providers as $provider) {
                $this->register($provider);
            }
        }
    }

    private function runBootstrappers(): void
    {
        // Sort bootstrappers by priority
        usort($this->bootstrappers, function($a, $b) {
            $priorityA = method_exists($a, 'getPriority') ? $a->getPriority() : 0;
            $priorityB = method_exists($b, 'getPriority') ? $b->getPriority() : 0;
            return $priorityA <=> $priorityB;
        });

        foreach ($this->bootstrappers as $bootstrapper) {
            if (method_exists($bootstrapper, 'canBootstrap') && !$bootstrapper->canBootstrap($this)) {
                continue;
            }

            if (method_exists($bootstrapper, 'bootstrap')) {
                $bootstrapper->bootstrap($this);
            }
        }
    }
}