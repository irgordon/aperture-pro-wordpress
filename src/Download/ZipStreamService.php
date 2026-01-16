<?php

namespace AperturePro\Download;

use AperturePro\Storage\StorageInterface;
use AperturePro\Helpers\Logger;
use ZipStream\ZipStream;
use ZipStream\Option\Archive;

/**
 * ZipStreamService
 *
 * Streams ZIP archives to the client.
 *
 * PERFORMANCE IMPROVEMENTS:
 *  - Remote files are downloaded in parallel using curl_multi
 *  - Bounded concurrency prevents memory exhaustion
 *  - ZIP entries are streamed from local temp files (fast + predictable)
 *
 * SECURITY:
 *  - Uses signed URLs for remote access
 *  - Sanitizes filenames
 *  - Cleans up temp files deterministically
 */
class ZipStreamService
{
    /** Max concurrent remote downloads */
    const MAX_CONCURRENT_DOWNLOADS = 5;

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

        // Phase 1: Download files in parallel to temp storage
        $tempFiles = self::downloadFilesInParallel($files, $storage);

        // Phase 2: Stream ZIP entries from local temp files
        foreach ($tempFiles as $entry) {
            if (!is_readable($entry['tmp'])) {
                Logger::log('warning', 'zip', 'Temp file missing, skipping', [
                    'name' => $entry['name'],
                ]);
                continue;
            }

            $zip->addFileFromPath(
                $entry['name'],
                $entry['tmp']
            );

            @unlink($entry['tmp']);
        }

        $zip->finish();
    }

    /**
     * Download remote files concurrently using curl_multi.
     *
     * @param array            $files
     * @param StorageInterface $storage
     * @return array           Array of ['name' => string, 'tmp' => string]
     */
    protected static function downloadFilesInParallel(array $files, StorageInterface $storage): array
    {
        $multiHandle = curl_multi_init();
        $handles = [];
        $results = [];
        $queue = $files;

        while (!empty($queue) || !empty($handles)) {
            // Fill concurrency slots
            while (count($handles) < self::MAX_CONCURRENT_DOWNLOADS && !empty($queue)) {
                $file = array_shift($queue);

                try {
                    $url = $storage->getSignedUrl($file['path'], 900);
                } catch (\Throwable $e) {
                    Logger::log('error', 'zip', 'Failed to get signed URL', [
                        'path' => $file['path'],
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }

                $tmp = wp_tempnam('ap-zip-');
                if (!$tmp) {
                    Logger::log('error', 'zip', 'Failed to create temp file');
                    continue;
                }

                $fp = fopen($tmp, 'w+b');
                if (!$fp) {
                    @unlink($tmp);
                    continue;
                }

                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_FILE => $fp,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 300,
                    CURLOPT_CONNECTTIMEOUT => 15,
                    CURLOPT_FAILONERROR => true,
                ]);

                curl_multi_add_handle($multiHandle, $ch);

                $handles[(int)$ch] = [
                    'handle' => $ch,
                    'fp' => $fp,
                    'tmp' => $tmp,
                    'name' => self::sanitizeFilename($file['name']),
                ];
            }

            // Execute
            do {
                $status = curl_multi_exec($multiHandle, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            // Read completed transfers
            while ($info = curl_multi_info_read($multiHandle)) {
                $ch = $info['handle'];
                $key = (int)$ch;

                if (!isset($handles[$key])) {
                    continue;
                }

                $entry = $handles[$key];
                fclose($entry['fp']);

                if ($info['result'] === CURLE_OK) {
                    $results[] = [
                        'name' => $entry['name'],
                        'tmp' => $entry['tmp'],
                    ];
                } else {
                    Logger::log('error', 'zip', 'Download failed', [
                        'name' => $entry['name'],
                        'error' => curl_error($ch),
                    ]);
                    @unlink($entry['tmp']);
                }

                curl_multi_remove_handle($multiHandle, $ch);
                curl_close($ch);
                unset($handles[$key]);
            }

            if ($running) {
                curl_multi_select($multiHandle, 1.0);
            }
        }

        curl_multi_close($multiHandle);
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
