<?php

namespace AperturePro\Helpers;

class Nonce
{
    /**
     * Create a WP nonce for a given action.
     *
     * @param string $action
     * @return string
     */
    public static function create(string $action = 'aperture_pro'): string
    {
        if (!function_exists('wp_create_nonce')) {
            return '';
        }
        return wp_create_nonce($action);
    }

    /**
     * Verify a WP nonce for a given action.
     *
     * @param string $nonce
     * @param string $action
     * @return bool
     */
    public static function verify(string $nonce, string $action = 'aperture_pro'): bool
    {
        if (!function_exists('wp_verify_nonce')) {
            return false;
        }

        $result = wp_verify_nonce($nonce, $action);

        // wp_verify_nonce returns 1, 2, or false. Treat 1 or 2 as valid.
        return $result === 1 || $result === 2;
    }
}
