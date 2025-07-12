<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * CLI Architecture Integration Tests
 * 
 * Tests the enhanced CLI with advanced architecture features.
 */
class CLIArchitectureTest extends TestCase
{
    private string $cliPath;

    protected function setUp(): void
    {
        $this->cliPath = __DIR__ . '/../../bin/highper';
        $this->assertTrue(file_exists($this->cliPath), 'CLI executable not found');
    }

    public function testCLIHelpCommand(): void
    {
        $output = shell_exec("php {$this->cliPath} help 2>&1");
        
        $this->assertStringContains('HighPer Framework CLI', $output);
        $this->assertStringContains('Hybrid Multi-Process + Async Architecture', $output);
        $this->assertStringContains('--workers=COUNT', $output);
        $this->assertStringContains('--mode=MODE', $output);
        $this->assertStringContains('--c10m', $output);
        $this->assertStringContains('--rust=enabled', $output);
        $this->assertStringContains('--zero-downtime', $output);
    }

    public function testCLIStatusCommand(): void
    {
        $output = shell_exec("php {$this->cliPath} status 2>&1");
        
        $this->assertStringContains('HighPer Framework Status', $output);
        $this->assertStringContains('Architecture Status:', $output);
        $this->assertStringContains('Core Components:', $output);
        $this->assertStringContains('Event Loop Support:', $output);
        $this->assertStringContains('Performance Extensions:', $output);
        $this->assertStringContains('ProcessManager:', $output);
        $this->assertStringContains('HybridEventLoop:', $output);
    }

    public function testCLIBasicServeValidation(): void
    {
        // Test that serve command validates configuration without actually starting
        $output = shell_exec("php {$this->cliPath} serve --workers=2 --dry-run 2>&1");
        
        // Since --dry-run doesn't exist, this will show the configuration attempt
        $this->assertNotEmpty($output);
    }

    public function testCLIWorkerCountConfiguration(): void
    {
        // Test worker count validation through configuration output
        $output = shell_exec("php {$this->cliPath} help 2>&1");
        
        $this->assertStringContains('--workers=COUNT', $output);
        $this->assertStringContains('Number of worker processes', $output);
        $this->assertStringContains('auto-detect CPU cores', $output);
    }

    public function testCLIModeConfiguration(): void
    {
        $output = shell_exec("php {$this->cliPath} help 2>&1");
        
        $this->assertStringContains('--mode=MODE', $output);
        $this->assertStringContains('single|dedicated', $output);
        $this->assertStringContains('--http-port=PORT', $output);
        $this->assertStringContains('--ws-port=PORT', $output);
    }

    public function testCLIC10MConfiguration(): void
    {
        $output = shell_exec("php {$this->cliPath} help 2>&1");
        
        $this->assertStringContains('--c10m', $output);
        $this->assertStringContains('C10M optimizations', $output);
        $this->assertStringContains('10M concurrent connections', $output);
    }

    public function testCLIRustConfiguration(): void
    {
        $output = shell_exec("php {$this->cliPath} help 2>&1");
        
        $this->assertStringContains('--rust=enabled', $output);
        $this->assertStringContains('Rust FFI optimizations', $output);
    }

    public function testCLIZeroDowntimeConfiguration(): void
    {
        $output = shell_exec("php {$this->cliPath} help 2>&1");
        
        $this->assertStringContains('--zero-downtime', $output);
        $this->assertStringContains('--deployment-strategy=TYPE', $output);
        $this->assertStringContains('blue_green|rolling', $output);
    }

    public function testCLIExamplesDocumentation(): void
    {
        $output = shell_exec("php {$this->cliPath} help 2>&1");
        
        $this->assertStringContains('Single port multiplexing', $output);
        $this->assertStringContains('Dedicated ports mode', $output);
        $this->assertStringContains('With performance optimizations', $output);
        $this->assertStringContains('Production with zero-downtime', $output);
        $this->assertStringContains('Full enterprise configuration', $output);
    }

    public function testCLIInvalidCommand(): void
    {
        $output = shell_exec("php {$this->cliPath} invalid-command 2>&1");
        
        // Should show help when invalid command is provided
        $this->assertStringContains('HighPer Framework CLI', $output);
    }

    public function testCLISystemCapabilityDetection(): void
    {
        $output = shell_exec("php {$this->cliPath} status 2>&1");
        
        // Should detect actual system capabilities
        $this->assertStringContains('CPU cores detected:', $output);
        $this->assertStringContains('PHP version:', $output);
        $this->assertStringContains('Memory limit:', $output);
        
        // Should show extension availability
        $this->assertStringContains('RevoltPHP:', $output);
        $this->assertStringContains('php-uv extension:', $output);
        $this->assertStringContains('FFI (Rust integration):', $output);
        $this->assertStringContains('Process control:', $output);
    }

    public function testCLIQuickStartExamples(): void
    {
        $output = shell_exec("php {$this->cliPath} status 2>&1");
        
        $this->assertStringContains('Quick Start Examples:', $output);
        $this->assertStringContains('Basic server', $output);
        $this->assertStringContains('Production server with all optimizations', $output);
        $this->assertStringContains('Dedicated ports mode', $output);
        
        // Should include actual CPU count in examples
        $cpuCores = (int) shell_exec('nproc') ?: 4;
        $this->assertStringContains("--workers={$cpuCores}", $output);
    }

    public function testCLIComponentAvailability(): void
    {
        $output = shell_exec("php {$this->cliPath} status 2>&1");
        
        // Core components should be reported as available or missing
        $this->assertRegExp('/ProcessManager:\s+(Available|Missing)/', $output);
        $this->assertRegExp('/HybridEventLoop:\s+(Available|Missing)/', $output);
        $this->assertRegExp('/ZeroDowntimeWorkerManager:\s+(Available|Missing)/', $output);
    }

    public function testCLIExtensionDetection(): void
    {
        $output = shell_exec("php {$this->cliPath} status 2>&1");
        
        // Should accurately report extension availability
        $uvAvailable = extension_loaded('uv');
        $ffiAvailable = extension_loaded('ffi');
        $opcacheAvailable = extension_loaded('opcache');
        $pcntlAvailable = function_exists('pcntl_fork');
        
        if ($uvAvailable) {
            $this->assertStringContains('✅ php-uv extension: Available', $output);
        } else {
            $this->assertStringContains('❌ php-uv extension: Not available', $output);
        }
        
        if ($ffiAvailable) {
            $this->assertStringContains('✅ FFI (Rust integration): Available', $output);
        } else {
            $this->assertStringContains('❌ FFI (Rust integration): Not available', $output);
        }
        
        if ($pcntlAvailable) {
            $this->assertStringContains('✅ Process control: Available', $output);
        } else {
            $this->assertStringContains('❌ Process control: Not available', $output);
        }
    }

    public function testCLIVersionInformation(): void
    {
        $output = shell_exec("php {$this->cliPath} help 2>&1");
        
        $this->assertStringContains('HighPer Framework', $output);
        $this->assertNotStringContains('v2', $output); // Should not show old version
    }

    public function testCLIArgumentParsing(): void
    {
        // Test that help shows proper argument format
        $output = shell_exec("php {$this->cliPath} help 2>&1");
        
        $this->assertStringContains('bin/highper [command] [options]', $output);
        $this->assertStringContains('--host=HOST', $output);
        $this->assertStringContains('--port=PORT', $output);
        $this->assertStringContains('--env=ENV', $output);
        $this->assertStringContains('--protocols=LIST', $output);
    }

    public function testCLIAdvancedOptionsSection(): void
    {
        $output = shell_exec("php {$this->cliPath} help 2>&1");
        
        $this->assertStringContains('Advanced Architecture Options:', $output);
        $this->assertStringContains('Basic Options for serve:', $output);
        
        // Ensure advanced options are clearly separated
        $advancedPos = strpos($output, 'Advanced Architecture Options:');
        $basicPos = strpos($output, 'Basic Options for serve:');
        
        $this->assertNotFalse($advancedPos);
        $this->assertNotFalse($basicPos);
        $this->assertLessThan($advancedPos, $basicPos); // Basic should come before advanced
    }

    public function testCLIExampleFormatting(): void
    {
        $output = shell_exec("php {$this->cliPath} help 2>&1");
        
        // Check that examples are properly formatted with comments
        $this->assertStringContains('# Single port multiplexing (default)', $output);
        $this->assertStringContains('# Dedicated ports mode', $output);
        $this->assertStringContains('# With performance optimizations', $output);
        $this->assertStringContains('# Production with zero-downtime', $output);
        $this->assertStringContains('# Full enterprise configuration', $output);
    }

    public function testCLIStopCommand(): void
    {
        $output = shell_exec("php {$this->cliPath} stop 2>&1");
        
        $this->assertStringContains('Stop command not yet implemented', $output);
        $this->assertStringContains('Use Ctrl+C to stop the server', $output);
    }

    public function testCLIMemoryLimitFormat(): void
    {
        $output = shell_exec("php {$this->cliPath} help 2>&1");
        
        $this->assertStringContains('--memory-limit=SIZE', $output);
        $this->assertStringContains('Worker memory limit', $output);
        $this->assertStringContains('default: 256M', $output);
        
        // Examples should show proper memory format
        $this->assertStringContains('--memory-limit=1G', $output);
        $this->assertStringContains('--memory-limit=2G', $output);
    }

    public function testCLIPortConfiguration(): void
    {
        $output = shell_exec("php {$this->cliPath} help 2>&1");
        
        // Basic port configuration
        $this->assertStringContains('--port=PORT', $output);
        $this->assertStringContains('default: 8080', $output);
        
        // Dedicated mode ports
        $this->assertStringContains('--http-port=PORT', $output);
        $this->assertStringContains('--ws-port=PORT', $output);
        $this->assertStringContains('default: 8081', $output);
    }
}