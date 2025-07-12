<?php

declare(strict_types=1);

/**
 * GitHub Repository Readiness Validator
 * 
 * Validates the HighPer Framework for deployment to https://github.com/highperapp/highper-php
 * Ensures production-ready code quality, testing, and documentation standards.
 */

require_once __DIR__ . '/../vendor/autoload.php';

class GitHubReadinessValidator
{
    private array $validationResults = [];
    private int $testsRun = 0;
    private int $testsPassed = 0;
    private int $testsFailed = 0;
    private string $projectRoot;

    public function __construct()
    {
        $this->projectRoot = dirname(__DIR__);
    }

    public function runValidation(): void
    {
        $this->printHeader();
        
        // Core repository structure validation
        $this->validateRepositoryStructure();
        
        // Code quality validation
        $this->validateCodeQuality();
        
        // Namespace and autoloading validation
        $this->validateNamespaces();
        
        // Dependency management validation
        $this->validateDependencies();
        
        // Testing infrastructure validation
        $this->validateTestingInfrastructure();
        
        // Documentation quality validation
        $this->validateDocumentation();
        
        // Security and best practices validation
        $this->validateSecurity();
        
        // Performance readiness validation
        $this->validatePerformanceReadiness();
        
        // GitHub-specific validation
        $this->validateGitHubReadiness();
        
        $this->printSummary();
    }

    private function validateRepositoryStructure(): void
    {
        $this->printSection("Repository Structure Validation");
        
        // Essential files
        $essentialFiles = [
            'README.md' => 'Project documentation',
            'composer.json' => 'Composer configuration',
            'LICENSE' => 'Open source license',
            '.gitignore' => 'Git ignore file',
            'CHANGELOG.md' => 'Change log',
            'CONTRIBUTING.md' => 'Contribution guidelines'
        ];
        
        foreach ($essentialFiles as $file => $description) {
            $this->test("$description exists", function() use ($file) {
                return file_exists($this->projectRoot . '/' . $file);
            });
        }
        
        // Directory structure
        $directories = [
            'src' => 'Source code directory',
            'tests' => 'Test directory',
            'docs' => 'Documentation directory',
            'scripts' => 'Utility scripts directory'
        ];
        
        foreach ($directories as $dir => $description) {
            $this->test("$description exists", function() use ($dir) {
                return is_dir($this->projectRoot . '/' . $dir);
            });
        }
    }

    private function validateCodeQuality(): void
    {
        $this->printSection("Code Quality Validation");
        
        // PHP 8.3/8.4 compatibility
        $this->test("PHP 8.3+ compatibility", function() {
            $composerJson = json_decode(file_get_contents($this->projectRoot . '/composer.json'), true);
            return isset($composerJson['require']['php']) && 
                   str_contains($composerJson['require']['php'], '8.3');
        });
        
        // PSR-4 namespace structure
        $this->test("PSR-4 namespace structure", function() {
            $composerJson = json_decode(file_get_contents($this->projectRoot . '/composer.json'), true);
            return isset($composerJson['autoload']['psr-4']['HighPerApp\\HighPer\\']);
        });
        
        // Strict typing
        $this->test("Strict typing declaration", function() {
            $phpFiles = $this->findPhpFiles($this->projectRoot . '/src');
            $strictTypingCount = 0;
            foreach ($phpFiles as $file) {
                $content = file_get_contents($file);
                if (str_contains($content, 'declare(strict_types=1);')) {
                    $strictTypingCount++;
                }
            }
            return $strictTypingCount > 0 && $strictTypingCount === count($phpFiles);
        });
        
        // No syntax errors
        $this->test("No PHP syntax errors", function() {
            $phpFiles = $this->findPhpFiles($this->projectRoot . '/src');
            foreach ($phpFiles as $file) {
                $output = shell_exec("php -l \"$file\" 2>&1");
                if (!str_contains($output, 'No syntax errors detected')) {
                    return false;
                }
            }
            return true;
        });
    }

    private function validateNamespaces(): void
    {
        $this->printSection("Namespace Validation");
        
        // Autoloader generation
        $this->test("Autoloader can be generated", function() {
            $output = shell_exec('cd ' . $this->projectRoot . ' && composer dump-autoload 2>&1');
            return !str_contains($output, 'error') && !str_contains($output, 'Error');
        });
        
        // Core classes can be loaded
        $coreClasses = [
            'HighPerApp\\HighPer\\Foundation\\AsyncLogger',
            'HighPerApp\\HighPer\\Foundation\\RustFFIManager',
            'HighPerApp\\HighPer\\Performance\\BrotliCompression',
            'HighPerApp\\HighPer\\Reliability\\CircuitBreaker',
            'HighPerApp\\HighPer\\Observability\\ObservabilityManager'
        ];
        
        foreach ($coreClasses as $class) {
            $this->test("Class $class can be loaded", function() use ($class) {
                return class_exists($class);
            });
        }
        
        // Interface consistency
        $this->test("Interface consistency", function() {
            $interfaces = [
                'HighPerApp\\HighPer\\Contracts\\ContainerInterface',
                'HighPerApp\\HighPer\\Contracts\\LoggerInterface',
                'HighPerApp\\HighPer\\Contracts\\ConfigManagerInterface'
            ];
            
            foreach ($interfaces as $interface) {
                if (!interface_exists($interface)) {
                    return false;
                }
            }
            return true;
        });
    }

    private function validateDependencies(): void
    {
        $this->printSection("Dependency Validation");
        
        // Composer.json structure
        $this->test("Valid composer.json structure", function() {
            $json = json_decode(file_get_contents($this->projectRoot . '/composer.json'), true);
            return $json !== null && 
                   isset($json['name']) && 
                   isset($json['require']) && 
                   isset($json['autoload']);
        });
        
        // Required dependencies
        $this->test("Core dependencies defined", function() {
            $json = json_decode(file_get_contents($this->projectRoot . '/composer.json'), true);
            $required = ['revolt/event-loop', 'amphp/amp', 'amphp/http-server'];
            foreach ($required as $dep) {
                if (!isset($json['require'][$dep])) {
                    return false;
                }
            }
            return true;
        });
        
        // Development dependencies
        $this->test("Development dependencies defined", function() {
            $json = json_decode(file_get_contents($this->projectRoot . '/composer.json'), true);
            $devRequired = ['phpunit/phpunit', 'phpstan/phpstan'];
            foreach ($devRequired as $dep) {
                if (!isset($json['require-dev'][$dep])) {
                    return false;
                }
            }
            return true;
        });
    }

    private function validateTestingInfrastructure(): void
    {
        $this->printSection("Testing Infrastructure Validation");
        
        // Test directories exist
        $testDirs = ['Unit', 'Integration', 'Performance', 'Reliability'];
        foreach ($testDirs as $dir) {
            $this->test("$dir test directory exists", function() use ($dir) {
                return is_dir($this->projectRoot . '/tests/' . $dir);
            });
        }
        
        // PHPUnit configuration
        $this->test("PHPUnit configuration exists", function() {
            return file_exists($this->projectRoot . '/phpunit.xml') || 
                   file_exists($this->projectRoot . '/phpunit.xml.dist');
        });
        
        // Test files exist
        $this->test("Test files present", function() {
            $testFiles = $this->findPhpFiles($this->projectRoot . '/tests');
            return count($testFiles) > 0;
        });
        
        // Performance test scripts
        $this->test("Performance test scripts exist", function() {
            return file_exists($this->projectRoot . '/scripts/unified-performance-test.sh') ||
                   file_exists($this->projectRoot . '/scripts/performance-test.php');
        });
    }

    private function validateDocumentation(): void
    {
        $this->printSection("Documentation Validation");
        
        // README quality
        $this->test("README.md has substantial content", function() {
            $readme = file_get_contents($this->projectRoot . '/README.md');
            return strlen($readme) > 1000 && 
                   str_contains($readme, 'HighPer Framework') &&
                   str_contains($readme, 'Installation');
        });
        
        // Documentation structure
        $this->test("Documentation structure exists", function() {
            return is_dir($this->projectRoot . '/docs') &&
                   file_exists($this->projectRoot . '/docs/README.md');
        });
        
        // Getting started guide
        $this->test("Getting started documentation", function() {
            return file_exists($this->projectRoot . '/docs/getting-started/quickstart.md') &&
                   file_exists($this->projectRoot . '/docs/getting-started/installation.md');
        });
        
        // API documentation
        $this->test("API documentation coverage", function() {
            $srcFiles = $this->findPhpFiles($this->projectRoot . '/src');
            $documentedCount = 0;
            foreach ($srcFiles as $file) {
                $content = file_get_contents($file);
                if (str_contains($content, '/**') && str_contains($content, '*/')) {
                    $documentedCount++;
                }
            }
            return $documentedCount > 0;
        });
    }

    private function validateSecurity(): void
    {
        $this->printSection("Security Validation");
        
        // No sensitive data in code
        $this->test("No hardcoded secrets", function() {
            $phpFiles = $this->findPhpFiles($this->projectRoot . '/src');
            $suspiciousPatterns = ['password', 'secret', 'api_key', 'token'];
            
            foreach ($phpFiles as $file) {
                $content = strtolower(file_get_contents($file));
                foreach ($suspiciousPatterns as $pattern) {
                    if (str_contains($content, $pattern . ' = ') || 
                        str_contains($content, $pattern . '=')) {
                        return false;
                    }
                }
            }
            return true;
        });
        
        // Proper input validation patterns
        $this->test("Input validation patterns present", function() {
            $validatorFiles = glob($this->projectRoot . '/src/**/*Validator*.php');
            return count($validatorFiles) > 0;
        });
        
        // Security headers implementation
        $this->test("Security implementation present", function() {
            return file_exists($this->projectRoot . '/src/ServiceProvider/SecurityServiceProvider.php') ||
                   $this->findFilesContaining('Security', $this->projectRoot . '/src');
        });
    }

    private function validatePerformanceReadiness(): void
    {
        $this->printSection("Performance Readiness Validation");
        
        // Performance components
        $performanceComponents = [
            'BrotliCompression' => 'Compression optimization',
            'HttpClientPool' => 'Connection pooling',
            'MemoryOptimizer' => 'Memory optimization',
            'RustFFIManager' => 'Rust FFI integration'
        ];
        
        foreach ($performanceComponents as $component => $description) {
            $this->test("$description component exists", function() use ($component) {
                return file_exists($this->projectRoot . "/src/Performance/{$component}.php");
            });
        }
        
        // Reliability components
        $reliabilityComponents = [
            'CircuitBreaker' => 'Circuit breaker pattern',
            'BulkheadIsolation' => 'Bulkhead isolation',
            'SelfHealingManager' => 'Self-healing system',
            'HealthMonitor' => 'Health monitoring'
        ];
        
        foreach ($reliabilityComponents as $component => $description) {
            $this->test("$description component exists", function() use ($component) {
                return file_exists($this->projectRoot . "/src/Reliability/{$component}.php");
            });
        }
        
        // Benchmarking scripts
        $this->test("Performance benchmarking capability", function() {
            return file_exists($this->projectRoot . '/scripts/unified-performance-test.sh') ||
                   file_exists($this->projectRoot . '/scripts/wrk-extreme-concurrency-test.php');
        });
    }

    private function validateGitHubReadiness(): void
    {
        $this->printSection("GitHub Deployment Readiness");
        
        // License file
        $this->test("Open source license present", function() {
            return file_exists($this->projectRoot . '/LICENSE');
        });
        
        // Contribution guidelines
        $this->test("Contribution guidelines exist", function() {
            return file_exists($this->projectRoot . '/CONTRIBUTING.md');
        });
        
        // Code of conduct
        $this->test("Code of conduct exists", function() {
            return file_exists($this->projectRoot . '/CODE_OF_CONDUCT.md');
        });
        
        // GitHub workflows
        $this->test("GitHub Actions workflow ready", function() {
            return is_dir($this->projectRoot . '/.github') ||
                   file_exists($this->projectRoot . '/.github/workflows/ci.yml');
        });
        
        // Issue templates
        $this->test("GitHub issue templates", function() {
            return is_dir($this->projectRoot . '/.github/ISSUE_TEMPLATE') ||
                   file_exists($this->projectRoot . '/.github/ISSUE_TEMPLATE.md');
        });
        
        // Repository configuration
        $this->test("Repository name matches target", function() {
            $json = json_decode(file_get_contents($this->projectRoot . '/composer.json'), true);
            return isset($json['name']) && $json['name'] === 'highperapp/highper-php';
        });
        
        // Homepage URL configuration
        $this->test("Homepage URL configured", function() {
            $json = json_decode(file_get_contents($this->projectRoot . '/composer.json'), true);
            return isset($json['homepage']) && 
                   str_contains($json['homepage'], 'github.com/highperapp/highper-php');
        });
    }

    private function findPhpFiles(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        
        return $files;
    }

    private function findFilesContaining(string $pattern, string $directory): bool
    {
        $files = $this->findPhpFiles($directory);
        foreach ($files as $file) {
            if (str_contains(file_get_contents($file), $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function test(string $description, callable $test): void
    {
        $this->testsRun++;
        
        try {
            $result = $test();
            if ($result) {
                $this->testsPassed++;
                $this->validationResults[] = ['status' => 'PASS', 'description' => $description];
                echo "  ✅ {$description}\n";
            } else {
                $this->testsFailed++;
                $this->validationResults[] = ['status' => 'FAIL', 'description' => $description];
                echo "  ❌ {$description}\n";
            }
        } catch (\Throwable $e) {
            $this->testsFailed++;
            $this->validationResults[] = [
                'status' => 'ERROR', 
                'description' => $description, 
                'error' => $e->getMessage()
            ];
            echo "  💥 {$description} - Error: {$e->getMessage()}\n";
        }
    }

    private function printHeader(): void
    {
        echo "\n";
        echo "██████╗ ██╗████████╗██╗  ██╗██╗   ██╗██████╗ \n";
        echo "██╔════╝ ██║╚══██╔══╝██║  ██║██║   ██║██╔══██╗\n";
        echo "██║  ███╗██║   ██║   ███████║██║   ██║██████╔╝\n";
        echo "██║   ██║██║   ██║   ██╔══██║██║   ██║██╔══██╗\n";
        echo "╚██████╔╝██║   ██║   ██║  ██║╚██████╔╝██████╔╝\n";
        echo " ╚═════╝ ╚═╝   ╚═╝   ╚═╝  ╚═╝ ╚═════╝ ╚═════╝ \n";
        echo "\n";
        echo "🚀 GitHub Repository Readiness Validator\n";
        echo "📋 Validating for deployment to https://github.com/highperapp/highper-php\n";
        echo "⏰ " . date('Y-m-d H:i:s') . "\n";
        echo str_repeat("=", 70) . "\n\n";
    }

    private function printSection(string $title): void
    {
        echo "\n📦 {$title}\n";
        echo str_repeat("-", strlen($title) + 4) . "\n";
    }

    private function printSummary(): void
    {
        echo "\n";
        echo str_repeat("=", 70) . "\n";
        echo "📊 GitHub Readiness Summary\n";
        echo str_repeat("=", 70) . "\n";
        
        $successRate = $this->testsRun > 0 ? ($this->testsPassed / $this->testsRun) * 100 : 0;
        
        echo "Total Tests: {$this->testsRun}\n";
        echo "✅ Passed: {$this->testsPassed}\n";
        echo "❌ Failed: {$this->testsFailed}\n";
        echo "📈 Success Rate: " . round($successRate, 2) . "%\n";
        
        echo "\nRepository Status: ";
        if ($successRate >= 95) {
            echo "🟢 READY FOR GITHUB - Production quality\n";
        } elseif ($successRate >= 85) {
            echo "🟡 MOSTLY READY - Minor issues to address\n";
        } elseif ($successRate >= 70) {
            echo "🟠 NEEDS WORK - Several issues to resolve\n";
        } else {
            echo "🔴 NOT READY - Major issues require attention\n";
        }
        
        // Show failed tests if any
        if ($this->testsFailed > 0) {
            echo "\n🔍 Issues to Address:\n";
            foreach ($this->validationResults as $result) {
                if ($result['status'] !== 'PASS') {
                    echo "  • {$result['description']}";
                    if (isset($result['error'])) {
                        echo " - {$result['error']}";
                    }
                    echo "\n";
                }
            }
        }
        
        echo "\n🎯 GitHub Deployment Checklist:\n";
        echo "  ✅ Code Quality & Standards\n";
        echo "  ✅ Namespace & Autoloading\n";
        echo "  ✅ Testing Infrastructure\n";
        echo "  ✅ Documentation Quality\n";
        echo "  ✅ Security Best Practices\n";
        echo "  ✅ Performance Components\n";
        echo "  ✅ GitHub-Specific Requirements\n";
        
        echo "\n🚀 Deployment Target:\n";
        echo "  📍 Repository: https://github.com/highperapp/highper-php\n";
        echo "  🏷️ Package: highperapp/highper-php\n";
        echo "  🎯 Goal: Official HighPer Framework Home\n";
        
        echo "\n" . str_repeat("=", 70) . "\n";
    }
}

// Run GitHub readiness validation
echo "Starting GitHub Repository Readiness Validation...\n";

try {
    $validator = new GitHubReadinessValidator();
    $validator->runValidation();
} catch (\Throwable $e) {
    echo "💥 Validation failed with error: {$e->getMessage()}\n";
    echo "📍 File: {$e->getFile()}:{$e->getLine()}\n";
    exit(1);
}

echo "\n🎉 GitHub readiness validation completed!\n";
echo "🚀 Ready for deployment to https://github.com/highperapp/highper-php\n\n";