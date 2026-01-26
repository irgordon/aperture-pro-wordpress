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

        foreach ($files as $file) {
            try {
                // Get signed URL with short expiry
                $url = $storage->getSignedUrl($file['path'], 300);

                // Open stream to remote file
                $stream = @fopen($url, 'rb');

                if ($stream === false) {
                    Logger::log('warning', 'zip', 'Could not open stream for file', [
                        'path' => $file['path'],
                        'name' => $file['name'],
                    ]);
                    continue;
                }

                $zip->addFileFromStream(
                    self::sanitizeFilename($file['name']),
                    $stream
                );

                fclose($stream);

            } catch (\Throwable $e) {
                Logger::log('error', 'zip', 'Failed to stream file', [
                    'path' => $file['path'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $zip->finish();
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
