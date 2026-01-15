<?php

namespace AperturePro\Upload;

use AperturePro\Helpers\Logger;
use AperturePro\Email\EmailService;

/**
 * Watchdog
 *
 * Periodic maintenance for chunked uploads:
 *  - Cleans stale upload sessions and temp files
 *  - Retries or flags partially assembled files
 *  - Reports summary to admin via queued email and logs
 *  - Updates a transient used by Health Card to surface upload health
 *
 * Intended to be run via WP Cron (e.g., every 15 minutes).
 */
class Watchdog
{
    const SESSION_TRANSIENT_PREFIX = ChunkedUploadHandler::SESSION_TRANSIENT_PREFIX;
    const STALE_THRESHOLD = 60 * 60 * 24; // 24 hours stale
    const HEALTH_TRANSIENT = 'ap_upload_watchdog_health';
    const HEALTH_TTL = 60 * 15; // 15 minutes

    /**
     * Run maintenance pass.
     *
     * - Scans upload temp dir for sessions older than STALE_THRESHOLD
     * - Removes temp files and transients for stale sessions
     * - If assembled files exist but not uploaded, attempts one retry (best-effort)
     * - Enqueues admin notification if many stale sessions or repeated failures
     */
    public static function run(): void
    {
        $uploads = wp_upload_dir();
        $baseDir = trailingslashit($uploads['basedir']) . 'aperture-uploads/';

        $summary = [
            'timestamp' => current_time('mysql'),
            'scanned_sessions' => 0,
            'stale_removed' => 0,
            'retry_attempts' => 0,
            'retry_failures' => 0,
            'errors' => [],
        ];

        if (!is_dir($baseDir)) {
            // Nothing to do
            set_transient(self::HEALTH_TRANSIENT, ['ok' => true, 'summary' => $summary], self::HEALTH_TTL);
            return;
        }

        $dirIt = new \DirectoryIterator($baseDir);
        foreach ($dirIt as $fileinfo) {
            if ($fileinfo->isDot()) {
                continue;
            }
            if (!$fileinfo->isDir()) {
                continue;
            }

            $uploadId = $fileinfo->getFilename();
            $sessionKey = self::SESSION_TRANSIENT_PREFIX . $uploadId;
            $session = get_transient($sessionKey);

            $summary['scanned_sessions']++;

            // If no transient, consider stale
            if (empty($session) || !is_array($session)) {
                // If assembled file exists, attempt to upload once
                $sessionDir = $fileinfo->getPathname() . '/';
                $assembled = $sessionDir . ChunkedUploadHandler::ASSEMBLED_FILENAME;
                if (file_exists($assembled)) {
                    // Attempt one best-effort upload using storage driver
                    try {
                        $storage = \AperturePro\Storage\StorageFactory::make();
                        // Determine a remote key placeholder (we don't have session metadata)
                        $remoteKey = 'orphaned/' . $uploadId . '/' . basename($assembled);
                        $res = $storage->upload($assembled, $remoteKey, ['signed' => true]);
                        $summary['retry_attempts']++;
                        if (empty($res['success'])) {
                            $summary['retry_failures']++;
                            Logger::log('warning', 'watchdog', 'Retry upload failed for orphaned assembled file', ['upload_id' => $uploadId, 'remoteKey' => $remoteKey]);
                        } else {
                            // On success, remove assembled and session dir
                            ChunkedUploadHandler::cleanupSessionFiles($sessionDir);
                            $summary['stale_removed']++;
                            Logger::log('info', 'watchdog', 'Orphaned assembled file uploaded and cleaned', ['upload_id' => $uploadId, 'remoteKey' => $remoteKey]);
                        }
                    } catch (\Throwable $e) {
                        $summary['errors'][] = $e->getMessage();
                        Logger::log('error', 'watchdog', 'Exception during orphaned assembled retry: ' . $e->getMessage(), ['upload_id' => $uploadId, 'notify_admin' => true]);
                        $summary['retry_failures']++;
                    }
                } else {
                    // No assembled file; remove stale temp dir if older than threshold
                    $mtime = $fileinfo->getMTime();
                    if ((time() - $mtime) > self::STALE_THRESHOLD) {
                        // Remove directory
                        ChunkedUploadHandler::cleanupSessionFiles($sessionDir);
                        $summary['stale_removed']++;
                        Logger::log('info', 'watchdog', 'Removed stale upload session directory', ['upload_id' => $uploadId]);
                    }
                }
                continue;
            }

            // If session exists but expired or last updated long ago, remove
            $updated = $session['updated_at'] ?? $session['created_at'] ?? 0;
            if ((time() - $updated) > self::STALE_THRESHOLD) {
                $sessionDir = $fileinfo->getPathname() . '/';
                ChunkedUploadHandler::cleanupSessionFiles($sessionDir);
                delete_transient($sessionKey);
                $summary['stale_removed']++;
                Logger::log('info', 'watchdog', 'Removed stale upload session (transient expired)', ['upload_id' => $uploadId]);
            }
        }

        // If many stale sessions removed, notify admin via queued email
        if ($summary['stale_removed'] >= 5 || $summary['retry_failures'] > 0) {
            $subject = 'Aperture Pro: Upload Watchdog report';
            $body = "Watchdog run at " . $summary['timestamp'] . "\n\n" .
                "Scanned sessions: " . $summary['scanned_sessions'] . "\n" .
                "Stale removed: " . $summary['stale_removed'] . "\n" .
                "Retry attempts: " . $summary['retry_attempts'] . "\n" .
                "Retry failures: " . $summary['retry_failures'] . "\n\n" .
                "Errors:\n" . print_r($summary['errors'], true);

            EmailService::enqueueAdminNotification('warning', 'upload_watchdog', $subject, ['summary' => $summary]);
            Logger::log('info', 'watchdog', 'Watchdog enqueued admin notification', ['summary' => $summary]);
        }

        // Update health transient for Admin Health Card
        set_transient(self::HEALTH_TRANSIENT, ['ok' => true, 'summary' => $summary], self::HEALTH_TTL);
    }
}
