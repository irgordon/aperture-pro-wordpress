<?php

namespace AperturePro\Helpers;

class Utils
{
    /**
     * Safely decode JSON into array, returning default on failure.
     *
     * @param string|null $json
     * @param mixed       $default
     * @return mixed
     */
    public static function safeJsonDecode(?string $json, $default = null)
    {
        if ($json === null || $json === '') {
            return $default;
        }

        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $default;
        }

        return $decoded;
    }

    /**
     * Ensure a value is an integer, with optional default.
     *
     * @param mixed $value
     * @param int   $default
     * @return int
     */
    public static function toInt($value, int $default = 0): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * Return current time in MySQL DATETIME format.
     *
     * @return string
     */
    public static function nowMysql(): string
    {
        return current_time('mysql');
    }

    /**
     * Normalize a boolean-ish value.
     *
     * @param mixed $value
     * @return bool
     */
    public static function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            $v = strtolower($value);
            return in_array($v, ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }
}
