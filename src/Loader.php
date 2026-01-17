<?php

namespace AperturePro;

use AperturePro\Helpers\Logger;

/**
 * Loader
 *
 * Orchestrates the loading of plugin services.
 */
class Loader
{
    protected string $file;
    protected string $path;
    protected string $url;
    protected string $version;

    protected array $services = [];
    protected bool $booted = false;

    /**
     * @param string $file    Main plugin file path
     * @param string $path    Plugin directory path
     * @param string $url     Plugin URL
     * @param string $version Plugin version
     */
    public function __construct(string $file, string $path, string $url, string $version)
    {
        $this->file = $file;
        $this->path = $path;
        $this->url = $url;
        $this->version = $version;
    }

    /**
     * Boot the plugin by registering default services.
     * Idempotent: safe to call multiple times.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->register_default_services();
        $this->booted = true;
    }

    /**
     * Register core services.
     */
    protected function register_default_services(): void
    {
        $this->register_service(\AperturePro\Services\Admin::class);
        $this->register_service(\AperturePro\Services\ClientPortal::class);
        $this->register_service(\AperturePro\Services\REST::class);
        $this->register_service(\AperturePro\Services\Email::class);
    }

    /**
     * Instantiate and register a service class.
     *
     * @param string $class Fully qualified class name
     */
    public function register_service(string $class): void
    {
        // Fail-soft: if class doesn't exist, log and skip
        if (!class_exists($class)) {
            if (class_exists(Logger::class)) {
                Logger::log('warning', 'loader', "Service class {$class} not found. Skipping.");
            }
            return;
        }

        // Avoid double registration
        if (isset($this->services[$class])) {
            return;
        }

        try {
            $service = new $class();
            if (method_exists($service, 'register')) {
                $service->register();
                $this->services[$class] = $service;
            } else {
                if (class_exists(Logger::class)) {
                    Logger::log('warning', 'loader', "Service class {$class} does not implement register(). Skipping.");
                }
            }
        } catch (\Throwable $e) {
            // Fail-soft: catch exception during registration
            if (class_exists(Logger::class)) {
                Logger::log('error', 'loader', "Failed to register service {$class}: " . $e->getMessage());
            }
        }
    }
}
