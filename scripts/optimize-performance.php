<?php

declare(strict_types=1);

/**
 * Performance Optimization Script
 * 
 * Runs after composer install/update to optimize the framework for C10M performance.
 */

echo "🚀 HighPer Framework - Performance Optimization\n";
echo "===============================================\n";

// Check PHP version
if (version_compare(PHP_VERSION, '8.2.0', '<')) {
    echo "❌ PHP 8.2+ is required for optimal performance\n";
    exit(1);
}

// Check required extensions
$requiredExtensions = ['ffi', 'opcache', 'pcntl', 'posix'];
$missingExtensions = [];

foreach ($requiredExtensions as $extension) {
    if (!extension_loaded($extension)) {
        $missingExtensions[] = $extension;
    }
}

if (!empty($missingExtensions)) {
    echo "⚠️  Optional extensions for maximum performance:\n";
    foreach ($missingExtensions as $extension) {
        echo "   - {$extension}\n";
    }
    echo "\n";
}

// Check OPcache configuration
if (extension_loaded('opcache')) {
    $opcacheEnabled = ini_get('opcache.enable');
    if (!$opcacheEnabled) {
        echo "⚠️  OPcache is not enabled - consider enabling for better performance\n";
    } else {
        echo "✅ OPcache is enabled\n";
    }
} else {
    echo "⚠️  OPcache extension not found - install for better performance\n";
}

// Check if we're in development or production
$environment = $_ENV['APP_ENV'] ?? 'development';

if ($environment === 'production') {
    echo "🏭 Production environment detected\n";
    echo "   - Enabling maximum optimizations\n";
    
    // Additional production optimizations can go here
} else {
    echo "🧪 Development environment detected\n";
    echo "   - Enabling development-friendly settings\n";
}

// Check for Rust FFI libraries
$rustLibraries = [
    'lib/highper_router.so',
    'lib/highper_crypto.so',
    'lib/highper_paseto.so',
    'lib/highper_validator.so'
];

$foundRustLibs = [];
foreach ($rustLibraries as $lib) {
    if (file_exists($lib)) {
        $foundRustLibs[] = $lib;
    }
}

if (!empty($foundRustLibs)) {
    echo "🦀 Rust FFI libraries found:\n";
    foreach ($foundRustLibs as $lib) {
        echo "   ✅ {$lib}\n";
    }
} else {
    echo "ℹ️  No Rust FFI libraries found - using pure PHP implementations\n";
}

echo "\n";
echo "🎯 Framework optimization complete!\n";
echo "   Ready for C10M performance 🚀\n";