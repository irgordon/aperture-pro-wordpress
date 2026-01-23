<?php

namespace AperturePro\Upload;

use AperturePro\Helpers\Logger;
use AperturePro\Storage\StorageFactory;
use AperturePro\Helpers\Utils;
use AperturePro\Email\EmailService;
use AperturePro\Auth\CookieService;
use AperturePro\Config\Config;

/**
 * ChunkedUploadHandler
 *
 * Handles chunked, resumable uploads for large image files.
 *
 * API expectations (REST controller should call these methods):
 *  - Start upload: create session (returns upload_id)
 *  - Upload chunk: POST chunk with upload_id, chunk_index, total_chunks
 *  - Query progress: GET progress for upload_id
 *  - Complete: server assembles and pushes to storage, creates DB image row
 *
 * Implementation notes:
 *  - Uses temporary files under wp_upload_dir()/aperture-uploads/{upload_id}/
 *  - Each chunk is written to a separate file (chunk_{index}.part)
 *  - When all chunks present, they are concatenated into a single file using streaming
 *  - After assembly, file is uploaded to configured Storage driver via StorageInterface
 *  - Upload session metadata stored in transient 'ap_upload_{upload_id}' for resumability
 *  - Watchdog cleans stale sessions and temp files
 *
 * Security:
 *  - Caller must validate permissions (e.g., admin or authenticated photographer)
 *  - Filenames and keys are sanitized; no direct path input accepted
 */
class ChunkedUploadHandler
{
    const SESSION_TRANSIENT_PREFIX = 'ap_upload_';
    const SESSION_TTL = 60 * 60 * 24; // 24 hours for resumable sessions
    const CHUNK_FILENAME = 'chunk_%d.part';
    const ASSEMBLED_FILENAME = 'assembled.bin';
    const MAX_FILE_SIZE = 1024 * 1024 * 1024; // 1GB default max (configurable)
    const ALLOWED_MIME = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/tiff',
        'image/heic',
    ];

    /**
     * Create a new upload session.
     *
     * @param int $projectId
     * @param int $uploaderId (optional) - user id or photographer id
     * @param array $meta optional metadata: original_filename, expected_size, mime_type, storage_key
     * @return array ['success'=>bool, 'upload_id'=>string, 'expires_at'=>int, 'message'=>string]
     */
    public static function createSession(int $projectId, ?int $uploaderId = null, array $meta = []): array
    {
        // Basic validation
        if ($projectId <= 0) {
            return ['success' => false, 'message' => 'Invalid project id.'];
        }

        $uploadId = bin2hex(random_bytes(16));
        $uploads = wp_upload_dir();
        $baseDir = trailingslashit($uploads['basedir']) . 'aperture-uploads/';
        if (!file_exists($baseDir)) {
            wp_mkdir_p($baseDir);
        }

        $sessionDir = $baseDir . $uploadId . '/';
        if (!file_exists($sessionDir)) {
            wp_mkdir_p($sessionDir);
        }

        $now = time();
        $expiresAt = $now + self::SESSION_TTL;

        $session = [
            'upload_id' => $uploadId,
            'project_id' => $projectId,
            'uploader_id' => $uploaderId,
            'created_at' => $now,
            'updated_at' => $now,
            'expires_at' => $expiresAt,
            'meta' => [
                'original_filename' => sanitize_file_name($meta['original_filename'] ?? 'upload.bin'),
                'expected_size' => isset($meta['expected_size']) ? (int)$meta['expected_size'] : null,
                'mime_type' => $meta['mime_type'] ?? null,
                'storage_key' => $meta['storage_key'] ?? null,
            ],
            'chunks' => [
                'received' => [],
                'total' => isset($meta['total_chunks']) ? (int)$meta['total_chunks'] : null,
            ],
            'status' => 'in_progress',
        ];

        $saved = set_transient(self::SESSION_TRANSIENT_PREFIX . $uploadId, $session, self::SESSION_TTL);
        if (!$saved) {
            Logger::log('error', 'upload', 'Failed to create upload session transient', ['upload_id' => $uploadId, 'project_id' => $projectId, 'notify_admin' => true]);
            return ['success' => false, 'message' => 'Unable to create upload session.'];
        }

        // Persist session metadata to disk
        self::persistSessionToDisk($sessionDir, $session);

        Logger::log('info', 'upload', 'Upload session created', ['upload_id' => $uploadId, 'project_id' => $projectId]);

        return ['success' => true, 'upload_id' => $uploadId, 'expires_at' => $expiresAt];
    }

    /**
     * Accept a chunk for an existing session.
     *
     * @param string $uploadId
     * @param int $chunkIndex zero-based
     * @param int $totalChunks
     * @param resource|string $streamOrPath Either php://input stream or path to uploaded tmp file
     * @return array ['success'=>bool, 'message'=>string, 'progress'=>float]
     */
    public static function acceptChunk(string $uploadId, int $chunkIndex, int $totalChunks, $streamOrPath): array
    {
        $uploadId = sanitize_text_field($uploadId);
        if (empty($uploadId) || $chunkIndex < 0 || $totalChunks <= 0) {
            return ['success' => false, 'message' => 'Invalid upload parameters.'];
        }

        $sessionKey = self::SESSION_TRANSIENT_PREFIX . $uploadId;
        $session = get_transient($sessionKey);
        if (empty($session) || !is_array($session)) {
            return ['success' => false, 'message' => 'Upload session not found or expired.'];
        }

        // Update total chunks if not set
        if (empty($session['chunks']['total']) || $session['chunks']['total'] !== $totalChunks) {
            $session['chunks']['total'] = $totalChunks;
        }

        $uploads = wp_upload_dir();
        $baseDir = trailingslashit($uploads['basedir']) . 'aperture-uploads/';
        $sessionDir = $baseDir . $uploadId . '/';
        if (!file_exists($sessionDir)) {
            wp_mkdir_p($sessionDir);
        }

        $chunkFilename = $sessionDir . sprintf(self::CHUNK_FILENAME, $chunkIndex);

        // Write chunk to disk using streaming to avoid memory blowout
        $written = false;
        try {
            if (is_resource($streamOrPath)) {
                // stream provided (php://input)
                $out = fopen($chunkFilename, 'wb');
                if ($out === false) {
                    throw new \RuntimeException('Unable to open chunk file for writing.');
                }
                while (!feof($streamOrPath)) {
                    $data = fread($streamOrPath, 1024 * 1024);
                    if ($data === false) {
                        break;
                    }
                    fwrite($out, $data);
                }
                fclose($out);
                $written = true;
            } elseif (is_string($streamOrPath) && file_exists($streamOrPath)) {
                // tmp uploaded file path
                $moved = @move_uploaded_file($streamOrPath, $chunkFilename);
                if (!$moved) {
                    // fallback to copy
                    $copied = @copy($streamOrPath, $chunkFilename);
                    if ($copied) {
                        @unlink($streamOrPath);
                        $written = true;
                    } else {
                        throw new \RuntimeException('Failed to move or copy uploaded chunk.');
                    }
                } else {
                    $written = true;
                }
            } else {
                throw new \InvalidArgumentException('Invalid chunk source.');
            }
        } catch (\Throwable $e) {
            Logger::log('error', 'upload', 'Failed to write chunk', ['upload_id' => $uploadId, 'chunk' => $chunkIndex, 'error' => $e->getMessage(), 'notify_admin' => true]);
            return ['success' => false, 'message' => 'Failed to write chunk.'];
        }

        if (!$written) {
            Logger::log('error', 'upload', 'Chunk write returned false', ['upload_id' => $uploadId, 'chunk' => $chunkIndex]);
            return ['success' => false, 'message' => 'Failed to write chunk.'];
        }

        // Mark chunk as received
        $session['chunks']['received'][$chunkIndex] = time();
        $session['updated_at'] = time();
        set_transient($sessionKey, $session, self::SESSION_TTL);
        self::persistSessionToDisk($sessionDir, $session);

        // Compute progress
        $receivedCount = count($session['chunks']['received']);
        $progress = ($receivedCount / max(1, $session['chunks']['total'])) * 100.0;

        Logger::log('info', 'upload', 'Chunk received', ['upload_id' => $uploadId, 'chunk' => $chunkIndex, 'progress' => $progress]);

        // If all chunks received, trigger assembly (synchronously here; could be queued)
        if ($receivedCount >= $session['chunks']['total']) {
            $assembleResult = self::assembleAndStore($uploadId, $sessionDir, $session);
            if (!$assembleResult['success']) {
                return ['success' => false, 'message' => 'Failed to assemble upload: ' . $assembleResult['message']];
            }

            // Mark session complete
            $session['status'] = 'completed';
            $session['updated_at'] = time();
            set_transient($sessionKey, $session, self::SESSION_TTL);
            self::persistSessionToDisk($sessionDir, $session);

            return ['success' => true, 'message' => 'Upload complete', 'progress' => 100.0];
        }

        return ['success' => true, 'message' => 'Chunk accepted', 'progress' => $progress];
    }

    /**
     * Assemble chunks into a single file and push to storage.
     *
     * @param string $uploadId
     * @param string $sessionDir
     * @param array $session
     * @return array ['success'=>bool,'message'=>string,'storage'=>array|null]
     */
    protected static function assembleAndStore(string $uploadId, string $sessionDir, array $session): array
    {
        $assembledPath = $sessionDir . self::ASSEMBLED_FILENAME;

        // Open assembled file for writing
        $out = fopen($assembledPath, 'wb');
        if ($out === false) {
            Logger::log('error', 'upload', 'Failed to open assembled file for writing', ['upload_id' => $uploadId, 'notify_admin' => true]);
            return ['success' => false, 'message' => 'Unable to assemble file.'];
        }

        // Acquire exclusive lock on assembled file to prevent concurrent assembly
        if (!flock($out, LOCK_EX)) {
            fclose($out);
            Logger::log('error', 'upload', 'Failed to acquire lock for assembly', ['upload_id' => $uploadId]);
            return ['success' => false, 'message' => 'Unable to assemble file (lock).'];
        }

        try {
            $total = $session['chunks']['total'];
            for ($i = 0; $i < $total; $i++) {
                $chunkFile = $sessionDir . sprintf(self::CHUNK_FILENAME, $i);
                if (!file_exists($chunkFile)) {
                    // Missing chunk: abort assembly
                    flock($out, LOCK_UN);
                    fclose($out);
                    Logger::log('error', 'upload', 'Missing chunk during assembly', ['upload_id' => $uploadId, 'missing_chunk' => $i, 'notify_admin' => true]);
                    return ['success' => false, 'message' => "Missing chunk {$i}."];
                }

                $in = fopen($chunkFile, 'rb');
                if ($in === false) {
                    flock($out, LOCK_UN);
                    fclose($out);
                    Logger::log('error', 'upload', 'Failed to open chunk for assembly', ['upload_id' => $uploadId, 'chunk' => $i, 'notify_admin' => true]);
                    return ['success' => false, 'message' => "Failed to read chunk {$i}."];
                }

                // Stream copy
                while (!feof($in)) {
                    $buffer = fread($in, 1024 * 1024);
                    if ($buffer === false) {
                        fclose($in);
                        flock($out, LOCK_UN);
                        fclose($out);
                        Logger::log('error', 'upload', 'Error reading chunk during assembly', ['upload_id' => $uploadId, 'chunk' => $i]);
                        return ['success' => false, 'message' => "Error reading chunk {$i}."];
                    }
                    fwrite($out, $buffer);
                }

                fclose($in);
            }

            // Flush and unlock
            fflush($out);
            flock($out, LOCK_UN);
            fclose($out);
        } catch (\Throwable $e) {
            @fclose($out);
            Logger::log('error', 'upload', 'Exception during assembly: ' . $e->getMessage(), ['upload_id' => $uploadId, 'notify_admin' => true]);
            return ['success' => false, 'message' => 'Exception during assembly.'];
        }

        // Basic validation: mime type and size
        $mime = self::detectMimeType($assembledPath);
        $size = filesize($assembledPath);

        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            // Clean up assembled file
            @unlink($assembledPath);
            Logger::log('warning', 'upload', 'Disallowed mime type uploaded', ['upload_id' => $uploadId, 'mime' => $mime]);
            return ['success' => false, 'message' => 'Uploaded file type not allowed.'];
        }

        if ($size > (self::MAX_FILE_SIZE)) {
            @unlink($assembledPath);
            Logger::log('warning', 'upload', 'Uploaded file exceeds max size', ['upload_id' => $uploadId, 'size' => $size]);
            return ['success' => false, 'message' => 'Uploaded file too large.'];
        }

        // Upload to storage driver
        try {
            $storage = StorageFactory::make();
        } catch (\Throwable $e) {
            Logger::log('error', 'upload', 'Storage driver unavailable: ' . $e->getMessage(), ['upload_id' => $uploadId, 'notify_admin' => true]);
            return ['success' => false, 'message' => 'Storage unavailable.'];
        }

        // Determine remote key: use provided storage_key or generate one
        $remoteKey = $session['meta']['storage_key'] ?? ('projects/' . $session['project_id'] . '/' . $session['meta']['original_filename']);
        // Ensure unique key by prefixing upload id
        $remoteKey = 'uploads/' . $session['project_id'] . '/' . $uploadId . '/' . basename($remoteKey);

        // Upload using streaming where possible
        try {
            $uploadResult = $storage->upload($assembledPath, $remoteKey, ['signed' => true]);
        } catch (\Throwable $e) {
            Logger::log('error', 'upload', 'Storage upload failed: ' . $e->getMessage(), ['upload_id' => $uploadId, 'remoteKey' => $remoteKey, 'notify_admin' => true]);
            // Keep assembled file for watchdog to retry; do not delete immediately
            return ['success' => false, 'message' => 'Failed to store uploaded file.'];
        }

        // On success, create DB image row
        global $wpdb;
        $imagesTable = $wpdb->prefix . 'ap_images';

        $inserted = $wpdb->insert(
            $imagesTable,
            [
                'gallery_id' => self::ensure_proof_gallery_for_project($session['project_id']),
                'storage_key_original' => $remoteKey,
                'storage_key_edited' => null,
                'is_selected' => 0,
                'client_comments' => null,
                'sort_order' => 0,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s']
        );

        if ($inserted === false) {
            Logger::log('error', 'upload', 'Failed to insert image DB row', ['upload_id' => $uploadId, 'remoteKey' => $remoteKey, 'notify_admin' => true]);

            // Auto-cleanup remote object on failure
            if (Config::get('upload.auto_cleanup_remote_on_failure', true)) {
                try {
                    $storage->delete($remoteKey);
                    Logger::log('info', 'upload', 'Cleaned up orphaned remote object', ['remoteKey' => $remoteKey]);
                } catch (\Throwable $e) {
                    Logger::log('warning', 'upload', 'Failed to cleanup orphaned remote object', ['remoteKey' => $remoteKey, 'error' => $e->getMessage()]);
                }
            }

            return ['success' => false, 'message' => 'Failed to register uploaded image.'];
        }

        $imageId = (int) $wpdb->insert_id;

        // Cleanup: remove chunk files and assembled file
        self::cleanupSessionFiles($sessionDir);

        // Remove transient session
        delete_transient(self::SESSION_TRANSIENT_PREFIX . $uploadId);

        Logger::log('info', 'upload', 'Upload assembled and stored', ['upload_id' => $uploadId, 'image_id' => $imageId, 'remoteKey' => $remoteKey]);

        return ['success' => true, 'message' => 'Upload stored', 'image_id' => $imageId, 'storage' => $uploadResult];
    }

    /**
     * Return progress for an upload session.
     *
     * @param string $uploadId
     * @return array ['success'=>bool,'progress'=>float,'received'=>int,'total'=>int]
     */
    public static function getProgress(string $uploadId): array
    {
        $sessionKey = self::SESSION_TRANSIENT_PREFIX . sanitize_text_field($uploadId);
        $session = get_transient($sessionKey);
        if (empty($session) || !is_array($session)) {
            return ['success' => false, 'message' => 'Session not found.'];
        }

        $received = count($session['chunks']['received'] ?? []);
        $total = (int) ($session['chunks']['total'] ?? 0);
        $progress = $total > 0 ? ($received / $total) * 100.0 : 0.0;

        return ['success' => true, 'progress' => $progress, 'received' => $received, 'total' => $total];
    }

    /**
     * Cleanup session files (chunks and assembled).
     */
    public static function cleanupSessionFiles(string $sessionDir): void
    {
        if (!is_dir($sessionDir)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sessionDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            @unlink($file->getPathname());
        }
        @rmdir($sessionDir);
    }

    /**
     * Persist session metadata to disk for resilience against transient loss.
     */
    protected static function persistSessionToDisk(string $sessionDir, array $session): void
    {
        $file = $sessionDir . 'session.json';
        @file_put_contents($file, json_encode($session));
    }

    /**
     * Ensure a proof gallery exists for a project; create if missing.
     * Returns gallery_id.
     */
    protected static function ensure_proof_gallery_for_project(int $projectId): int
    {
        global $wpdb;
        $galleries = $wpdb->prefix . 'ap_galleries';

        $galleryId = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$galleries} WHERE project_id = %d AND type = %s LIMIT 1", $projectId, 'proof'));
        if ($galleryId) {
            return (int)$galleryId;
        }

        $inserted = $wpdb->insert(
            $galleries,
            [
                'project_id' => $projectId,
                'type' => 'proof',
                'status' => 'uploaded',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            Logger::log('error', 'upload', 'Failed to create proof gallery', ['project_id' => $projectId, 'notify_admin' => true]);
            return 0;
        }

        return (int)$wpdb->insert_id;
    }

    /**
     * Detect mime type for a file path.
     */
    protected static function detectMimeType(string $path): string
    {
        $mime = null;

        if (function_exists('mime_content_type')) {
            $mime = @mime_content_type($path);
        }

        if (empty($mime) && function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);
            }
        }

        if (empty($mime)) {
            $mime = 'application/octet-stream';
        }

        return $mime;
    }
}
