<?php
declare(strict_types=1);

namespace AperturePro\Config;

class Validator
{
    /**
     * Validate and sanitize configuration array.
     *
     * @param array $config
     * @return array
     */
    public static function validate(array $config): array
    {
        $validated = [];

        foreach ($config as $key => $value) {
            $validated[$key] = self::sanitize($key, $value);
        }

        return $validated;
    }

    /**
     * Sanitize individual setting based on key or type.
     *
     * @param string $key
     * @param mixed $value
     * @return mixed
     */
    private static function sanitize(string $key, $value)
    {
        if (is_bool($value)) {
            return (bool) $value;
        }

        if (is_int($value)) {
            return (int) $value;
        }

        if (is_string($value)) {
            return sanitize_text_field($value);
        }

        return $value;
    }
}
