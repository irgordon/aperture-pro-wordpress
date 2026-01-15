<?php

namespace AperturePro\Download;

use AperturePro\Helpers\Logger;
use AperturePro\Storage\StorageFactory;

/**
 * ZipStreamService
 *
 * Responsibilities:
 *  - Validate a download token (transient or DB)
 *  - Resolve gallery and image list for the token
 *  - Stream a ZIP archive to the client without loading all files into memory
 *  - Use ZipStream library if available for true streaming
 *  - Fallback to ZipArchive writing to a temporary file and streaming it
 *  - Log and notify admin on critical failures
 *
 * Expected token payload (transient "ap_download_{token}" or DB ap_download_tokens):
 *  - gallery_id
 *  - project_id
 *  - created_at
 *
 * Return value:
 *  - On success: ['success' => true] (streaming will have been emitted)
 *  - On failure: ['success' => false, 'error' => 'code', 'message' => 'human message', 'status' => int]
 */
class ZipStreamService
{
    const TRANSIENT_PREFIX = 'ap_download_';
    const TEMP_PREFIX = 'ap_zip_';
    const TEMP_TTL = 3600; // seconds for temp files

    /**
     * Validate token and stream ZIP.
     *
     * This method will attempt to stream the ZIP to the client. If streaming
     * succeeds it will exit after output. If it cannot stream, it returns an
     * array describing the failure.
     *
     * @param string $token 64 hex chars
     * @return array
     */
    public static function streamByToken(string $token): array
    {
        global $wpdb;

        if (empty($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return ['success' => false, 'error' => 'invalid_token', 'message' => 'Invalid token format.', 'status' => 400];
        }

        // 1) Try transient first
        $transientKey = self::TRANSIENT_PREFIX . $token;
        $payload = get_transient($transientKey);

        // 2) If no transient, try DB table ap_download_tokens
        if (empty($payload)) {
            $table = $wpdb->prefix . 'ap_download_tokens';
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE token = %s LIMIT 1", $token));
            if ($row) {
                $payload = [
                    'gallery_id' => (int) $row->gallery_id,
                    'project_id' => (int) $row->project_id,
                    'created_at' => strtotime($row->created_at),
                    'expires_at' => !empty($row->expires_at) ? strtotime($row->expires_at) : null,
                    'zip_ref'    => $row->zip_ref ?? null,
                ];
            }
        }

        if (empty($payload) || !is_array($payload)) {
            return ['success' => false, 'error' => 'not_found', 'message' => 'Download token not found or expired.', 'status' => 410];
        }

        // Check expiry if present
        if (!empty($payload['expires_at']) && time() > (int) $payload['expires_at']) {
            // Clean up transient if present
            delete_transient($transientKey);
            return ['success' => false, 'error' => 'expired', 'message' => 'Download token expired.', 'status' => 410];
        }

        $galleryId = (int) ($payload['gallery_id'] ?? 0);
        if ($galleryId <= 0) {
            return ['success' => false, 'error' => 'invalid_payload', 'message' => 'Download token payload invalid.', 'status' => 400];
        }

        // Resolve images for gallery
        $images = self::getImagesForGallery($galleryId);
        if (empty($images)) {
            return ['success' => false, 'error' => 'no_images', 'message' => 'No images found for this gallery.', 'status' => 404];
        }

        // Acquire storage driver
        try {
            $storage = StorageFactory::make();
        } catch (\Throwable $e) {
            Logger::log('error', 'zipstream', 'Storage driver unavailable: ' . $e->getMessage(), ['token' => $token, 'notify_admin' => true]);
            return ['success' => false, 'error' => 'storage_unavailable', 'message' => 'Storage driver unavailable.', 'status' => 500];
        }

        // Prefer ZipStream library if available
        if (class_exists('\ZipStream\ZipStream')) {
            return self::streamWithZipStream($token, $images, $storage);
        }

        // Fallback to ZipArchive -> temp file -> stream
        return self::streamWithZipArchive($token, $images, $storage);
    }

    /**
     * Query DB for images belonging to a gallery and return array of image rows.
     *
     * Each item returned will be an associative array:
     *  - id
     *  - storage_key_original
     *  - storage_key_edited
     */
    protected static function getImagesForGallery(int $galleryId): array
    {
        global $wpdb;
        $imagesTable = $wpdb->prefix . 'ap_images';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, storage_key_original, storage_key_edited FROM {$imagesTable} WHERE gallery_id = %d ORDER BY sort_order ASC, id ASC",
                $galleryId
            ),
            ARRAY_A
        );

        return $rows ?: [];
    }

    /**
     * Stream using ZipStream library (preferred).
     *
     * This method streams directly to the client without creating a temp file.
     */
    protected static function streamWithZipStream(string $token, array $images, $storage): array
    {
        try {
            // Create a friendly filename
            $zipName = 'aperture-download-' . substr($token, 0, 8) . '.zip';

            // Headers will be handled by ZipStream
            $options = new \ZipStream\Option\Archive();
            $options->setSendHttpHeaders(true);
            $options->setFlushOutput(true);

            $zip = new \ZipStream\ZipStream($zipName, $options);

            foreach ($images as $img) {
                $key = $img['storage_key_edited'] ?: $img['storage_key_original'];
                if (empty($key)) {
                    Logger::log('warning', 'zipstream', 'Image missing storage key', ['image_id' => $img['id']]);
                    continue;
                }

                // Get a readable stream for the object. StorageInterface does not define a stream method,
                // so we attempt to fetch a URL and stream via fopen if allowed.
                $url = $storage->getUrl($key, ['signed' => true, 'expires' => 300]);
                if (empty($url)) {
                    Logger::log('warning', 'zipstream', 'Could not obtain URL for image', ['key' => $key, 'image_id' => $img['id']]);
                    continue;
                }

                // Open remote stream
                $context = stream_context_create(['http' => ['timeout' => 30]]);
                $handle = @fopen($url, 'rb', false, $context);
                if ($handle === false) {
                    Logger::log('warning', 'zipstream', 'Failed to open remote stream for image', ['url' => $url, 'key' => $key]);
                    continue;
                }

                // Determine filename inside zip
                $filename = basename($key);
                // Add file to zip from stream
                $zip->addFileFromStream($filename, $handle);

                // ZipStream will close the stream when finished adding
            }

            // Finish and send
            $zip->finish();

            // Streaming completed
            return ['success' => true];
        } catch (\Throwable $e) {
            Logger::log('error', 'zipstream', 'ZipStream streaming failed: ' . $e->getMessage(), ['token' => $token, 'notify_admin' => true]);
            return ['success' => false, 'error' => 'stream_failed', 'message' => 'Failed to stream ZIP.', 'status' => 500];
        }
    }

    /**
     * Fallback streaming using ZipArchive and a temporary file.
     *
     * Creates a temp file in uploads, writes the ZIP, streams it, and deletes the temp file.
     */
    protected static function streamWithZipArchive(string $token, array $images, $storage): array
    {
        $uploads = wp_upload_dir();
        $tmpDir = trailingslashit($uploads['basedir']) . 'aperture-temp/';
        if (!file_exists($tmpDir)) {
            wp_mkdir_p($tmpDir);
        }

        $tmpFile = tempnam($tmpDir, self::TEMP_PREFIX);
        if ($tmpFile === false) {
            Logger::log('error', 'zipstream', 'Failed to create temporary file for ZIP', ['token' => $token, 'notify_admin' => true]);
            return ['success' => false, 'error' => 'tempfile_failed', 'message' => 'Unable to create temporary file.', 'status' => 500];
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($tmpFile, \ZipArchive::OVERWRITE);
        if ($opened !== true) {
            Logger::log('error', 'zipstream', 'ZipArchive open failed', ['code' => $opened, 'tmp' => $tmpFile, 'notify_admin' => true]);
            @unlink($tmpFile);
            return ['success' => false, 'error' => 'zip_open_failed', 'message' => 'Unable to create ZIP archive.', 'status' => 500];
        }

        try {
            foreach ($images as $img) {
                $key = $img['storage_key_edited'] ?: $img['storage_key_original'];
                if (empty($key)) {
                    Logger::log('warning', 'zipstream', 'Image missing storage key', ['image_id' => $img['id']]);
                    continue;
                }

                $url = $storage->getUrl($key, ['signed' => true, 'expires' => 300]);
                if (empty($url)) {
                    Logger::log('warning', 'zipstream', 'Could not obtain URL for image', ['key' => $key, 'image_id' => $img['id']]);
                    continue;
                }

                // Stream remote file into temp file chunk and add to zip using addFile or addFromString
                $context = stream_context_create(['http' => ['timeout' => 30]]);
                $handle = @fopen($url, 'rb', false, $context);
                if ($handle === false) {
                    Logger::log('warning', 'zipstream', 'Failed to open remote stream for image', ['url' => $url, 'key' => $key]);
                    continue;
                }

                // Create a temporary local buffer file for this entry
                $entryTmp = tempnam($tmpDir, 'ap_entry_');
                if ($entryTmp === false) {
                    Logger::log('warning', 'zipstream', 'Failed to create entry temp file', ['key' => $key]);
                    fclose($handle);
                    continue;
                }

                $out = fopen($entryTmp, 'wb');
                if ($out === false) {
                    Logger::log('warning', 'zipstream', 'Failed to open entry temp file for writing', ['entry' => $entryTmp]);
                    fclose($handle);
                    @unlink($entryTmp);
                    continue;
                }

                // Copy stream in chunks
                while (!feof($handle)) {
                    $chunk = fread($handle, 1024 * 1024);
                    if ($chunk === false) {
                        break;
                    }
                    fwrite($out, $chunk);
                }

                fclose($handle);
                fclose($out);

                $filename = basename($key);
                $zip->addFile($entryTmp, $filename);

                // Remove entry temp file after adding (ZipArchive copies it into archive)
                @unlink($entryTmp);
            }

            $zip->close();

            // Stream the temp zip file to client
            $zipName = 'aperture-download-' . substr($token, 0, 8) . '.zip';
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . rawurlencode($zipName) . '"');
            header('Content-Length: ' . filesize($tmpFile));
            header('Pragma: public');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Expires: 0');

            // Clear output buffers
            while (ob_get_level()) {
                ob_end_clean();
            }

            $fp = fopen($tmpFile, 'rb');
            if ($fp === false) {
                Logger::log('error', 'zipstream', 'Failed to open generated ZIP for streaming', ['tmp' => $tmpFile, 'notify_admin' => true]);
                @unlink($tmpFile);
                return ['success' => false, 'error' => 'stream_failed', 'message' => 'Unable to stream ZIP.', 'status' => 500];
            }

            // Stream in chunks
            $chunkSize = 1024 * 1024;
            while (!feof($fp)) {
                echo fread($fp, $chunkSize);
                if (function_exists('fastcgi_finish_request')) {
                    @flush();
                } else {
                    @flush();
                    @ob_flush();
                }
            }

            fclose($fp);
            @unlink($tmpFile);

            // Streaming completed
            return ['success' => true];
        } catch (\Throwable $e) {
            // Ensure zip closed and temp file removed
            @$zip->close();
            @unlink($tmpFile);
            Logger::log('error', 'zipstream', 'ZipArchive streaming failed: ' . $e->getMessage(), ['token' => $token, 'notify_admin' => true]);
            return ['success' => false, 'error' => 'stream_failed', 'message' => 'Failed to stream ZIP.', 'status' => 500];
        }
    }
}
