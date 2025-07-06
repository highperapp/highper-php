<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Performance;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use HighPerApp\HighPer\Contracts\LoggerInterface;

/**
 * Brotli Compression Middleware for AMPHP HTTP Server
 * 
 * Provides intelligent HTTP response compression with:
 * - Brotli (primary): 20-30% better compression than gzip
 * - Gzip (fallback): Maximum browser compatibility
 * - Content-aware compression decisions
 * - Performance monitoring and statistics
 * 
 * Integration with HighPer Framework's transparent fallback pattern.
 */
class BrotliCompressionMiddleware implements Middleware
{
    private BrotliCompression $compressor;
    private LoggerInterface $logger;
    private array $config = [
        'enable_brotli' => true,
        'enable_gzip' => true,
        'min_size' => 1024, // Don't compress responses smaller than 1KB
        'max_size' => 50 * 1024 * 1024, // Don't compress responses larger than 50MB
        'content_types' => [
            'text/html',
            'text/css',
            'text/javascript',
            'text/plain',
            'text/xml',
            'application/json',
            'application/javascript',
            'application/xml',
            'application/rss+xml',
            'application/atom+xml',
            'image/svg+xml'
        ],
        'excluded_extensions' => [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif',
            'mp4', 'avi', 'mkv', 'webm',
            'mp3', 'wav', 'flac', 'ogg',
            'zip', 'rar', '7z', 'tar', 'gz', 'bz2',
            'pdf', 'doc', 'docx', 'xls', 'xlsx'
        ]
    ];
    private array $stats = [
        'requests_processed' => 0,
        'responses_compressed' => 0,
        'bytes_saved' => 0,
        'brotli_used' => 0,
        'gzip_used' => 0,
        'compression_skipped' => 0
    ];

    public function __construct(BrotliCompression $compressor, ?LoggerInterface $logger = null)
    {
        $this->compressor = $compressor;
        $this->logger = $logger ?? new class implements LoggerInterface {
            public function emergency(string $message, array $context = []): void {}
            public function alert(string $message, array $context = []): void {}
            public function critical(string $message, array $context = []): void {}
            public function error(string $message, array $context = []): void {}
            public function warning(string $message, array $context = []): void {}
            public function notice(string $message, array $context = []): void {}
            public function info(string $message, array $context = []): void {}
            public function debug(string $message, array $context = []): void {}
            public function log(mixed $level, string $message, array $context = []): void {}
            public function flush(): void {}
            public function getStats(): array { return []; }
        };

        $this->loadConfiguration();

        $this->logger->info('BrotliCompressionMiddleware initialized', [
            'brotli_available' => $this->compressor->isRustAvailable(),
            'fallback_available' => $this->compressor->isExtensionAvailable(),
            'min_size' => $this->config['min_size'],
            'supported_types' => count($this->config['content_types'])
        ]);
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $this->stats['requests_processed']++;
        $response = $requestHandler->handleRequest($request);

        // Check if compression should be applied
        if (!$this->shouldCompress($request, $response)) {
            $this->stats['compression_skipped']++;
            return $response;
        }

        return $this->compressResponse($request, $response);
    }

    private function shouldCompress(Request $request, Response $response): bool
    {
        // Check if client accepts compression
        $acceptEncoding = $request->getHeader('accept-encoding') ?? '';
        $supportsCompression = $this->supportsCompression($acceptEncoding);
        
        if (!$supportsCompression) {
            return false;
        }

        // Check response size
        $body = $response->getBody()->buffer();
        $bodySize = strlen($body);
        
        if ($bodySize < $this->config['min_size'] || $bodySize > $this->config['max_size']) {
            $this->logger->debug('Compression skipped due to size', [
                'body_size' => $bodySize,
                'min_size' => $this->config['min_size'],
                'max_size' => $this->config['max_size']
            ]);
            return false;
        }

        // Check content type
        $contentType = $response->getHeader('content-type') ?? '';
        if (!$this->isCompressibleContentType($contentType)) {
            $this->logger->debug('Compression skipped due to content type', [
                'content_type' => $contentType
            ]);
            return false;
        }

        // Check if already compressed
        $contentEncoding = $response->getHeader('content-encoding');
        if ($contentEncoding) {
            $this->logger->debug('Compression skipped - already encoded', [
                'content_encoding' => $contentEncoding
            ]);
            return false;
        }

        // Check file extension (if available)
        $uri = $request->getUri()->getPath();
        $extension = strtolower(pathinfo($uri, PATHINFO_EXTENSION));
        if ($extension && in_array($extension, $this->config['excluded_extensions'])) {
            $this->logger->debug('Compression skipped due to file extension', [
                'extension' => $extension,
                'uri' => $uri
            ]);
            return false;
        }

        return true;
    }

    private function compressResponse(Request $request, Response $response): Response
    {
        $body = $response->getBody()->buffer();
        $originalSize = strlen($body);
        $acceptEncoding = $request->getHeader('accept-encoding') ?? '';

        $compressionMethod = $this->selectCompressionMethod($acceptEncoding);
        
        try {
            $startTime = microtime(true);
            
            switch ($compressionMethod) {
                case 'br':
                    $compressedBody = $this->compressor->compress($body);
                    $encoding = 'br';
                    $this->stats['brotli_used']++;
                    break;
                    
                case 'gzip':
                    $compressedBody = gzencode($body, 6);
                    if ($compressedBody === false) {
                        throw new \RuntimeException('Gzip compression failed');
                    }
                    $encoding = 'gzip';
                    $this->stats['gzip_used']++;
                    break;
                    
                case 'deflate':
                    $compressedBody = gzdeflate($body, 6);
                    if ($compressedBody === false) {
                        throw new \RuntimeException('Deflate compression failed');
                    }
                    $encoding = 'deflate';
                    $this->stats['gzip_used']++;
                    break;
                    
                default:
                    $this->stats['compression_skipped']++;
                    return $response;
            }

            $compressedSize = strlen($compressedBody);
            $compressionRatio = round((1 - $compressedSize / $originalSize) * 100, 1);
            $duration = microtime(true) - $startTime;

            // Only use compression if it actually reduces size significantly
            if ($compressedSize >= $originalSize * 0.9) {
                $this->logger->debug('Compression abandoned - insufficient savings', [
                    'original_size' => $originalSize,
                    'compressed_size' => $compressedSize,
                    'method' => $compressionMethod
                ]);
                $this->stats['compression_skipped']++;
                return $response;
            }

            $this->stats['responses_compressed']++;
            $this->stats['bytes_saved'] += $originalSize - $compressedSize;

            $this->logger->debug('Response compressed successfully', [
                'method' => $encoding,
                'original_size' => $originalSize,
                'compressed_size' => $compressedSize,
                'compression_ratio' => $compressionRatio,
                'duration_ms' => round($duration * 1000, 2),
                'uri' => $request->getUri()->getPath()
            ]);

            // Create new response with compressed body
            return $response
                ->withBody($compressedBody)
                ->withHeader('content-encoding', $encoding)
                ->withHeader('content-length', (string) $compressedSize)
                ->withHeader('vary', $this->updateVaryHeader($response->getHeader('vary')));

        } catch (\Throwable $e) {
            $this->logger->error('Compression failed', [
                'method' => $compressionMethod,
                'error' => $e->getMessage(),
                'uri' => $request->getUri()->getPath()
            ]);
            
            $this->stats['compression_skipped']++;
            return $response;
        }
    }

    private function supportsCompression(string $acceptEncoding): bool
    {
        $acceptEncoding = strtolower($acceptEncoding);
        
        return str_contains($acceptEncoding, 'br') ||
               str_contains($acceptEncoding, 'gzip') ||
               str_contains($acceptEncoding, 'deflate');
    }

    private function selectCompressionMethod(string $acceptEncoding): string
    {
        $acceptEncoding = strtolower($acceptEncoding);
        
        // Parse Accept-Encoding header with quality values
        $encodings = $this->parseAcceptEncoding($acceptEncoding);
        
        // Prefer Brotli if available and supported
        if ($this->config['enable_brotli'] && 
            $this->compressor->isRustAvailable() && 
            isset($encodings['br'])) {
            return 'br';
        }
        
        // Fallback to gzip/deflate
        if ($this->config['enable_gzip']) {
            if (isset($encodings['gzip'])) {
                return 'gzip';
            }
            if (isset($encodings['deflate'])) {
                return 'deflate';
            }
        }
        
        return 'none';
    }

    private function parseAcceptEncoding(string $acceptEncoding): array
    {
        $encodings = [];
        $parts = explode(',', $acceptEncoding);
        
        foreach ($parts as $part) {
            $part = trim($part);
            if (preg_match('/^([^;]+)(?:;\s*q=([0-9.]+))?/', $part, $matches)) {
                $encoding = trim($matches[1]);
                $quality = isset($matches[2]) ? (float) $matches[2] : 1.0;
                
                if ($quality > 0) {
                    $encodings[$encoding] = $quality;
                }
            }
        }
        
        // Sort by quality (highest first)
        arsort($encodings);
        return $encodings;
    }

    private function isCompressibleContentType(string $contentType): bool
    {
        // Extract main content type (before semicolon)
        $mainType = strtolower(explode(';', $contentType)[0]);
        $mainType = trim($mainType);
        
        return in_array($mainType, $this->config['content_types']);
    }

    private function updateVaryHeader(?string $varyHeader): string
    {
        $varyValues = [];
        
        if ($varyHeader) {
            $varyValues = array_map('trim', explode(',', $varyHeader));
        }
        
        if (!in_array('Accept-Encoding', $varyValues)) {
            $varyValues[] = 'Accept-Encoding';
        }
        
        return implode(', ', $varyValues);
    }

    private function loadConfiguration(): void
    {
        $this->config = array_merge($this->config, [
            'enable_brotli' => (bool) ($_ENV['COMPRESSION_BROTLI_ENABLED'] ?? true),
            'enable_gzip' => (bool) ($_ENV['COMPRESSION_GZIP_ENABLED'] ?? true),
            'min_size' => (int) ($_ENV['COMPRESSION_MIN_SIZE'] ?? 1024),
            'max_size' => (int) ($_ENV['COMPRESSION_MAX_SIZE'] ?? 50 * 1024 * 1024),
        ]);

        // Load custom content types if specified
        $customTypes = $_ENV['COMPRESSION_CONTENT_TYPES'] ?? '';
        if ($customTypes) {
            $this->config['content_types'] = array_map('trim', explode(',', $customTypes));
        }

        // Load excluded extensions if specified
        $excludedExtensions = $_ENV['COMPRESSION_EXCLUDED_EXTENSIONS'] ?? '';
        if ($excludedExtensions) {
            $this->config['excluded_extensions'] = array_map('trim', explode(',', $excludedExtensions));
        }
    }

    public function getStats(): array
    {
        $processed = $this->stats['requests_processed'];
        $compressed = $this->stats['responses_compressed'];
        
        return array_merge($this->stats, [
            'compression_rate' => $processed > 0 ? round($compressed / $processed * 100, 2) : 0,
            'average_savings' => $compressed > 0 ? round($this->stats['bytes_saved'] / $compressed) : 0,
            'brotli_usage_rate' => $compressed > 0 ? round($this->stats['brotli_used'] / $compressed * 100, 2) : 0,
            'gzip_usage_rate' => $compressed > 0 ? round($this->stats['gzip_used'] / $compressed * 100, 2) : 0,
            'compressor_stats' => $this->compressor->getStats(),
            'configuration' => $this->config
        ]);
    }

    public function getConfiguration(): array
    {
        return $this->config;
    }

    public function setConfiguration(array $config): void
    {
        $this->config = array_merge($this->config, $config);
        $this->logger->info('Compression middleware configuration updated', $config);
    }

    public function resetStats(): void
    {
        $this->stats = [
            'requests_processed' => 0,
            'responses_compressed' => 0,
            'bytes_saved' => 0,
            'brotli_used' => 0,
            'gzip_used' => 0,
            'compression_skipped' => 0
        ];
    }
}