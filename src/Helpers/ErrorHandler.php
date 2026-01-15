<?php

namespace AperturePro\Helpers;

class ErrorHandler
{
    /**
     * Generates a trace id for correlating logs and responses.
     *
     * @return string 32+ hex characters
     */
    public static function traceId(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            // Fallback to uniqid if random_bytes is unavailable for some reason.
            return uniqid('ap_', true);
        }
    }
}
