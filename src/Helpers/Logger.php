<?php

namespace AperturePro\Helpers;

class Logger
{
    /**
     * Buffer for log entries to be batch inserted.
     * @var array
     */
    private static $buffer = [];

    /**
     * Maximum number of logs to hold in memory before flushing.
     * @var int
     */
    private static $bufferLimit = 50;

    /**
     * Whether the shutdown handler has been registered.
     * @var bool
     */
    private static $registered = false;

    /**
     * Writes a log entry to the ap_logs table (buffered).
     *
     * @param string $level   e.g., 'info', 'warning', 'error'
     * @param string $context short context string
     * @param string $message human readable message
     * @param array  $meta    optional metadata (will be JSON encoded)
     */
    public static function log(string $level, string $context, string $message, array $meta = []): void
    {
        $traceId = $meta['trace_id'] ?? null;

        $entry = [
            'level'      => $level,
            'context'    => $context,
            'message'    => $message,
            'trace_id'   => $traceId,
            'meta'       => !empty($meta) ? wp_json_encode($meta) : null,
            'created_at' => current_time('mysql'),
        ];

        self::$buffer[] = $entry;

        // Register shutdown flush once
        if (!self::$registered) {
            register_shutdown_function([self::class, 'flush']);
            self::$registered = true;
        }

        // Auto-flush if buffer is full
        if (count(self::$buffer) >= self::$bufferLimit) {
            self::flush();
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
                // Use async queue to avoid blocking main thread
                \AperturePro\Email\EmailService::enqueueAdminNotification($level, $context, $message, $meta);
            } catch (\Throwable $e) {
                // If email fails, log to PHP error log but do not throw.
                error_log('AperturePro admin notification failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Flushes the log buffer to the database in a single batch insert.
     */
    public static function flush(): void
    {
        if (empty(self::$buffer)) {
            return;
        }

        global $wpdb;

        // Safety check: if wpdb is gone (e.g. extremely late shutdown), we can't log to DB.
        if (!isset($wpdb) || !is_object($wpdb)) {
            return;
        }

        $table = $wpdb->prefix . 'ap_logs';

        // Take a snapshot and clear the main buffer immediately
        $entries = self::$buffer;
        self::$buffer = [];

        $placeholders = [];
        $values = [];

        foreach ($entries as $entry) {
            $placeholders[] = '(%s, %s, %s, %s, %s, %s)';
            $values[] = $entry['level'];
            $values[] = $entry['context'];
            $values[] = $entry['message'];
            $values[] = $entry['trace_id'];
            $values[] = $entry['meta'];
            $values[] = $entry['created_at'];
        }

        if (empty($placeholders)) {
            return;
        }

        $query = "INSERT INTO $table (level, context, message, trace_id, meta, created_at) VALUES " . implode(', ', $placeholders);

        try {
            $sql = $wpdb->prepare($query, $values);
            if ($sql) {
                $wpdb->query($sql);
            }
        } catch (\Throwable $e) {
            error_log(sprintf(
                'AperturePro Logger batch failure: %s | count: %d',
                $e->getMessage(),
                count($entries)
            ));
        }
    }
}
