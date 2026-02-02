<?php

namespace AperturePro\Email;

use AperturePro\Helpers\Logger;

/**
 * EmailService
 *
 * - sendTemplate() remains for normal transactional emails to clients.
 * - enqueueAdminNotification() queues admin emails to avoid email storms.
 * - A WP Cron job processes the admin email queue at a controlled rate.
 *
 * Queue storage:
 *  - Stored in an option 'ap_admin_email_queue' as an array of items.
 *  - Each item: ['level','context','subject','body','meta','created_at']
 *
 * Rate limiting:
 *  - The processor will send up to MAX_PER_RUN emails per run and will dedupe similar contexts.
 */
class EmailService
{
    const TEMPLATE_PATH = __DIR__ . '/Templates/';
    const ADMIN_QUEUE_OPTION = 'ap_admin_email_queue';
    const ADMIN_QUEUE_LOCK = 'ap_admin_email_queue_lock';
    const CRON_HOOK = 'aperture_pro_send_admin_emails';

    // Legacy option constant kept for backward compatibility
    const TRANSACTIONAL_QUEUE_OPTION = 'ap_transactional_email_queue';

    const TRANSACTIONAL_CRON_HOOK = 'aperture_pro_send_transactional_emails';
    const TRANSACTIONAL_QUEUE_LOCK = 'ap_transactional_queue_lock';
    const TRANSACTIONAL_MAX_PER_RUN = 5;
    const MAX_PER_RUN = 3; // send up to 3 admin emails per cron run
    const DEDUPE_WINDOW = 900; // 15 minutes dedupe window for same context

    protected static $_tableExists = null;
    protected static ?bool $adminQueueTableExistsCache = null;
    protected static array $dedupeCache = [];

    protected static function tableExists(): bool
    {
        if (self::$_tableExists !== null) {
            return self::$_tableExists;
        }

        // Performance: Check persistent cache first
        $cacheKey = 'ap_email_queue_table_exists';
        $cached = get_transient($cacheKey);
        if ($cached !== false) {
            self::$_tableExists = (bool) $cached;
            return self::$_tableExists;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ap_email_queue';
        // Check using a cheap query that hits information_schema or just check if table name is correct?
        // SHOW TABLES LIKE is robust.
        self::$_tableExists = ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table);

        // Cache for 24 hours
        set_transient($cacheKey, (int) self::$_tableExists, 24 * 3600);

        return self::$_tableExists;
    }

    protected static function adminQueueTableExists(): bool
    {
        if (self::$adminQueueTableExistsCache !== null) {
            return self::$adminQueueTableExistsCache;
        }

        // Performance: Check persistent cache first
        $cacheKey = 'ap_admin_queue_table_exists';
        $cached = get_transient($cacheKey);
        if ($cached !== false) {
            self::$adminQueueTableExistsCache = (bool) $cached;
            return self::$adminQueueTableExistsCache;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ap_admin_notifications';
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($table))) === $table;

        // Cache for 24 hours
        set_transient($cacheKey, (int) $exists, 24 * 3600);

        self::$adminQueueTableExistsCache = (bool) $exists;
        return self::$adminQueueTableExistsCache;
    }

    /**
     * Send a templated email to client(s).
     */
    public static function sendTemplate(string $templateName, $to, array $placeholders = [], array $headers = []): bool
    {
        // Inject global defaults
        if (!isset($placeholders['studio_name'])) {
            $placeholders['studio_name'] = function_exists('aperture_pro')
                ? (aperture_pro()->settings->get('studio_name') ?: get_option('blogname'))
                : get_option('blogname');
        }

        // Backward compatibility mappings for new copy
        if (isset($placeholders['portal_url']) && !isset($placeholders['proof_gallery_link'])) {
            $placeholders['proof_gallery_link'] = $placeholders['portal_url'];
        }
        if (isset($placeholders['code']) && !isset($placeholders['otp_code'])) {
            $placeholders['otp_code'] = $placeholders['code'];
        }
        if (isset($placeholders['gallery_url']) && !isset($placeholders['download_link'])) {
            $placeholders['download_link'] = $placeholders['gallery_url'];
        }

        $templateFile = self::TEMPLATE_PATH . $templateName . '.php';
        if (!file_exists($templateFile)) {
            Logger::log('error', 'email', 'Email template not found', ['template' => $templateName, 'notify_admin' => true]);
            return false;
        }

        $template = include $templateFile;
        if (!is_array($template) || empty($template['subject']) || empty($template['body'])) {
            Logger::log('error', 'email', 'Email template returned invalid structure', ['template' => $templateName, 'notify_admin' => true]);
            return false;
        }

        $subject = self::applyPlaceholders($template['subject'], $placeholders);
        $body = self::applyPlaceholders($template['body'], $placeholders);

        $defaultHeaders = [
            'Content-Type: text/plain; charset=UTF-8',
        ];

        $allHeaders = array_merge($defaultHeaders, $headers);

        // Always queue for background sending
        self::enqueueTransactionalEmail($to, $subject, $body, $allHeaders);

        Logger::log('info', 'email', 'Email queued for sending', ['template' => $templateName, 'to' => $to]);

        return true;
    }

    /**
     * Enqueue a transactional email for background processing.
     *
     * @param string $to
     * @param string $subject
     * @param string $body
     * @param array $headers
     */
    public static function enqueueTransactionalEmail(string $to, string $subject, string $body, array $headers = []): void
    {
        global $wpdb;

        if (!self::tableExists()) {
            // Fallback to legacy behavior if table missing
            $queue = get_option(self::TRANSACTIONAL_QUEUE_OPTION, []);
            if (!is_array($queue)) $queue = [];
            $queue[] = [
                'to' => $to,
                'subject' => $subject,
                'body' => $body,
                'headers' => $headers,
                'retries' => 0,
                'created_at' => current_time('mysql'),
            ];
            update_option(self::TRANSACTIONAL_QUEUE_OPTION, $queue, false);
        } else {
            // Optimized storage
            $table = $wpdb->prefix . 'ap_email_queue';
            $wpdb->insert(
                $table,
                [
                    'to_address' => $to,
                    'subject'    => $subject,
                    'body'       => $body,
                    'headers'    => !empty($headers) ? json_encode($headers) : null,
                    'status'     => 'pending',
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ]
            );
        }

        if (!wp_next_scheduled(self::TRANSACTIONAL_CRON_HOOK)) {
            wp_schedule_single_event(time(), self::TRANSACTIONAL_CRON_HOOK);
        }
    }

    /**
     * Process transactional email queue.
     */
    public static function processTransactionalQueue(): void
    {
        if (get_transient(self::TRANSACTIONAL_QUEUE_LOCK)) {
            return;
        }
        set_transient(self::TRANSACTIONAL_QUEUE_LOCK, 1, 60);

        if (!self::tableExists()) {
            self::processOptionQueue();
            delete_transient(self::TRANSACTIONAL_QUEUE_LOCK);
            return;
        }

        // Migrate any legacy items to the table
        self::migrateLegacyQueue();

        global $wpdb;
        $table = $wpdb->prefix . 'ap_email_queue';

        // Fetch pending items
        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE status = %s ORDER BY priority DESC, created_at ASC LIMIT %d",
                'pending',
                self::TRANSACTIONAL_MAX_PER_RUN
            ),
            ARRAY_A
        );

        if (empty($items)) {
            delete_transient(self::TRANSACTIONAL_QUEUE_LOCK);
            return;
        }

        $startTime = microtime(true);
        $maxExecTime = (int)ini_get('max_execution_time');
        $timeLimit = $maxExecTime ? ($maxExecTime * 0.8) : 45;
        $maxRetries = 3;

        // Optimization: Use SMTP KeepAlive to reuse connection
        $keepAliveInit = function ($mailer) {
            $mailer->SMTPKeepAlive = true;
        };
        add_action('phpmailer_init', $keepAliveInit);

        $processedCount = 0;
        $phpmailerInstance = null;

        // Capture the mailer instance to close connection later
        $captureInstance = function ($mailer) use (&$phpmailerInstance) {
            $phpmailerInstance = $mailer;
        };
        add_action('phpmailer_init', $captureInstance);

        $sentIds = [];
        $retryIds = [];
        $failedIds = [];

        foreach ($items as $item) {
             // Check for timeout
             if ((microtime(true) - $startTime) > $timeLimit) {
                Logger::log('warning', 'email_queue', 'Time limit reached', ['processed' => $processedCount]);
                break;
            }

            $headers = !empty($item['headers']) ? json_decode($item['headers'], true) : [];
            if (!is_array($headers)) $headers = [];

            $sent = @wp_mail($item['to_address'], $item['subject'], $item['body'], $headers);

            if ($sent) {
                Logger::log('info', 'email_queue', 'Transactional email sent via queue', ['to' => $item['to_address']]);
                $sentIds[] = $item['id'];
            } else {
                $retries = $item['retries'] + 1;
                $newStatus = ($retries >= $maxRetries) ? 'failed_permanently' : 'pending';

                if ($newStatus === 'failed_permanently') {
                    Logger::log('error', 'email_queue', 'Transactional email failed after max retries', ['to' => $item['to_address'], 'subject' => $item['subject'], 'notify_admin' => true]);
                    self::enqueueAdminNotification('error', 'email_queue', 'Transactional email failed permanently', ['item_id' => $item['id']]);
                    $failedIds[] = $item['id'];
                } else {
                    $retryIds[] = $item['id'];
                }
            }
            $processedCount++;
        }

        // Batch update results to reduce N+1 queries
        $now = current_time('mysql');

        if (!empty($sentIds)) {
            $ids = implode(',', array_map('intval', $sentIds));
            $wpdb->query("UPDATE $table SET status = 'sent', updated_at = '$now' WHERE id IN ($ids)");
        }

        if (!empty($retryIds)) {
            $ids = implode(',', array_map('intval', $retryIds));
            // Use SQL for retry increment to ensure consistency
            $wpdb->query("UPDATE $table SET status = 'pending', retries = retries + 1, updated_at = '$now' WHERE id IN ($ids)");
        }

        if (!empty($failedIds)) {
            $ids = implode(',', array_map('intval', $failedIds));
            $wpdb->query("UPDATE $table SET status = 'failed_permanently', retries = retries + 1, updated_at = '$now' WHERE id IN ($ids)");
        }

        // Cleanup: Close SMTP connection and remove hooks
        if ($phpmailerInstance) {
            $phpmailerInstance->smtpClose();
        }
        remove_action('phpmailer_init', $keepAliveInit);
        remove_action('phpmailer_init', $captureInstance);

        // Check if there are more pending items
        $remainingCount = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE status = %s", 'pending'));

        if ($remainingCount > 0) {
            if (!wp_next_scheduled(self::TRANSACTIONAL_CRON_HOOK)) {
                wp_schedule_single_event(time() + 10, self::TRANSACTIONAL_CRON_HOOK);
            }
        }

        delete_transient(self::TRANSACTIONAL_QUEUE_LOCK);
    }

    /**
     * Migrate items from the legacy option queue to the new database table.
     */
    private static function migrateLegacyQueue(): void
    {
        $queue = get_option(self::TRANSACTIONAL_QUEUE_OPTION, []);
        if (!is_array($queue) || empty($queue)) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ap_email_queue';

        // Transactional integrity is ideal, but for now we just insert one by one
        // and delete option at the end.
        foreach ($queue as $item) {
             $wpdb->insert(
                $table,
                [
                    'to_address' => $item['to'],
                    'subject'    => $item['subject'],
                    'body'       => $item['body'],
                    'headers'    => !empty($item['headers']) ? json_encode($item['headers']) : null,
                    'status'     => 'pending',
                    'retries'    => $item['retries'] ?? 0,
                    'created_at' => $item['created_at'] ?? current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ]
            );
        }

        delete_option(self::TRANSACTIONAL_QUEUE_OPTION);
        Logger::log('info', 'email_queue', 'Migrated legacy email queue to database', ['count' => count($queue)]);
    }

    /**
     * Process legacy option-based queue.
     * Restores original processing logic for fallback scenarios.
     */
    private static function processOptionQueue(): void
    {
        $queue = get_option(self::TRANSACTIONAL_QUEUE_OPTION, []);
        if (!is_array($queue) || empty($queue)) {
            return;
        }

        $batch = array_splice($queue, 0, self::TRANSACTIONAL_MAX_PER_RUN);
        $remaining = $queue;
        $maxRetries = 3;

        $startTime = microtime(true);
        $maxExecTime = (int)ini_get('max_execution_time');
        $timeLimit = $maxExecTime ? ($maxExecTime * 0.8) : 45;

        // Same SMTP optimization logic
        $keepAliveInit = function ($mailer) { $mailer->SMTPKeepAlive = true; };
        add_action('phpmailer_init', $keepAliveInit);

        $phpmailerInstance = null;
        $captureInstance = function ($mailer) use (&$phpmailerInstance) { $phpmailerInstance = $mailer; };
        add_action('phpmailer_init', $captureInstance);

        $processedCount = 0;
        foreach ($batch as $item) {
             if ((microtime(true) - $startTime) > $timeLimit) {
                $unprocessed = array_slice($batch, $processedCount);
                $remaining = array_merge($unprocessed, $remaining);
                break;
            }

            $retries = $item['retries'] ?? 0;
            if ($retries >= $maxRetries) {
                // Fail permenently
                 self::enqueueAdminNotification('error', 'email_queue', 'Transactional email failed permanently', ['item' => $item]);
                 $processedCount++;
                 continue;
            }

            $sent = @wp_mail($item['to'], $item['subject'], $item['body'], $item['headers'] ?? []);

            if (!$sent) {
                $item['retries'] = $retries + 1;
                $remaining[] = $item;
            }
            $processedCount++;
        }

        if ($phpmailerInstance) { $phpmailerInstance->smtpClose(); }
        remove_action('phpmailer_init', $keepAliveInit);
        remove_action('phpmailer_init', $captureInstance);

        update_option(self::TRANSACTIONAL_QUEUE_OPTION, $remaining, false);

        if (!empty($remaining)) {
            if (!wp_next_scheduled(self::TRANSACTIONAL_CRON_HOOK)) {
                wp_schedule_single_event(time() + 10, self::TRANSACTIONAL_CRON_HOOK);
            }
        }
    }

    /**
     * Enqueue an admin notification. This avoids immediate email storms.
     *
     * @param string $level
     * @param string $context
     * @param string $message
     * @param array $meta
     */
    public static function enqueueAdminNotification(string $level, string $context, string $message, array $meta = []): void
    {
        global $wpdb;

        // Normalize inputs
        $level = substr((string) $level, 0, 16);
        $context = substr((string) $context, 0, 128);
        $message = (string) $message;
        $metaJson = wp_json_encode($meta);

        // Configurable: allow operator to choose storage (table preferred)
        $useTable = self::adminQueueTableExists();

        if ($useTable) {
            $table = $wpdb->prefix . 'ap_admin_notifications';

            // Best-effort insert; do not throw on DB errors
            try {
                // Check for duplicate pending item to avoid flooding
                // We use dedupe_hash index for O(1) lookup
                $dedupeHash = md5($level . '|' . $context . '|' . $message);

                // Optimization: Check in-memory cache first to avoid repetitive DB hits within the same request
                if (isset(self::$dedupeCache[$dedupeHash])) {
                    return;
                }

                $duplicateId = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $table WHERE dedupe_hash = %s AND processed = 0 LIMIT 1",
                    $dedupeHash
                ));

                if ($duplicateId) {
                    self::$dedupeCache[$dedupeHash] = true;
                    return;
                }

                $inserted = $wpdb->insert(
                    $table,
                    [
                        'level'      => $level,
                        'context'    => $context,
                        'message'    => $message,
                        'meta'       => $metaJson,
                        'dedupe_hash' => $dedupeHash,
                        'created_at' => current_time('mysql', true),
                        'processed'  => 0,
                    ],
                    [
                        '%s', '%s', '%s', '%s', '%s', '%s', '%d'
                    ]
                );

                if ($inserted !== false) {
                    self::$dedupeCache[$dedupeHash] = true;

                    // Ensure cron is scheduled
                    if (!wp_next_scheduled(self::CRON_HOOK)) {
                        wp_schedule_event(time() + 60, 'minute', self::CRON_HOOK);
                    }
                    return;
                }

                // If inserted is false, log and fall through
                Logger::log('error', 'email', 'Failed to insert admin notification into DB; falling back to option', [
                    'level' => $level,
                    'context' => $context,
                ]);

            } catch (\Throwable $e) {
                Logger::log('error', 'email', 'Exception inserting admin notification; falling back to option', [
                    'error' => $e->getMessage(),
                    'level' => $level,
                    'context' => $context,
                ]);
            }
        }

        // Option fallback (legacy): keep behavior but avoid repeated linear scans
        $optionKey = self::ADMIN_QUEUE_OPTION;
        $queue = get_option($optionKey, []);
        if (!is_array($queue)) {
            $queue = [];
        }

        // Convert to keyed map for O(1) dedupe if not already keyed
        $isKeyed = false;
        if (!empty($queue) && is_array($queue)) {
            foreach (array_keys($queue) as $k) {
                if (!is_int($k)) { $isKeyed = true; break; }
            }
        }

        $map = [];
        if ($isKeyed) {
            $map = $queue;
        } else {
            // Migrate numeric list to keyed map
            foreach ($queue as $item) {
                $p = $item['level'] ?? null;
                $c = $item['context'] ?? null;
                $m = $item['message'] ?? null;

                // Fallback for legacy items that used 'body' instead of 'message'
                if ($m === null && isset($item['body'])) {
                    $m = $item['body'];
                }

                // Use a stable key; here we use hash of level|context|message
                if ($p !== null && $c !== null && $m !== null) {
                    $k = md5("{$p}:{$c}:{$m}");
                    $map[$k] = $item;
                } else {
                    // If we can't key it easily, just append with random key or skip?
                    // We'll just preserve it with a random key to avoid data loss
                    $map[uniqid('legacy_', true)] = $item;
                }
            }
        }

        // Dedup key for this item
        $dedupeKey = md5("{$level}:{$context}:{$message}");

        if (isset($map[$dedupeKey])) {
            // Already queued
            return;
        }

        $map[$dedupeKey] = [
            'level' => $level,
            'context' => $context,
            'message' => $message, // Changed from subject/body to message for consistency with DB schema
            // Also keep legacy fields for backward compatibility if we revert
            'subject' => sprintf('[Aperture Pro] %s: %s', strtoupper($level), $context),
            'body' => $message . "\n\n" . print_r($meta, true),
            'meta' => $meta,
            'created_at' => current_time('mysql'),
        ];

        // Persist back to option
        update_option($optionKey, $map, false);

        // Ensure cron is scheduled
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'minute', self::CRON_HOOK);
        }
    }

    /**
     * Migrate items from legacy admin queue to new table.
     */
    public static function migrateAdminQueue(): void
    {
        if (!self::adminQueueTableExists()) {
            return;
        }

        $queue = get_option(self::ADMIN_QUEUE_OPTION, []);
        if (empty($queue) || !is_array($queue)) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ap_admin_notifications';

        // Check if queue is keyed or list
        foreach ($queue as $key => $item) {
            // Basic validation
            if (!isset($item['level'])) continue;

            $meta = $item['meta'] ?? [];
            $message = $item['message'] ?? $item['body'] ?? '';
            // If body was used (legacy), it contained the message.
            $context = substr((string)($item['context'] ?? 'general'), 0, 128);
            $level = substr((string)$item['level'], 0, 16);
            $messageStr = (string)$message;
            $hash = md5($level . '|' . $context . '|' . $messageStr);

            $wpdb->insert($table, [
                'level' => $level,
                'context' => $context,
                'message' => $messageStr,
                'meta' => wp_json_encode($meta),
                'dedupe_hash' => $hash,
                'created_at' => $item['created_at'] ?? current_time('mysql'),
                'processed' => 0
            ]);
        }

        delete_option(self::ADMIN_QUEUE_OPTION);
        Logger::log('info', 'email_queue', 'Migrated admin notification queue to database', ['count' => count($queue)]);
    }

    /**
     * Cron processor for admin email queue.
     * Sends up to MAX_PER_RUN emails per run, deduping by context within DEDUPE_WINDOW.
     */
    public static function processAdminQueue(): void
    {
        // Simple lock to avoid concurrent runs
        if (get_transient(self::ADMIN_QUEUE_LOCK)) {
            return;
        }
        set_transient(self::ADMIN_QUEUE_LOCK, 1, 30);

        // 1. Check for legacy items to migrate
        if (get_option(self::ADMIN_QUEUE_OPTION) !== false) {
             self::migrateAdminQueue();
        }

        if (!self::adminQueueTableExists()) {
            self::processLegacyAdminQueue();
            delete_transient(self::ADMIN_QUEUE_LOCK);
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ap_admin_notifications';

        // Fetch pending items
        $items = $wpdb->get_results(
            "SELECT * FROM $table WHERE processed = 0 ORDER BY created_at ASC LIMIT 50",
            ARRAY_A
        );

        if (empty($items)) {
            delete_transient(self::ADMIN_QUEUE_LOCK);
            return;
        }

        $sentCount = 0;
        $now = time();
        $lastSent = get_option('ap_admin_email_last_sent', []);
        if (!is_array($lastSent)) {
            $lastSent = [];
        }

        $limit = self::MAX_PER_RUN;

        foreach ($items as $item) {
            if ($sentCount >= $limit) {
                break;
            }

            $context = $item['context'];
            $last = isset($lastSent[$context]) ? strtotime($lastSent[$context]) : 0;

            // Deduplication Check
            if (($now - $last) < self::DEDUPE_WINDOW) {
                // Throttled. Mark as processed to prevent queue blocking.
                $wpdb->update($table, ['processed' => 1], ['id' => $item['id']]);
                continue;
            }

            $adminEmail = get_option('admin_email');
            if (empty($adminEmail)) {
                // Should we keep it? Yes.
                continue;
            }

            // Construct email
            $subject = sprintf('[Aperture Pro] %s: %s', strtoupper($item['level']), $context);
            $body = $item['message'];

            // Append meta to body if it looks like json or array
            $meta = !empty($item['meta']) ? json_decode($item['meta'], true) : [];
            if (!empty($meta) && is_array($meta)) {
                 $body .= "\n\n" . print_r($meta, true);
            }

            $headers = ['Content-Type: text/plain; charset=UTF-8'];
            $sent = @wp_mail($adminEmail, $subject, $body, $headers);

            if ($sent) {
                $sentCount++;
                $lastSent[$context] = current_time('mysql');
                Logger::log('info', 'email_queue', 'Admin notification sent', ['context' => $context]);

                // Mark processed
                $wpdb->update($table, ['processed' => 1], ['id' => $item['id']]);
            } else {
                Logger::log('warning', 'email_queue', 'Failed to send admin notification; will retry', ['context' => $context]);
            }
        }

        update_option('ap_admin_email_last_sent', $lastSent, false);
        delete_transient(self::ADMIN_QUEUE_LOCK);
    }

    /**
     * Legacy processor for admin email queue (fallback).
     */
    private static function processLegacyAdminQueue(): void
    {
        $queue = get_option(self::ADMIN_QUEUE_OPTION, []);
        if (empty($queue)) {
            return;
        }

        // Normalize keyed map to list for legacy processing logic
        if (!isset($queue[0]) && !empty($queue)) {
             $queue = array_values($queue);
        }

        $sentCount = 0;
        $now = time();
        $lastSent = get_option('ap_admin_email_last_sent', []);
        if (!is_array($lastSent)) {
            $lastSent = [];
        }

        $remaining = [];

        foreach ($queue as $item) {
            if ($sentCount >= self::MAX_PER_RUN) {
                $remaining[] = $item;
                continue;
            }

            $context = $item['context'] ?? 'general';
            $last = isset($lastSent[$context]) ? strtotime($lastSent[$context]) : 0;

            if (($now - $last) < self::DEDUPE_WINDOW) {
                $remaining[] = $item;
                continue;
            }

            $adminEmail = get_option('admin_email');
            if (empty($adminEmail)) {
                Logger::log('warning', 'email_queue', 'No admin email configured; keeping notification in queue', ['context' => $context]);
                $remaining[] = $item;
                continue;
            }

            // Legacy items have 'subject' and 'body' prepared.
            $subject = $item['subject'] ?? sprintf('[Aperture Pro] %s: %s', strtoupper($item['level']), $context);
            $body = $item['body'] ?? ($item['message'] ?? '');

            $headers = ['Content-Type: text/plain; charset=UTF-8'];
            $sent = @wp_mail($adminEmail, $subject, $body, $headers);

            if ($sent) {
                $sentCount++;
                $lastSent[$context] = current_time('mysql');
                Logger::log('info', 'email_queue', 'Admin notification sent', ['context' => $context]);
            } else {
                Logger::log('warning', 'email_queue', 'Failed to send admin notification; will retry', ['context' => $context]);
                $remaining[] = $item;
            }
        }

        update_option(self::ADMIN_QUEUE_OPTION, $remaining, false);
        update_option('ap_admin_email_last_sent', $lastSent, false);
    }

    /**
     * Apply placeholders in text.
     */
    protected static function applyPlaceholders(string $text, array $placeholders = []): string
    {
        if (empty($placeholders)) {
            return $text;
        }

        $search = [];
        $replace = [];
        foreach ($placeholders as $k => $v) {
            $search[] = '{{' . $k . '}}';
            $replace[] = (string)$v;
        }

        return str_replace($search, $replace, $text);
    }

    /**
     * Send payment received email.
     * Hooked to aperture_pro_payment_received.
     */
    public static function sendPaymentReceivedEmail(int $projectId): void
    {
        global $wpdb;

        $projectTable = $wpdb->prefix . 'ap_projects';
        $clientTable = $wpdb->prefix . 'ap_clients';

        $data = $wpdb->get_row($wpdb->prepare(
            "SELECT p.id, c.name as client_name, c.email as client_email
             FROM $projectTable p
             JOIN $clientTable c ON p.client_id = c.id
             WHERE p.id = %d",
            $projectId
        ));

        if (!$data || empty($data->client_email)) {
            return;
        }

        $studioName = function_exists('aperture_pro')
            ? (aperture_pro()->settings->get('studio_name') ?: get_option('blogname'))
            : get_option('blogname');

        self::sendTemplate('payment-received', $data->client_email, [
            'client_name' => $data->client_name,
            'studio_name' => $studioName,
        ]);
    }
}
