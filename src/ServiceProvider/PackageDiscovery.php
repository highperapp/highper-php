<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\ServiceProvider;

use HighPerApp\HighPer\Contracts\ServiceProviderInterface;

/**
 * Package Auto-Discovery
 * 
 * Automatically discovers and loads HighPer packages from composer.lock.
 * Enables seamless integration of external packages via ServiceProviders.
 */
class PackageDiscovery
{
    private const HIGHPER_PACKAGE_PREFIX = 'easeappphp/highper-';
    private const PROVIDER_SUFFIX = 'ServiceProvider';
    
    private array $discoveredPackages = [];
    private array $packageProviders = [];

    public function discoverInstalledPackages(): array
    {
        if (!empty($this->discoveredPackages)) {
            return $this->discoveredPackages;
        }

        $packages = [];

        // Check local packages directory first (development environment)
        $localPackagesPath = getcwd() . '/packages';
        if (is_dir($localPackagesPath)) {
            $packages = array_merge($packages, $this->scanLocalPackagesDirectory($localPackagesPath));
        }

        // Check composer.lock for installed packages
        $composerLockPath = getcwd() . '/composer.lock';
        if (file_exists($composerLockPath)) {
            $packages = array_merge($packages, $this->parseComposerLock($composerLockPath));
        }

        // Check vendor directory as fallback
        $vendorPath = getcwd() . '/vendor';
        if (is_dir($vendorPath)) {
            $packages = array_merge($packages, $this->scanVendorDirectory($vendorPath));
        }

        $this->discoveredPackages = array_unique($packages);
        
        return $this->discoveredPackages;
    }

    public function loadServiceProviders(array $packages, \HighPerApp\HighPer\Contracts\ApplicationInterface $app): array
    {
        $providers = [];

        foreach ($packages as $packageName) {
            $provider = $this->loadServiceProvider($packageName, $app);
            if ($provider !== null) {
                $providers[] = $provider;
                $this->packageProviders[$packageName] = $provider;
            }
        }

        return $providers;
    }

    public function getServiceProvider(string $packageName): ?ServiceProviderInterface
    {
        return $this->packageProviders[$packageName] ?? null;
    }

    public function hasPackage(string $packageName): bool
    {
        return in_array($packageName, $this->discoveredPackages, true);
    }

    public function getPackageInfo(string $packageName): array
    {
        $composerJsonPath = $this->getPackageComposerJsonPath($packageName);
        
        if ($composerJsonPath && file_exists($composerJsonPath)) {
            $composerJson = json_decode(file_get_contents($composerJsonPath), true);
            return $composerJson ?? [];
        }

        return [];
    }

    public function getDiscoveredPackages(): array
    {
        return $this->discoveredPackages;
    }

    public function clearCache(): void
    {
        $this->discoveredPackages = [];
        $this->packageProviders = [];
    }

    private function parseComposerLock(string $composerLockPath): array
    {
        $packages = [];
        
        try {
            $composerLock = json_decode(file_get_contents($composerLockPath), true);
            
            if (!is_array($composerLock) || !isset($composerLock['packages'])) {
                return $packages;
            }

            foreach ($composerLock['packages'] as $package) {
                if (isset($package['name']) && $this->isHighPerPackage($package['name'])) {
                    $packages[] = $package['name'];
                }
            }
            
        } catch (\Throwable $e) {
            // Silently fail if composer.lock can't be parsed
            error_log("Failed to parse composer.lock: " . $e->getMessage());
        }

        return $packages;
    }

    private function scanVendorDirectory(string $vendorPath): array
    {
        $packages = [];
        $easeAppPath = $vendorPath . '/easeappphp';
        
        if (!is_dir($easeAppPath)) {
            return $packages;
        }

        try {
            $directories = scandir($easeAppPath);
            
            foreach ($directories as $directory) {
                if ($directory === '.' || $directory === '..') {
                    continue;
                }
                
                $fullPath = $easeAppPath . '/' . $directory;
                if (is_dir($fullPath) && strpos($directory, 'highper-') === 0) {
                    $packageName = 'easeappphp/' . $directory;
                    $packages[] = $packageName;
                }
            }
            
        } catch (\Throwable $e) {
            // Silently fail if vendor directory can't be scanned
            error_log("Failed to scan vendor directory: " . $e->getMessage());
        }

        return $packages;
    }

    private function scanLocalPackagesDirectory(string $packagesPath): array
    {
        $packages = [];
        
        try {
            $directories = scandir($packagesPath);
            
            foreach ($directories as $directory) {
                if ($directory === '.' || $directory === '..') {
                    continue;
                }
                
                $fullPath = $packagesPath . '/' . $directory;
                if (is_dir($fullPath) && strpos($directory, 'highper-') === 0) {
                    $packageName = 'easeappphp/' . $directory;
                    $packages[] = $packageName;
                }
            }
            
        } catch (\Throwable $e) {
            error_log("Failed to scan local packages directory: " . $e->getMessage());
        }

        return $packages;
    }

    private function loadServiceProvider(string $packageName, \HighPerApp\HighPer\Contracts\ApplicationInterface $app): ?ServiceProviderInterface
    {
        $providerClass = $this->getServiceProviderClass($packageName);
        
        // Try to load the class from local packages first
        if (!class_exists($providerClass)) {
            $this->tryLoadLocalServiceProvider($packageName, $providerClass);
        }
        
        if (!class_exists($providerClass)) {
            return null;
        }

        try {
            $provider = new $providerClass($app);
            
            if (!$provider instanceof ServiceProviderInterface) {
                error_log("Invalid service provider for package {$packageName}: must implement ServiceProviderInterface");
                return null;
            }

            return $provider;
            
        } catch (\Throwable $e) {
            error_log("Failed to instantiate service provider for package {$packageName}: " . $e->getMessage());
            return null;
        }
    }

    private function getServiceProviderClass(string $packageName): string
    {
        // Convert package name to namespace and class name
        // e.g., easeappphp/highper-cache -> EaseAppPHP\HighPerCache\CacheServiceProvider
        
        $parts = explode('/', $packageName);
        if (count($parts) !== 2) {
            return '';
        }

        [$vendor, $package] = $parts;
        
        // Convert vendor name to namespace
        $vendorNamespace = $this->convertToNamespace($vendor);
        
        // Convert package name to namespace and class
        $packageNamespace = $this->convertToNamespace($package);
        $className = $this->convertToClassName($package) . self::PROVIDER_SUFFIX;
        
        return "{$vendorNamespace}\\{$packageNamespace}\\{$className}";
    }

    private function convertToNamespace(string $name): string
    {
        // Convert kebab-case to PascalCase
        // e.g., easeappphp -> EaseAppPHP, highper-cache -> HighPerCache
        
        $parts = explode('-', $name);
        $namespace = '';
        
        foreach ($parts as $part) {
            $namespace .= ucfirst(strtolower($part));
        }
        
        return $namespace;
    }

    private function convertToClassName(string $packageName): string
    {
        // Remove 'highper-' prefix and convert to PascalCase
        // e.g., highper-cache -> Cache, highper-websockets -> WebSockets
        
        $name = str_replace('highper-', '', $packageName);
        $parts = explode('-', $name);
        $className = '';
        
        foreach ($parts as $part) {
            $className .= ucfirst(strtolower($part));
        }
        
        return $className;
    }

    private function isHighPerPackage(string $packageName): bool
    {
        return strpos($packageName, self::HIGHPER_PACKAGE_PREFIX) === 0;
    }

    private function tryLoadLocalServiceProvider(string $packageName, string $providerClass): void
    {
        $packageDir = str_replace('easeappphp/', '', $packageName);
        $localPackagePath = getcwd() . '/packages/' . $packageDir;
        
        if (!is_dir($localPackagePath)) {
            return;
        }

        // Try to include the service provider file if it exists
        $serviceProviderFile = $localPackagePath . '/src/' . $this->getServiceProviderFileName($packageName);
        if (file_exists($serviceProviderFile)) {
            require_once $serviceProviderFile;
        }
    }

    private function getServiceProviderFileName(string $packageName): string
    {
        $className = $this->convertToClassName($packageName);
        return str_replace('easeappphp/', '', $className) . self::PROVIDER_SUFFIX . '.php';
    }

    private function getPackageComposerJsonPath(string $packageName): ?string
    {
        // Check local packages first
        $packageDir = str_replace('easeappphp/', '', $packageName);
        $localPath = getcwd() . '/packages/' . $packageDir . '/composer.json';
        if (file_exists($localPath)) {
            return $localPath;
        }

        // Check vendor directory
        $vendorPath = getcwd() . '/vendor/' . $packageName . '/composer.json';
        return file_exists($vendorPath) ? $vendorPath : null;
    }
}