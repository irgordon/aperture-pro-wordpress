<?php

namespace AperturePro\Storage;

use AperturePro\Config\Config;

abstract class AbstractStorage implements StorageInterface
{
    /**
     * Per-request signed URL cache.
     *
     * @var array<string,string>
     */
    protected static array $requestCache = [];

    /**
     * Sign a single path with layered caching.
     */
    public function sign(string $path): ?string
    {
        $cacheKey = static::class . '|' . $path;

        // 1️⃣ Request-level cache
        if (isset(self::$requestCache[$cacheKey])) {
            return self::$requestCache[$cacheKey];
        }

        // 2️⃣ Optional cross-request cache
        $ttl = (int) Config::get('signed_url_cache_ttl', 60);
        if ($ttl > 0) {
            $cached = wp_cache_get($cacheKey, 'ap_signed_urls');
            if ($cached) {
                self::$requestCache[$cacheKey] = $cached;
                return $cached;
            }
        }

        // 3️⃣ Provider signing
        $url = $this->signInternal($path);
        if (!$url) {
            return null;
        }

        // Populate caches
        self::$requestCache[$cacheKey] = $url;

        if ($ttl > 0) {
            wp_cache_set($cacheKey, $url, 'ap_signed_urls', $ttl);
        }

        return $url;
    }

    /**
     * Batch sign with cache awareness.
     *
     * @param string[] $paths
     * @return array<string,string>
     */
    public function signMany(array $paths): array
    {
        $results = [];
        $toSign  = [];
        $ttl     = (int) Config::get('signed_url_cache_ttl', 60);

        foreach ($paths as $path) {
            $cacheKey = static::class . '|' . $path;

            // Request cache
            if (isset(self::$requestCache[$cacheKey])) {
                $results[$path] = self::$requestCache[$cacheKey];
                continue;
            }

            // Cross-request cache
            if ($ttl > 0) {
                $cached = wp_cache_get($cacheKey, 'ap_signed_urls');
                if ($cached) {
                    self::$requestCache[$cacheKey] = $cached;
                    $results[$path] = $cached;
                    continue;
                }
            }

            $toSign[] = $path;
        }

        if ($toSign) {
            $signed = $this->signManyInternal($toSign);

            foreach ($signed as $path => $url) {
                $cacheKey = static::class . '|' . $path;

                self::$requestCache[$cacheKey] = $url;
                $results[$path] = $url;

                if ($ttl > 0) {
                    wp_cache_set($cacheKey, $url, 'ap_signed_urls', $ttl);
                }
            }
        }

        return $results;
    }

    /**
     * Provider-specific single signing.
     */
    abstract protected function signInternal(string $path): ?string;

    /**
     * Provider-specific batch signing.
     *
     * @param string[] $paths
     * @return array<string,string>
     */
    abstract protected function signManyInternal(array $paths): array;
}
