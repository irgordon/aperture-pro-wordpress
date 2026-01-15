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

        $traceId = $meta['trace_id'] ?? null;

        $data = [
            'level'      => $level,
            'context'    => $context,
            'message'    => $message,
            'trace_id'   => $traceId,
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
            error_log(sprintf(
                'AperturePro Logger failure: %s | original: %s | meta: %s',
                $e->getMessage(),
                $message,
                !empty($meta) ? wp_json_encode($meta) : '{}'
            ));
        }

        // Decide whether to notify admin by email.
        $shouldNotify = false;

        // Explicit override in meta
        if (!empty($meta['notify_admin'])) {
            $shouldNotify = true;
        }

        // Conservative automatic notification for high-impact errors
        $criticalContexts = ['installer', 'local_storage', 'download', 'storage', 'migration'];
        if ($level === 'error' && in_array($context, $criticalContexts, true)) {
            $shouldNotify = true;
        }

        if ($shouldNotify) {
            try {
                self::notifyAdminByEmail($level, $context, $message, $meta);
            } catch (\Throwable $e) {
                // If email fails, log to PHP error log but do not throw.
                error_log('AperturePro admin notification failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Send a concise email to the site admin with details about the critical event.
     *
     * This method is intentionally simple. For production you may want to:
     *  - Rate-limit notifications
     *  - Aggregate similar errors
     *  - Use a dedicated alerting system
     */
    protected static function notifyAdminByEmail(string $level, string $context, string $message, array $meta = []): void
    {
        $adminEmail = get_option('admin_email');
        if (empty($adminEmail)) {
            return;
        }

        $subject = sprintf('[Aperture Pro] %s: %s', strtoupper($level), $context);

        $body = "Aperture Pro has detected an issue that requires attention.\n\n";
        $body .= "Level: {$level}\n";
        $body .= "Context: {$context}\n";
        $body .= "Message: {$message}\n";
        $body .= "Time: " . current_time('mysql') . "\n\n";

        if (!empty($meta)) {
            $body .= "Meta:\n" . print_r($meta, true) . "\n\n";
        }

        // Include a short link to the Admin Command Center health card (if available)
        $adminUrl = admin_url('admin.php?page=aperture-pro-command');
        $body .= "Admin Command Center: {$adminUrl}\n\n";
        $body .= "This is an automated message from Aperture Pro.";

        // Use wp_mail; if it fails, wp_mail returns false.
        @wp_mail($adminEmail, $subject, $body);
    }
}
