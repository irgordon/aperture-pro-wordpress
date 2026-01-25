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

        $storage = null;
        $dirIt = new \DirectoryIterator($baseDir);

        // Collect candidates first to allow batch processing
        $candidates = [];
        foreach ($dirIt as $fileinfo) {
            if ($fileinfo->isDot() || !$fileinfo->isDir()) {
                continue;
            }
            $candidates[] = [
                'id' => $fileinfo->getFilename(),
                'path' => $fileinfo->getPathname(),
                'mtime' => $fileinfo->getMTime(),
            ];
        }

        // Process in batches to optimize DB queries
        $batchSize = 500;
        $chunks = array_chunk($candidates, $batchSize);

        foreach ($chunks as $chunk) {
            $ids = array_column($chunk, 'id');
            $sessions = self::getSessionsBatch($ids);

            foreach ($chunk as $item) {
                $uploadId = $item['id'];
                $session = $sessions[$uploadId] ?? false;

                $summary['scanned_sessions']++;

                // If no transient, consider stale
                if (empty($session) || !is_array($session)) {
                    // If assembled file exists, attempt to upload once
                    $sessionDir = $item['path'] . '/';
                    $assembled = $sessionDir . ChunkedUploadHandler::ASSEMBLED_FILENAME;
                    if (file_exists($assembled)) {
                        // Attempt one best-effort upload using storage driver
                        try {
                            if (!$storage) {
                                $storage = \AperturePro\Storage\StorageFactory::make();
                            }

                            // Try to recover session metadata from disk
                            $sessionFile = $sessionDir . 'session.json';
                            $diskSession = null;
                            if (file_exists($sessionFile)) {
                                $diskSession = json_decode(file_get_contents($sessionFile), true);
                            }

                            if ($diskSession && !empty($diskSession['project_id'])) {
                                // Use persisted metadata to reconstruct the correct remote key
                                $remoteKey = $diskSession['meta']['storage_key'] ?? ('projects/' . $diskSession['project_id'] . '/' . ($diskSession['meta']['original_filename'] ?? 'upload.bin'));
                                $remoteKey = 'uploads/' . $diskSession['project_id'] . '/' . $uploadId . '/' . basename($remoteKey);
                            } else {
                                // Fallback: Determine a remote key placeholder (we don't have session metadata)
                                $remoteKey = 'orphaned/' . $uploadId . '/' . basename($assembled);
                            }

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
                        $mtime = $item['mtime'];
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
                    $sessionDir = $item['path'] . '/';
                    ChunkedUploadHandler::cleanupSessionFiles($sessionDir);

                    // We need to delete the transient.
                    // Note: If we are using DB batching, we didn't check if it's already expired in DB logic (we filtered those out in getSessionsBatch)
                    // But here we are checking application-level logic (STALE_THRESHOLD).
                    // So we must call delete_transient.
                    delete_transient(self::SESSION_TRANSIENT_PREFIX . $uploadId);

                    $summary['stale_removed']++;
                    Logger::log('info', 'watchdog', 'Removed stale upload session (transient expired)', ['upload_id' => $uploadId]);
                }
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

    /**
     * Fetch multiple sessions from DB in one query to avoid N+1.
     * Safely falls back to iterative get_transient if object caching is detected.
     *
     * @param array $uploadIds List of upload IDs
     * @return array Map of upload_id => session data (or false if missing/expired)
     */
    protected static function getSessionsBatch(array $uploadIds): array
    {
        if (empty($uploadIds)) {
            return [];
        }

        // If external object cache is active, rely on it to avoid data inconsistency.
        // N fetches from memory cache is acceptable.
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            $sessions = [];
            foreach ($uploadIds as $id) {
                $sessions[$id] = get_transient(self::SESSION_TRANSIENT_PREFIX . $id);
            }
            return $sessions;
        }

        global $wpdb;

        // Prepare keys
        $transientKeys = [];
        $keyMap = []; // option_name => upload_id
        foreach ($uploadIds as $id) {
            $key = '_transient_' . self::SESSION_TRANSIENT_PREFIX . $id;
            $timeoutKey = '_transient_timeout_' . self::SESSION_TRANSIENT_PREFIX . $id;
            $transientKeys[] = $key;
            $transientKeys[] = $timeoutKey;
            $keyMap[$key] = $id;
            $keyMap[$timeoutKey] = $id;
        }

        // Chunking for SQL safety (max 1000 items in IN clause usually safe)
        // Since we are already chunking IDs at 500 (yielding 1000 keys), this is safe.

        $placeholders = implode(',', array_fill(0, count($transientKeys), '%s'));
        $sql = "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ($placeholders)";

        $results = $wpdb->get_results($wpdb->prepare($sql, $transientKeys));

        $data = []; // upload_id => value
        $timeouts = []; // upload_id => timestamp

        foreach ($results as $row) {
            $name = $row->option_name;
            $val = $row->option_value;

            // Check if timeout or value
            if (strpos($name, '_transient_timeout_') === 0) {
                // It's a timeout
                if (isset($keyMap[$name])) {
                    $id = $keyMap[$name];
                    $timeouts[$id] = (int)$val;
                }
            } else {
                // It's a value
                if (isset($keyMap[$name])) {
                    $id = $keyMap[$name];
                    $data[$id] = maybe_unserialize($val);
                }
            }
        }

        $sessions = [];
        $now = time();

        foreach ($uploadIds as $id) {
            // Check expiration
            if (isset($timeouts[$id]) && $timeouts[$id] < $now) {
                // Expired
                $sessions[$id] = false;
            } elseif (isset($data[$id])) {
                // Exists and not expired (or no timeout set)
                $sessions[$id] = $data[$id];
            } else {
                // Not found
                $sessions[$id] = false;
            }
        }

        return $sessions;
    }
}
