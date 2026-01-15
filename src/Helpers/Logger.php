<?php

namespace AperturePro\Helpers;

class Logger
{
    /**
     * Writes a log entry to the ap_logs table.
     *
     * @param string $level   e.g., 'info', 'warning', 'error'
     * @param string $context short context string
     * @param string $message human readable message
     * @param array  $meta    optional metadata (will be JSON encoded)
     */
    public static function log(string $level, string $context, string $message, array $meta = []): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'ap_logs';

        $data = [
            'level'      => $level,
            'context'    => $context,
            'message'    => $message,
            'trace_id'   => $meta['trace_id'] ?? null,
            'meta'       => !empty($meta) ? wp_json_encode($meta) : null,
            'created_at' => current_time('mysql'),
        ];

        $format = [
            '%s', // level
            '%s', // context
            '%s', // message
            '%s', // trace_id
            '%s', // meta
            '%s', // created_at
        ];

        // Fail-soft: never throw from logger. If DB insert fails, fallback to error_log.
        try {
            $wpdb->insert($table, $data, $format);
        } catch (\Throwable $e) {
            // If $wpdb isn't available or insert fails, write to PHP error log as a last resort.
            error_log(sprintf(
                'AperturePro Logger failure: %s | original: %s | meta: %s',
                $e->getMessage(),
                $message,
                !empty($meta) ? wp_json_encode($meta) : '{}'
            ));
        }
    }
}
