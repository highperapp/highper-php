# HighPer Framework v1 - Complete Installation Guide

## Prerequisites

### System Requirements

- **PHP**: 8.3+ (8.4 recommended)
- **Memory**: 256MB+ (512MB+ for development)
- **OS**: Linux (recommended), macOS, Windows with WSL2
- **CPU**: x64 architecture (ARM64 support available)

### Required PHP Extensions

```bash
# Essential extensions
php -m | grep -E "(ffi|opcache|pcntl|posix|sockets)"

# If missing, install:
# Ubuntu/Debian
sudo apt install php8.3-ffi php8.3-opcache php8.3-pcntl php8.3-posix php8.3-sockets

# CentOS/RHEL/Rocky
sudo dnf install php-ffi php-opcache php-pcntl php-posix php-sockets

# macOS with Homebrew
brew install php@8.3
```

## Basic Installation (Without Rust FFI)

### 1. Install Framework Core

```bash
# Create new project with Blueprint template
composer create-project highperapp/blueprint my-app
cd my-app

# Or install framework directly
composer require highperapp/highper-php
```

### 2. Configure Environment

```bash
# Copy environment file
cp .env.example .env

# Edit configuration
nano .env
```

### 3. Run Application

```bash
# Development server
php bin/serve

# Production server
php bin/serve --env=production --workers=auto
```

## Advanced Installation (With Rust FFI)

### 1. Install Rust Toolchain

#### Linux/macOS
```bash
# Install Rust
curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh
source ~/.cargo/env

# Verify installation
rustc --version
cargo --version
```

#### Windows
```powershell
# Download and run rustup-init.exe from https://rustup.rs/
# Or use chocolatey
choco install rust

# Verify installation
rustc --version
cargo --version
```

### 2. Configure Rust for FFI

```bash
# Add target for your platform
# Linux x64
rustup target add x86_64-unknown-linux-gnu

# macOS x64
rustup target add x86_64-apple-darwin

# macOS ARM64
rustup target add aarch64-apple-darwin

# Windows x64
rustup target add x86_64-pc-windows-msvc
```

### 3. Install HighPer Framework with Rust Components

```bash
# Clone the complete framework
git clone https://github.com/highperapp/highper-php-v3.git
cd highper-php-v3

# Install PHP dependencies
composer install

# Build Rust components
./scripts/build-rust-components.sh
```

### 4. Verify Rust FFI Installation

```bash
# Check FFI extension
php -m | grep ffi

# Test Rust component loading
php scripts/test-rust-ffi.php
```

## Component-Specific Installation

### Framework Core

```bash
# Install framework core
cd core/framework
composer install

# Run tests
php run-all-tests.php

# Start development server
php -S localhost:8080 -t public
```

### Blueprint Enterprise Template

```bash
# Install Blueprint
cd templates/blueprint
composer install

# Setup environment
cp .env.example .env

# Generate application key
php bin/generate-key

# Setup database (optional)
php bin/setup-database

# Start server
php bin/serve --port=8080
```

#### Blueprint with Full Features

```bash
# Install with all optional dependencies
cd templates/blueprint
composer require highperapp/websockets
composer require highperapp/database
composer require highperapp/cache
composer require highperapp/monitoring
composer require highperapp/security

# Enable features in .env
echo "WEBSOCKET_ENABLED=true" >> .env
echo "DATABASE_ENABLED=true" >> .env
echo "CACHE_ENABLED=true" >> .env
echo "MONITORING_ENABLED=true" >> .env
echo "SECURITY_LEVEL=enterprise" >> .env

# Build Rust components for maximum performance
./scripts/build-rust-blueprint.sh

# Start with all features
php bin/serve --features=all
```

### Nano Minimal Template

```bash
# Install Nano
cd templates/nano
composer install

# Setup minimal environment
cp .env.example .env

# Start minimal server
php server.php
```

#### Nano with Optional Performance

```bash
# Add optional Rust components for performance
./scripts/build-rust-nano.sh

# Enable Rust FFI in .env
echo "RUST_FFI_ENABLED=true" >> .env

# Start with Rust acceleration
php server.php --rust
```

### Standalone Libraries

#### DI Container

```bash
cd libraries/di-container
composer install

# Build compiler components
php bin/build-container-compiler.php

# Run tests
php tests/run-all-tests.php
```

#### Router

```bash
cd libraries/router
composer install

# Build Rust routing engine (optional)
cd rust && ./build.sh && cd ..

# Run performance tests
php bin/benchmark-router.php
```

#### WebSockets

```bash
cd libraries/websockets
composer install

# Setup WebSocket configuration
cp .env.example .env
echo "WEBSOCKET_PORT=8081" >> .env

# Start WebSocket server
php bin/websocket-server.php
```

#### Security

```bash
cd libraries/security
composer install

# Setup security configuration
cp .env.example .env

# Generate encryption keys
php bin/generate-security-keys.php

# Run compliance tests
php bin/test-compliance.php
```

## Docker Installation

### Basic Docker Setup

```dockerfile
# Dockerfile
FROM php:8.3-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libffi-dev \
    && docker-php-ext-install ffi opcache pcntl posix sockets

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# Copy application
COPY . .

# Install dependencies
RUN composer install --no-dev --optimize-autoloader

# Expose port
EXPOSE 8080

# Start application
CMD ["php", "bin/serve", "--host=0.0.0.0", "--port=8080"]
```

### Docker with Rust FFI

```dockerfile
# Dockerfile.rust
FROM rust:1.70 as rust-builder

WORKDIR /usr/src/app
COPY rust/ ./
RUN cargo build --release

FROM php:8.3-cli

# Install system dependencies including Rust runtime
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libffi-dev \
    && docker-php-ext-install ffi opcache pcntl posix sockets

# Copy Rust libraries
COPY --from=rust-builder /usr/src/app/target/release/*.so /usr/local/lib/

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www
COPY . .

RUN composer install --no-dev --optimize-autoloader

EXPOSE 8080
CMD ["php", "bin/serve", "--host=0.0.0.0", "--port=8080", "--rust"]
```

### Docker Compose Setup

```yaml
# docker-compose.yml
version: '3.8'

services:
  highper-app:
    build: .
    ports:
      - "8080:8080"
      - "8081:8081"  # WebSocket port
    environment:
      - APP_ENV=production
      - RUST_FFI_ENABLED=true
    volumes:
      - ./storage:/var/www/storage
      - ./logs:/var/www/logs
    depends_on:
      - redis
      - mysql

  redis:
    image: redis:7-alpine
    ports:
      - "6379:6379"

  mysql:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: secret
      MYSQL_DATABASE: highper_app
    ports:
      - "3306:3306"
    volumes:
      - mysql_data:/var/lib/mysql

volumes:
  mysql_data:
```

## Performance Optimization

### PHP Configuration

```ini
; php.ini optimizations for HighPer Framework

; Memory settings
memory_limit = 512M
max_execution_time = 0

; OPcache settings
opcache.enable = 1
opcache.enable_cli = 1
opcache.memory_consumption = 256
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 10000
opcache.validate_timestamps = 0
opcache.save_comments = 0
opcache.fast_shutdown = 1

; FFI settings
ffi.enable = "preload"

; Process control
pcntl.async_signals = 1
```

### System Optimizations

```bash
# Linux kernel optimizations
echo 'net.core.somaxconn = 65535' >> /etc/sysctl.conf
echo 'net.ipv4.tcp_max_syn_backlog = 65535' >> /etc/sysctl.conf
echo 'net.core.netdev_max_backlog = 5000' >> /etc/sysctl.conf
echo 'fs.file-max = 1000000' >> /etc/sysctl.conf
sysctl -p

# Increase ulimits
echo '* soft nofile 1000000' >> /etc/security/limits.conf
echo '* hard nofile 1000000' >> /etc/security/limits.conf
```

## Troubleshooting

### Common Issues

#### FFI Extension Missing
```bash
# Ubuntu/Debian
sudo apt install php8.3-ffi

# CentOS/RHEL
sudo dnf install php-ffi

# Verify
php -m | grep ffi
```

#### Rust Build Failures
```bash
# Update Rust
rustup update

# Clean build
cargo clean
cargo build --release

# Check for missing dependencies
ldd target/release/lib*.so
```

#### Permission Issues
```bash
# Fix storage permissions
chmod -R 755 storage/
chmod -R 755 logs/

# Fix Rust library permissions
chmod 755 rust/*.so
```

#### Memory Limit Issues
```bash
# Increase PHP memory limit
echo "memory_limit = 512M" >> /etc/php/8.3/cli/php.ini

# For development
php -d memory_limit=1G bin/serve
```

### Performance Issues

#### Low Concurrency
```bash
# Check system limits
ulimit -n

# Increase if needed
ulimit -n 100000

# Check PHP configuration
php --ini | grep fpm
```

#### Slow Rust FFI
```bash
# Verify Rust optimization
cargo build --release

# Check FFI preloading
php -i | grep ffi
```

## Verification

### Test Installation

```bash
# Run framework tests
php run-all-tests.php

# Test performance
php bin/benchmark.php

# Test Rust FFI
php scripts/test-rust-components.php

# Test WebSocket
php bin/test-websocket.php
```

### Performance Validation

```bash
# Benchmark HTTP server
wrk2 -t4 -c100 -d30s -R1000 http://localhost:8080/

# Test memory usage
php bin/memory-test.php

# Validate zero memory leaks
php tests/Performance/MemoryLeakDetectionTest.php
```

## Production Deployment

### Systemd Service

```ini
# /etc/systemd/system/highper-app.service
[Unit]
Description=HighPer Framework Application
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/highper-app
ExecStart=/usr/bin/php bin/serve --env=production --workers=auto
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

### Nginx Configuration

```nginx
server {
    listen 80;
    server_name example.com;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    }

    location /ws {
        proxy_pass http://127.0.0.1:8081;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
    }
}
```

This installation guide provides comprehensive coverage for all installation scenarios from basic PHP-only setup to advanced Rust FFI-enabled deployments.