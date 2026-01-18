<?php
declare(strict_types=1);

namespace AperturePro\Security;

final class RateLimiter
{
    /**
     * Attempt a rate-limited action.
     *
     * @return array{allowed:bool, remaining:int, reset_in:int, limit:int}
     */
    public function attempt(string $key, int $limit, int $window_seconds): array
    {
        $key = $this->normalize_key($key);

        $bucket_key = 'ap_rl_' . md5($key);
        $reset_key  = 'ap_rlr_' . md5($key);

        $count = (int) get_transient($bucket_key);
        $reset_at = (int) get_transient($reset_key);

        $now = time();

        // Initialize window if not present.
        if ($reset_at <= 0 || $reset_at < $now) {
            $reset_at = $now + $window_seconds;
            set_transient($bucket_key, 0, $window_seconds);
            set_transient($reset_key, $reset_at, $window_seconds);
            $count = 0;
        }

        $count++;

        // Persist the increment with remaining TTL.
        $ttl = max(1, $reset_at - $now);
        set_transient($bucket_key, $count, $ttl);
        set_transient($reset_key, $reset_at, $ttl);

        $allowed = ($count <= $limit);

        return [
            'allowed'   => $allowed,
            'remaining' => max(0, $limit - $count),
            'reset_in'  => $ttl,
            'limit'     => $limit,
        ];
    }

    private function normalize_key(string $key): string
    {
        $key = strtolower(trim($key));
        // Prevent absurdly long transient key material.
        return substr($key, 0, 500);
    }
}
