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

    protected static function tableExists(): bool
    {
        if (self::$_tableExists !== null) {
            return self::$_tableExists;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ap_email_queue';
        // Check using a cheap query that hits information_schema or just check if table name is correct?
        // SHOW TABLES LIKE is robust.
        self::$_tableExists = ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table);
        return self::$_tableExists;
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
                $wpdb->update(
                    $table,
                    ['status' => 'sent', 'updated_at' => current_time('mysql')],
                    ['id' => $item['id']]
                );
            } else {
                $retries = $item['retries'] + 1;
                $newStatus = ($retries >= $maxRetries) ? 'failed_permanently' : 'pending';

                $wpdb->update(
                    $table,
                    [
                        'status' => $newStatus,
                        'retries' => $retries,
                        'updated_at' => current_time('mysql')
                    ],
                    ['id' => $item['id']]
                );

                if ($newStatus === 'failed_permanently') {
                    Logger::log('error', 'email_queue', 'Transactional email failed after max retries', ['to' => $item['to_address'], 'subject' => $item['subject'], 'notify_admin' => true]);
                    self::enqueueAdminNotification('error', 'email_queue', 'Transactional email failed permanently', ['item_id' => $item['id']]);
                }
            }
            $processedCount++;
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
        $queue = get_option(self::ADMIN_QUEUE_OPTION, []);
        if (!is_array($queue)) {
            $queue = [];
        }

        $item = [
            'level' => $level,
            'context' => $context,
            'subject' => sprintf('[Aperture Pro] %s: %s', strtoupper($level), $context),
            'body' => $message . "\n\n" . print_r($meta, true),
            'meta' => $meta,
            'created_at' => current_time('mysql'),
        ];

        $queue[] = $item;
        update_option(self::ADMIN_QUEUE_OPTION, $queue, false);

        // Ensure cron is scheduled
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'minute', self::CRON_HOOK);
        }
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

        $queue = get_option(self::ADMIN_QUEUE_OPTION, []);
        if (!is_array($queue) || empty($queue)) {
            delete_transient(self::ADMIN_QUEUE_LOCK);
            return;
        }

        $sentCount = 0;
        $now = time();
        $sentContexts = [];

        // Load last-sent times to dedupe
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
                // Skip sending now; keep in queue
                $remaining[] = $item;
                continue;
            }

            // Send email
            $adminEmail = get_option('admin_email');
            if (empty($adminEmail)) {
                // Can't send; keep in queue and log
                Logger::log('warning', 'email_queue', 'No admin email configured; keeping notification in queue', ['context' => $context]);
                $remaining[] = $item;
                continue;
            }

            $headers = ['Content-Type: text/plain; charset=UTF-8'];
            $sent = @wp_mail($adminEmail, $item['subject'], $item['body'], $headers);

            if ($sent) {
                $sentCount++;
                $lastSent[$context] = current_time('mysql');
                Logger::log('info', 'email_queue', 'Admin notification sent', ['context' => $context]);
            } else {
                // Failed to send; keep in queue and log
                Logger::log('warning', 'email_queue', 'Failed to send admin notification; will retry', ['context' => $context]);
                $remaining[] = $item;
            }
        }

        // Persist remaining queue and lastSent
        update_option(self::ADMIN_QUEUE_OPTION, $remaining, false);
        update_option('ap_admin_email_last_sent', $lastSent, false);

        delete_transient(self::ADMIN_QUEUE_LOCK);
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
