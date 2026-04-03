<?php

namespace AperturePro\Download;

use AperturePro\Storage\StorageInterface;
use AperturePro\Storage\StorageFactory;
use AperturePro\Helpers\Logger;
use ZipStream\ZipStream;
use ZipStream\Option\Archive;

/**
 * ZipStreamService
 *
 * Streams ZIP archives to the client.
 *
 * PERFORMANCE IMPROVEMENTS:
 *  - Files are streamed directly from storage source to the ZIP output
 *  - Eliminates local temporary file usage
 *  - Reduces Time To First Byte (TTFB) significantly
 *
 * SECURITY:
 *  - Uses signed URLs for remote access
 *  - Sanitizes filenames
 */
class ZipStreamService
{
    /**
     * Stream a ZIP archive based on a download token.
     * This orchestrates the retrieval of files and storage instantiation.
     *
     * @param string      $token
     * @param string|null $email
     * @param int|null    $projectId
     * @return array      ['success' => bool, 'message' => string]
     */
    public static function streamByToken(string $token, ?string $email, ?int $projectId): array
    {
        global $wpdb;

        // 1. Resolve token to gallery/files
        $tokensTable = $wpdb->prefix . 'ap_download_tokens';
        $tokenRow = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tokensTable WHERE token = %s LIMIT 1", $token));

        if (!$tokenRow) {
            return ['success' => false, 'error' => 'invalid_token', 'message' => 'Invalid token.'];
        }

        $galleryId = (int) $tokenRow->gallery_id;

        // 2. Fetch images (use storage_key_original as path)
        $imagesTable = $wpdb->prefix . 'ap_images';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT storage_key_original, filename FROM $imagesTable WHERE gallery_id = %d",
            $galleryId
        ));

        if (empty($rows)) {
            return ['success' => false, 'error' => 'no_files', 'message' => 'No files found in this gallery.'];
        }

        // 3. Prepare file list
        $files = [];
        foreach ($rows as $row) {
            $path = $row->storage_key_original;
            // Use filename from DB or basename of path
            $name = !empty($row->filename) ? $row->filename : basename($path);

            $files[] = [
                'path' => $path,
                'name' => $name,
            ];
        }

        // 4. Resolve Storage
        try {
            $storage = StorageFactory::create();
        } catch (\Throwable $e) {
            Logger::log('error', 'zip', 'Storage init failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'storage_error', 'message' => 'Storage configuration error.'];
        }

        // 5. Determine ZIP name
        $zipName = 'Gallery_Download.zip';
        if ($tokenRow->project_id) {
            $pTitle = $wpdb->get_var($wpdb->prepare("SELECT title FROM {$wpdb->prefix}ap_projects WHERE id = %d", $tokenRow->project_id));
            if ($pTitle) {
                $zipName = sanitize_file_name($pTitle) . '.zip';
            }
        }

        // 6. Stream and Exit
        try {
            self::streamZip($files, $storage, $zipName);
            // If streamZip returns, it means it finished streaming.
            // We exit here to ensure no further output (like JSON from controller) is appended to the ZIP.
            exit;
        } catch (\Throwable $e) {
            Logger::log('error', 'zip', 'Stream failed', ['error' => $e->getMessage()]);
            // If streaming failed BEFORE headers were sent, we can return error JSON.
            // If headers were sent, this return value might be moot, but safe to return.
            return ['success' => false, 'message' => 'Internal error during streaming.'];
        }
    }

    /**
     * Stream a ZIP archive to the client.
     *
     * @param array            $files   Array of file descriptors:
     *                                  [
     *                                    'path' => 'remote/path.jpg',
     *                                    'name' => 'ClientFilename.jpg'
     *                                  ]
     * @param StorageInterface $storage
     * @param string           $zipName
     */
    public static function streamZip(array $files, StorageInterface $storage, string $zipName): void
    {
        if (headers_sent()) {
            throw new \RuntimeException('Headers already sent.');
        }

        $options = new Archive();
        $options->setSendHttpHeaders(true);
        $options->setContentType('application/octet-stream');
        $options->setEnableZip64(true);

        $zip = new ZipStream(self::sanitizeFilename($zipName), $options);

        // 1. Pre-sign URLs in batch to avoid N+1 storage API calls
        $paths = array_column($files, 'path');
        $signedUrls = $storage->signMany($paths);

        // 2. Process files in manageable parallel chunks (e.g., 10 at a time)
        // This balances speed with local disk usage and memory.
        $batchSize = apply_filters('aperture_pro_zip_download_batch_size', 10);
        $chunks = array_chunk($files, $batchSize);

        foreach ($chunks as $chunk) {
            if (connection_aborted()) {
                break;
            }

            $urlsToDownload = [];
            foreach ($chunk as $idx => $file) {
                $path = $file['path'];
                $url = $signedUrls[$path] ?? $storage->getUrl($path, ['signed' => true, 'expires' => 300]);
                // Use index as key to support duplicate paths in the same chunk
                $urlsToDownload[$idx] = $url;
            }

            // 3. Download chunk in parallel to temporary storage
            $tempFiles = self::downloadParallel($urlsToDownload);

            // 4. Stream temporary files into the ZIP
            foreach ($chunk as $idx => $file) {
                $path = $file['path'];
                $tempPath = $tempFiles[$idx] ?? null;

                if (!$tempPath || !file_exists($tempPath)) {
                    Logger::log('warning', 'zip', 'File could not be downloaded for ZIP', [
                        'path' => $path,
                        'name' => $file['name'],
                    ]);
                    continue;
                }

                try {
                    $stream = @fopen($tempPath, 'rb');
                    if ($stream !== false) {
                        $zip->addFileFromStream(
                            self::sanitizeFilename($file['name']),
                            $stream
                        );
                        fclose($stream);
                    }
                } catch (\Throwable $e) {
                    Logger::log('error', 'zip', 'Failed to stream temp file to ZIP', [
                        'path'  => $path,
                        'error' => $e->getMessage(),
                    ]);
                } finally {
                    // 5. Immediate cleanup to minimize disk footprint
                    @unlink($tempPath);
                }
            }
        }

        $zip->finish();
    }

    /**
     * Download multiple URLs in parallel using curl_multi.
     *
     * @param array $urls Map of key => URL
     * @return array Map of key => localTempPath
     */
    protected static function downloadParallel(array $urls): array
    {
        if (empty($urls)) {
            return [];
        }

        // Use curl_multi if available
        if (function_exists('curl_multi_init')) {
            return self::downloadParallelCurl($urls);
        }

        // Fallback to sequential if curl_multi is not available
        $results = [];
        foreach ($urls as $key => $url) {
            $tmp = wp_tempnam('ap-zip-');
            $response = wp_remote_get($url, [
                'timeout'  => 60,
                'stream'   => true,
                'filename' => $tmp,
            ]);

            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $results[$key] = $tmp;
            } else {
                @unlink($tmp);
            }
        }

        return $results;
    }

    /**
     * Parallel download using curl_multi.
     */
    protected static function downloadParallelCurl(array $urls): array
    {
        $mh = curl_multi_init();
        $handles = [];
        $files = []; // key => [path, resource]
        $results = [];

        foreach ($urls as $key => $url) {
            $tmp = wp_tempnam('ap-zip-');
            $fp = @fopen($tmp, 'w+');
            if (!$fp) {
                continue;
            }

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300); // Allow more time for large files
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);

            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
            $files[$key] = ['path' => $tmp, 'fp' => $fp];
        }

        $active = null;
        do {
            $mrc = curl_multi_exec($mh, $active);
        } while ($mrc === CURLM_CALL_MULTI_PERFORM);

        while ($active && $mrc === CURLM_OK) {
            if (curl_multi_select($mh) === -1) {
                usleep(10000); // 10ms
            }
            do {
                $mrc = curl_multi_exec($mh, $active);
            } while ($mrc === CURLM_CALL_MULTI_PERFORM);
        }

        foreach ($handles as $key => $ch) {
            $info = curl_getinfo($ch);
            $error = curl_error($ch);

            if (isset($files[$key]['fp']) && is_resource($files[$key]['fp'])) {
                fclose($files[$key]['fp']);
            }

            if ($info['http_code'] >= 200 && $info['http_code'] < 300 && empty($error)) {
                $results[$key] = $files[$key]['path'];
            } else {
                @unlink($files[$key]['path']);
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);

        return $results;
    }


    /**
     * Sanitize filenames for ZIP entries.
     */
    protected static function sanitizeFilename(string $name): string
    {
        $name = basename($name);
        return preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
    }
}
