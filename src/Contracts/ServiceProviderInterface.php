<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Contracts;

/**
 * Service Provider Interface
 * 
 * Contract for all service providers in the HighPer framework.
 * Follows the simple ea-rapid pattern for service providers.
 */
interface ServiceProviderInterface
{
    /**
     * Register any application services.
     */
    public function register(): void;

    /**
     * Boot any application services.
     */
    public function boot(): void;
}