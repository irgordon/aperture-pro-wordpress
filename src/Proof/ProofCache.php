<?php

namespace AperturePro\Proof;

/**
 * ProofCache
 *
 * Caches signed proof URLs to avoid redundant signing operations
 * and storage API calls.
 *
 * Strategies:
 *  - Uses WordPress transients for storage.
 *  - Default TTL of 15 minutes (900s).
 *  - Keys should include a hash of the image set to ensure correctness.
 */
class ProofCache
{
    /**
     * Cache TTL in seconds (15 minutes).
     */
    const TTL = 900;

    /**
     * Cache key prefix.
     */
    const PREFIX = 'proof_cache_';

    /**
     * Get cached URLs.
     *
     * @param string $key Unique cache key.
     * @return array|null Cached URLs or null if miss.
     */
    public static function get(string $key): ?array
    {
        $cacheKey = self::PREFIX . $key;
        // In WP, get_transient returns false if expired or not found.
        $cached = get_transient($cacheKey);

        if ($cached === false || !is_array($cached)) {
            return null;
        }

        return $cached;
    }

    /**
     * Set cached URLs.
     *
     * @param string $key  Unique cache key.
     * @param array  $urls Map of image ID/path => signed URL.
     * @param int    $ttl  Optional TTL override.
     * @return bool
     */
    public static function set(string $key, array $urls, int $ttl = self::TTL): bool
    {
        $cacheKey = self::PREFIX . $key;
        return set_transient($cacheKey, $urls, $ttl);
    }

    /**
     * Invalidate cache.
     *
     * @param string $key Unique cache key.
     */
    public static function invalidate(string $key): void
    {
        $cacheKey = self::PREFIX . $key;
        delete_transient($cacheKey);
    }

    /**
     * Generate a robust cache key.
     *
     * @param string|int $contextId Gallery ID, Project ID, or other context.
     * @param array      $images    List of images (to include in hash).
     * @return string
     */
    public static function generateKey($contextId, array $images): string
    {
        $paths = [];
        foreach ($images as $image) {
            // Use path or filename or ID to identify the image content
            $paths[] = $image['path'] ?? $image['filename'] ?? $image['id'] ?? '';
        }

        // Create a hash of the content to ensure uniqueness
        $hash = md5(json_encode($paths));

        return "{$contextId}_{$hash}";
    }
}
