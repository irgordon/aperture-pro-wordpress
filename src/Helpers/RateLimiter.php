<?php

namespace AperturePro\Helpers;

/**
 * RateLimiter
 *
 * Provides a simple rate limiting mechanism using WordPress transients.
 */
class RateLimiter
{
    /**
     * Check if an action is allowed within a rate limit.
     * Increments the counter if allowed.
     *
     * @param string $key    Unique key for the action (e.g. 'download_ip_127.0.0.1')
     * @param int    $limit  Maximum allowed attempts
     * @param int    $window Time window in seconds
     * @return bool          True if allowed, False if limit exceeded
     */
    public static function check(string $key, int $limit, int $window): bool
    {
        $count = (int) get_transient($key);

        if ($count >= $limit) {
            return false;
        }

        // Increment count
        $count++;

        // Update transient
        // This resets the TTL on every hit, creating a "sliding window" effect.
        set_transient($key, $count, $window);

        return true;
    }
}
