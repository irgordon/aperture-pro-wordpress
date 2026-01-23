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
     * Plugin environment context.
     */
    protected Environment $environment;

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
     * @param Environment $environment Plugin environment
     */
    public function __construct(Environment $environment)
    {
        $this->environment = $environment;
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
            $service = $this->resolveService($class);

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
     * Resolve service instance with dependency injection.
     *
     * @param string $class Fully qualified class name
     * @return object
     * @throws \ReflectionException
     */
    protected function resolveService(string $class): object
    {
        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if (!$constructor) {
            return new $class();
        }

        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();
            $type = $param->getType();

            // Handle built-in types (specifically string for path)
            if ($type instanceof \ReflectionNamedType && $type->isBuiltin()) {
                if ($type->getName() === 'string' && ($name === 'path' || $name === 'pluginPath')) {
                    $args[] = $this->environment->getPath();
                } elseif ($param->isOptional()) {
                    $args[] = $param->getDefaultValue();
                }
                continue;
            }

            // Handle class dependencies
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $dependencyClass = $type->getName();

                // 1. Environment Injection
                if ($dependencyClass === Environment::class) {
                    $args[] = $this->environment;
                    continue;
                }

                // 2. Existing Service Injection
                if (isset($this->services[$dependencyClass])) {
                    $args[] = $this->services[$dependencyClass];
                    continue;
                }

                // 3. Recursive Registration
                if (class_exists($dependencyClass)) {
                    $this->registerService($dependencyClass);
                    if (isset($this->services[$dependencyClass])) {
                        $args[] = $this->services[$dependencyClass];
                        continue;
                    }
                }
            }

            // Fallback for optional parameters
            if ($param->isOptional()) {
                $args[] = $param->getDefaultValue();
            } else {
                // Argument cannot be resolved.
                // We rely on new $class() triggering the error naturally if arguments are missing.
            }
        }

        return $reflection->newInstanceArgs($args);
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
     * Get the plugin environment.
     *
     * @return Environment
     */
    public function getEnvironment(): Environment
    {
        return $this->environment;
    }

    /**
     * Internal logging helper.
     */
    protected function log(string $level, string $message): void
    {
        if (class_exists(Logger::class)) {
            Logger::log($level, 'loader', $message, [
                'version' => $this->environment->getVersion(),
            ]);
        }
    }
}
