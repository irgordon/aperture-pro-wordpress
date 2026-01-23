<?php

namespace AperturePro;

/**
 * Environment
 *
 * Encapsulates the plugin environment context (paths, URLs, version).
 * Passed to services via dependency injection to avoid global constant usage.
 */
class Environment
{
    protected string $path;
    protected string $url;
    protected string $version;

    public function __construct(string $path, string $url, string $version)
    {
        $this->path = $path;
        $this->url = $url;
        $this->version = $version;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getVersion(): string
    {
        return $this->version;
    }
}
