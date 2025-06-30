<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\ServiceProvider;

use HighPerApp\HighPer\Contracts\ServiceProviderInterface;
use HighPerApp\HighPer\Contracts\ApplicationInterface;

/**
 * Library Loader - Conditional ServiceProvider Integration
 * 
 * Dynamically loads WebSockets, TCP, CLI and other libraries
 * based on availability and configuration requirements.
 * 
 * Total: ~45 LOC as per project plan
 */
class LibraryLoader
{
    private array $availableProviders = [];
    private array $loadedProviders = [];
    private ApplicationInterface $app;

    public function __construct(ApplicationInterface $app)
    {
        $this->app = $app;
        $this->discoverProviders();
    }

    private function discoverProviders(): void
    {
        $providers = [
            'websockets' => 'HighPerApp\\HighPer\\WebSockets\\WebSocketServiceProvider',
            'tcp' => 'HighPerApp\\HighPer\\TCP\\TCPServiceProvider', 
            'cli' => 'HighPerApp\\HighPer\\CLI\\CLIServiceProvider',
            'database' => 'HighPerApp\\HighPer\\Database\\DatabaseServiceProvider',
            'cache' => 'HighPerApp\\HighPer\\Cache\\CacheServiceProvider',
            'security' => 'HighPerApp\\HighPer\\Security\\SecurityServiceProvider'
        ];

        foreach ($providers as $library => $providerClass) {
            if (class_exists($providerClass)) {
                $this->availableProviders[$library] = $providerClass;
            }
        }
    }

    public function loadConditionally(array $requiredLibraries = []): void
    {
        foreach ($requiredLibraries as $library) {
            $this->loadProvider($library);
        }
        
        // Always load essential providers
        $this->loadProvider('cli'); // Essential for commands
    }

    public function loadProvider(string $library): bool
    {
        if (isset($this->loadedProviders[$library])) {
            return true;
        }
        
        if (!isset($this->availableProviders[$library])) {
            return false;
        }
        
        $providerClass = $this->availableProviders[$library];
        
        try {
            $provider = new $providerClass();
            
            if ($provider instanceof ServiceProviderInterface) {
                $provider->register($this->app);
                $this->loadedProviders[$library] = $provider;
                return true;
            }
        } catch (\Exception $e) {
            error_log("Failed to load provider {$library}: " . $e->getMessage());
        }
        
        return false;
    }

    public function getAvailableProviders(): array { return array_keys($this->availableProviders); }
    public function getLoadedProviders(): array { return array_keys($this->loadedProviders); }
    public function isProviderLoaded(string $library): bool { return isset($this->loadedProviders[$library]); }
    public function getStats(): array { return ['available' => count($this->availableProviders), 'loaded' => count($this->loadedProviders)]; }
}