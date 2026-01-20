<?php

namespace AperturePro;

use AperturePro\Helpers\Logger;
use AperturePro\Services\ServiceInterface;

/**
 * Loader
 *
 * Orchestrates the loading of plugin services.
 *
 * Responsibilities:
 * - Instantiate and register core services
 * - Ensure idempotent boot behavior
 * - Fail-soft when optional services are unavailable
 */
class Loader
{
    /**
     * Plugin version string.
     * Reserved for cache-busting and diagnostics.
     */
    protected string $version;

    /**
     * Registered service instances keyed by class name.
     *
     * @var array<string,object>
     */
    protected array $services = [];

    /**
     * Whether the loader has already booted.
     */
    protected bool $booted = false;

    /**
     * @param string $version Plugin version
     */
    public function __construct(string $version)
    {
        $this->version = $version;
    }

    /**
     * Boot the plugin by registering default services.
     *
     * Idempotent: safe to call multiple times.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->registerDefaultServices();
        $this->booted = true;
    }

    /**
     * Register core services.
     */
    protected function registerDefaultServices(): void
    {
        $this->registerService(\AperturePro\Services\Admin::class);
        $this->registerService(\AperturePro\Services\ClientPortal::class);
        $this->registerService(\AperturePro\Services\REST::class);
        $this->registerService(\AperturePro\Services\Email::class);
        $this->registerService(\AperturePro\Services\Proof::class);
    }

    /**
     * Instantiate and register a service class.
     *
     * @param string $class Fully qualified class name
     */
    public function registerService(string $class): void
    {
        // Fail-soft: missing class
        if (!class_exists($class)) {
            $this->log('warning', "Service class {$class} not found. Skipping.");
            return;
        }

        // Avoid double registration
        if (isset($this->services[$class])) {
            return;
        }

        try {
            $service = new $class();

            // Prefer explicit interface when available
            if ($service instanceof ServiceInterface) {
                $service->register();
            } elseif (method_exists($service, 'register')) {
                // Backward-compatible fallback
                $service->register();
            } else {
                $this->log('warning', "Service class {$class} does not implement register(). Skipping.");
                return;
            }

            $this->services[$class] = $service;

        } catch (\Throwable $e) {
            // Fail-soft: catch exception during registration
            $this->log(
                'error',
                "Failed to register service {$class}: {$e->getMessage()}"
            );
        }
    }

    /**
     * Retrieve all registered services.
     *
     * Useful for diagnostics, testing, or admin health checks.
     *
     * @return array<string,object>
     */
    public function getServices(): array
    {
        return $this->services;
    }

    /**
     * Internal logging helper.
     */
    protected function log(string $level, string $message): void
    {
        if (class_exists(Logger::class)) {
            Logger::log($level, 'loader', $message, [
                'version' => $this->version,
            ]);
        }
    }
}
