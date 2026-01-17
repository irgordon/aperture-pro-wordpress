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
    const TRANSACTIONAL_QUEUE_OPTION = 'ap_transactional_email_queue';
    const TRANSACTIONAL_CRON_HOOK = 'aperture_pro_send_transactional_emails';
    const TRANSACTIONAL_QUEUE_LOCK = 'ap_transactional_queue_lock';
    const TRANSACTIONAL_MAX_PER_RUN = 5;
    const MAX_PER_RUN = 3; // send up to 3 admin emails per cron run
    const DEDUPE_WINDOW = 900; // 15 minutes dedupe window for same context

    /**
     * Send a templated email to client(s).
     */
    public static function sendTemplate(string $templateName, $to, array $placeholders = [], array $headers = []): bool
    {
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

        $sent = @wp_mail($to, $subject, $body, $allHeaders);

        if (!$sent) {
            // Queue for retry
            Logger::log('warning', 'email', 'Failed to send email immediately; queuing for retry', ['template' => $templateName, 'to' => $to]);
            self::enqueueTransactionalEmail($to, $subject, $body, $allHeaders);
            return true; // We return true because the system has accepted the responsibility to deliver it
        }

        Logger::log('info', 'email', 'Email sent', ['template' => $templateName, 'to' => $to]);
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
        $queue = get_option(self::TRANSACTIONAL_QUEUE_OPTION, []);
        if (!is_array($queue)) {
            $queue = [];
        }

        $queue[] = [
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'headers' => $headers,
            'retries' => 0,
            'created_at' => current_time('mysql'),
        ];

        update_option(self::TRANSACTIONAL_QUEUE_OPTION, $queue, false);

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

        $queue = get_option(self::TRANSACTIONAL_QUEUE_OPTION, []);
        if (!is_array($queue) || empty($queue)) {
            delete_transient(self::TRANSACTIONAL_QUEUE_LOCK);
            return;
        }

        // Process in batches to avoid timeout
        $batch = array_splice($queue, 0, self::TRANSACTIONAL_MAX_PER_RUN);
        $remaining = $queue; // The rest of the queue (if any)
        $maxRetries = 3;

        foreach ($batch as $item) {
            $retries = $item['retries'] ?? 0;

            if ($retries >= $maxRetries) {
                Logger::log('error', 'email_queue', 'Transactional email failed after max retries', ['to' => $item['to'], 'subject' => $item['subject'], 'notify_admin' => true]);
                // Notify admin about the persistent failure
                self::enqueueAdminNotification('error', 'email_queue', 'Transactional email failed permanently', ['item' => $item]);
                continue; // Drop from queue
            }

            $sent = @wp_mail($item['to'], $item['subject'], $item['body'], $item['headers']);

            if ($sent) {
                Logger::log('info', 'email_queue', 'Transactional email sent via queue', ['to' => $item['to']]);
            } else {
                $item['retries'] = $retries + 1;
                $remaining[] = $item;
            }
        }

        update_option(self::TRANSACTIONAL_QUEUE_OPTION, $remaining, false);

        // If items remain, ensure processing continues
        if (!empty($remaining)) {
            if (!wp_next_scheduled(self::TRANSACTIONAL_CRON_HOOK)) {
                wp_schedule_single_event(time() + 10, self::TRANSACTIONAL_CRON_HOOK);
            }
        }

        delete_transient(self::TRANSACTIONAL_QUEUE_LOCK);
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
}
