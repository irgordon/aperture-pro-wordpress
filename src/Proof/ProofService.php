<?php

namespace AperturePro\Proof;

use AperturePro\Storage\StorageFactory;
use AperturePro\Storage\StorageInterface;
use AperturePro\Helpers\Logger;
use AperturePro\Proof\ProofCache;
use AperturePro\Proof\ProofQueue;
use AperturePro\Config\Config;

/**
 * ProofService
 *
 * Responsible for generating and serving proof images:
 *  - Watermarked, low-resolution variants of originals
 *  - URLs clearly labeled as "proof copies"
 *
 * PERFORMANCE:
 *  - getProofUrlForImage() accepts an optional StorageInterface instance.
 *    If provided, we reuse it instead of calling StorageFactory::create()
 *    internally, avoiding repeated config decryption in tight loops.
 *
 * CONFIGURABLE QUALITY / SIZE:
 *  - Max proof dimension and JPEG quality are configurable via filters:
 *      - aperture_pro_proof_max_size   (int)
 *      - aperture_pro_proof_quality   (int)
 *  - Values are clamped to safe bounds:
 *      - max_size:  800–2400 px
 *      - quality:   40–85
 *  - This keeps proofs clearly “proof-only” while allowing tuning.
 */
class ProofService
{
    /**
     * Get proof URLs for multiple images in batch.
     *
     * PERFORMANCE:
     *  - Uses StorageInterface::existsMany() to check for existing proofs in parallel.
     *  - Generates missing proofs sequentially.
     *
     * @param array                 $images   List of image records.
     * @param StorageInterface|null $storage  Optional storage driver.
     *
     * @return array Map of image index/ID to proof URL. Key matches input array key.
     */
    public static function getProofUrls(array $images, ?StorageInterface $storage = null): array
    {
        // 0. Static Request Cache (Fastest)
        // Avoids re-hashing and transient lookup within the same request.
        static $requestCache = [];

        // We use a hash of the images array as the key to ensure we return the correct set.
        $cacheKey = ProofCache::generateKey('batch', $images);

        if (isset($requestCache[$cacheKey])) {
            return $requestCache[$cacheKey];
        }

        // 1. Try Persistent Cache (Transient)
        $cached = ProofCache::get($cacheKey);
        if ($cached !== null) {
            $requestCache[$cacheKey] = $cached;
            return $cached;
        }

        if ($storage === null) {
            $storage = StorageFactory::create();
        }

        $proofPaths = [];
        $originalPaths = [];
        $urls = [];

        // 2. Calculate paths
        foreach ($images as $key => $image) {
            $originalPath = $image['path'] ?? $image['filename'] ?? null;
            if ($originalPath) {
                $proofPaths[$key] = self::getProofPath($originalPath);
                $originalPaths[$key] = $originalPath;
            }
        }

        if (empty($proofPaths)) {
            return [];
        }

        // 3. Batch check existence (Optimized)
        // We only check storage for items that are not already flagged as existing in DB.
        $pathsToCheck = [];
        $verifiedPaths = [];

        foreach ($proofPaths as $key => $path) {
            if (!empty($images[$key]['has_proof'])) {
                $verifiedPaths[$path] = true;
            } else {
                $pathsToCheck[] = $path;
            }
        }

        $pathsToCheck = array_unique($pathsToCheck);
        $existenceMap = [];

        if (!empty($pathsToCheck)) {
            $existenceMap = $storage->existsMany($pathsToCheck);

            // Lazy Migration: If we found proofs that weren't flagged in DB, flag them now.
            $foundIds = [];
            foreach ($images as $key => $image) {
                // If this image was checked (not verified initially) AND exists in storage
                $pPath = $proofPaths[$key] ?? '';
                if (empty($image['has_proof']) && !empty($existenceMap[$pPath])) {
                    if (isset($image['id']) && is_numeric($image['id'])) {
                        $foundIds[] = (int) $image['id'];
                    }
                }
            }

            if (!empty($foundIds)) {
                ProofQueue::markProofsAsExisting($foundIds);
            }
        }

        // Merge verified results
        foreach ($verifiedPaths as $path => $exists) {
            $existenceMap[$path] = true;
        }

        // 4. Identify Existing vs Missing
        $toEnqueue = [];
        $existingPaths = [];

        foreach ($proofPaths as $key => $proofPath) {
            $exists = $existenceMap[$proofPath] ?? false;

            if (!$exists) {
                // OFFLOAD: Do not generate synchronously. Queue it.
                $originalPath = $originalPaths[$key];
                $imageRecord  = $images[$key] ?? [];

                $queueItem = [
                    'original_path' => $originalPath,
                    'proof_path'    => $proofPath,
                ];

                // Pass IDs if available (for optimized queue)
                if (isset($imageRecord['project_id'], $imageRecord['id'])) {
                    $queueItem['project_id'] = $imageRecord['project_id'];
                    $queueItem['image_id']   = $imageRecord['id'];
                }

                $toEnqueue[] = $queueItem;

                // Return placeholder
                $urls[$key] = self::getPlaceholderUrl();
            } else {
                // Mark for batch signing
                $existingPaths[$proofPath] = $proofPath;
            }
        }

        // 5. Batch Sign Existing URLs (Optimized)
        if (!empty($existingPaths)) {
            $signedMap = $storage->signMany(array_values($existingPaths));

            foreach ($proofPaths as $key => $proofPath) {
                // Only fill if we haven't already set a placeholder
                if (!isset($urls[$key]) && isset($signedMap[$proofPath])) {
                    $urls[$key] = $signedMap[$proofPath];
                }
            }
        }

        // 6. Batch enqueue missing proofs
        if (!empty($toEnqueue)) {
            // Split items into those with IDs (optimized) and legacy items (paths only)
            $batchIds = [];
            $legacyItems = [];

            foreach ($toEnqueue as $item) {
                if (isset($item['project_id'], $item['image_id'])) {
                    $batchIds[] = [
                        'project_id' => $item['project_id'],
                        'image_id'   => $item['image_id'],
                    ];
                } else {
                    $legacyItems[] = $item;
                }
            }

            // Try to resolve IDs for legacy items
            if (!empty($legacyItems)) {
                $pathsToResolve = [];
                foreach ($legacyItems as $lItem) {
                    if (isset($lItem['original_path'])) {
                        $pathsToResolve[] = $lItem['original_path'];
                    }
                }

                $resolvedMap = self::resolveBatchIds($pathsToResolve);
                $stillLegacy = [];

                foreach ($legacyItems as $lItem) {
                    $path = $lItem['original_path'] ?? '';
                    if (isset($resolvedMap[$path])) {
                        $batchIds[] = [
                            'project_id' => $resolvedMap[$path]['project_id'],
                            'image_id'   => $resolvedMap[$path]['image_id'],
                        ];
                    } else {
                        $stillLegacy[] = $lItem;
                    }
                }
                $legacyItems = $stillLegacy;
            }

            // Optimized batch insert for items with IDs
            if (!empty($batchIds)) {
                ProofQueue::addBatch($batchIds);
            }

            // Batch enqueue for legacy items
            if (!empty($legacyItems)) {
                ProofQueue::enqueueBatch($legacyItems);
            }
        }

        // Cache the result
        ProofCache::set($cacheKey, $urls);
        $requestCache[$cacheKey] = $urls;

        return $urls;
    }

    /**
     * Get a proof URL for a given image record.
     *
     * @param array                 $image    Image record (expects at least 'path' or 'filename')
     * @param StorageInterface|null $storage  Optional pre-instantiated storage driver
     *
     * @return string
     *
     * @throws \RuntimeException on failure
     */
    public static function getProofUrlForImage(array $image, ?StorageInterface $storage = null): string
    {
        // Use provided storage if available; otherwise instantiate lazily.
        if ($storage === null) {
            $storage = StorageFactory::create();
        }

        // Derive proof path (e.g., add suffix or folder).
        $originalPath = $image['path'] ?? $image['filename'] ?? null;
        if (!$originalPath) {
            throw new \RuntimeException('Missing image path for proof generation.');
        }

        $proofPath = self::getProofPath($originalPath);

        // Ensure proof exists; if missing, queue and return placeholder.
        if (!$storage->exists($proofPath)) {
            if (isset($image['project_id'], $image['id']) && is_numeric($image['project_id']) && is_numeric($image['id'])) {
                ProofQueue::add((int) $image['project_id'], (int) $image['id']);
            } else {
                // Try to resolve IDs first
                $ids = self::resolveIdsFromPath($originalPath);
                if ($ids) {
                    ProofQueue::add($ids['project_id'], $ids['image_id']);
                } else {
                    ProofQueue::enqueue($originalPath, $proofPath);
                }
            }
            return self::getPlaceholderUrl();
        }

        // Use signed URL or public URL depending on your policy.
        // For proof copies, short-lived signed URLs are recommended.
        $url = $storage->getUrl($proofPath, ['signed' => true, 'expires' => 3600]);

        return $url;
    }

    /**
     * Compute the proof path for a given original path.
     *
     * Example:
     *  original: projects/123/image-1.jpg
     *  proof:    proofs/123/image-1_proof.jpg
     */
    public static function getProofPathForOriginal(string $originalPath): string
    {
        return self::getProofPath($originalPath);
    }

    /**
     * Internal helper. Use getProofPathForOriginal externally.
     */
    protected static function getProofPath(string $originalPath): string
    {
        $parts = pathinfo($originalPath);
        $dir   = $parts['dirname'] !== '.' ? $parts['dirname'] : '';
        $name  = $parts['filename'] ?? 'image';
        $ext   = isset($parts['extension']) ? '.' . $parts['extension'] : '';

        $proofName = $name . '_proof' . $ext;

        if ($dir !== '') {
            return 'proofs/' . $dir . '/' . $proofName;
        }

        return 'proofs/' . $proofName;
    }

    /**
     * Get URL for a placeholder image (processing state).
     */
    public static function getPlaceholderUrl(): string
    {
        // 1. Check for Config override
        $customUrl = Config::get('proofing.placeholder_url');
        if (!empty($customUrl)) {
            return apply_filters('aperture_pro_proof_placeholder_url', $customUrl);
        }

        // 2. Fallback to local asset
        if (function_exists('plugins_url')) {
            $defaultUrl = plugins_url('assets/images/processing-proof.svg', APERTURE_PRO_FILE);
        } else {
            // If 'plugins_url' is not available (e.g. CLI or weird context), fallback to relative path
            $defaultUrl = '/wp-content/plugins/aperture-pro/assets/images/processing-proof.svg';
        }

        return apply_filters('aperture_pro_proof_placeholder_url', $defaultUrl);
    }

    /**
     * Generate proofs in batch to optimize network I/O.
     *
     * @param array            $items   List of ['original_path' => ..., 'proof_path' => ...]
     * @param StorageInterface $storage
     * @return array Map of proof_path => bool (success/failure)
     */
    public static function generateBatch(array $items, StorageInterface $storage): array
    {
        $results = [];
        $downloadMap = [];

        // 1. Prepare Downloads
        foreach ($items as $key => $item) {
            $originalPath = $item['original_path'];
            $downloadMap[$key] = $originalPath;
        }

        // 2. Batch Download (Parallel)
        $tempFiles = self::downloadBatchToTemp($downloadMap, $storage);

        // 3. Process Locally (Create Proofs)
        $filesToUpload = [];
        $tempProofs = [];

        foreach ($items as $key => $item) {
            $proofPath = $item['proof_path'];
            $tempOriginal = $tempFiles[$key] ?? null;

            if (!$tempOriginal || !file_exists($tempOriginal)) {
                Logger::log('error', 'proofs', 'Missing original for batch item', ['path' => $item['original_path']]);
                $results[$proofPath] = false;
                continue;
            }

            // Generate Proof (CPU intensive)
            $tmpProof = self::createWatermarkedLowRes($tempOriginal);

            // Cleanup temp original immediately to free space
            @unlink($tempOriginal);

            if ($tmpProof && is_readable($tmpProof)) {
                $tempProofs[$proofPath] = $tmpProof;
                $filesToUpload[] = [
                    'source'  => $tmpProof,
                    'target'  => $proofPath,
                    'options' => [
                        'content_type' => 'image/jpeg',
                        'acl'          => 'private',
                    ],
                ];
            } else {
                Logger::log('error', 'proofs', 'Failed to create watermarked proof', [
                    'proofPath' => $proofPath,
                ]);
                $results[$proofPath] = false;
            }
        }

        // 4. Batch Upload (Network I/O)
        if (!empty($filesToUpload)) {
            // Use parallel upload if supported by driver
            $uploadResults = $storage->uploadMany($filesToUpload);

            foreach ($uploadResults as $target => $res) {
                $results[$target] = $res['success'];
                if (!$res['success']) {
                    Logger::log('error', 'proofs', 'Batch upload failed: ' . ($res['error'] ?? 'Unknown'), ['target' => $target]);
                }
            }
        }

        // 5. Cleanup Temp Proofs
        foreach ($tempProofs as $tmp) {
            @unlink($tmp);
        }

        return $results;
    }

    /**
     * Generate a watermarked, low-resolution proof variant.
     *
     * SECURITY / UX:
     *  - Proofs are intentionally lower resolution and watermarked to deter
     *    unauthorized use.
     *  - Overlay text clearly indicates "PROOF COPY - NOT FINAL QUALITY".
     *  - Max size and quality are configurable via filters but clamped to safe
     *    bounds so proofs remain clearly non-final.
     *
     * @param string           $originalPath
     * @param string           $proofPath
     * @param StorageInterface $storage
     *
     * @return bool
     */
    public static function generateProofVariant(string $originalPath, string $proofPath, StorageInterface $storage): bool
    {
        try {
            // Fetch original file to a temp location.
            $tmpOriginal = self::downloadToTemp($originalPath, $storage);
            if (!$tmpOriginal || !is_readable($tmpOriginal)) {
                Logger::log('error', 'proofs', 'Failed to download original for proof generation', [
                    'originalPath' => $originalPath,
                ]);
                return false;
            }

            $success = self::processAndUpload($tmpOriginal, $proofPath, $storage);

            @unlink($tmpOriginal);
            return $success;
        } catch (\Throwable $e) {
            Logger::log('error', 'proofs', 'Exception during proof generation: ' . $e->getMessage(), [
                'originalPath' => $originalPath,
            ]);
            return false;
        }
    }

    /**
     * Process a local original file into a proof and upload it.
     *
     * @param string           $localOriginal Path to local source file
     * @param string           $proofPath     Destination path
     * @param StorageInterface $storage       Storage driver
     * @return bool
     */
    protected static function processAndUpload(string $localOriginal, string $proofPath, StorageInterface $storage): bool
    {
        $tmpProof = self::createWatermarkedLowRes($localOriginal);

        if (!$tmpProof || !is_readable($tmpProof)) {
            Logger::log('error', 'proofs', 'Failed to create watermarked proof', [
                'proofPath' => $proofPath,
            ]);
            return false;
        }

        // Upload proof back to storage.
        try {
            $storage->upload($tmpProof, $proofPath, [
                'content_type' => 'image/jpeg',
                'acl'          => 'private', // proofs should not be world-readable by default
            ]);
        } catch (\Throwable $e) {
            Logger::log('error', 'proofs', 'Upload failed: ' . $e->getMessage());
            @unlink($tmpProof);
            return false;
        }

        @unlink($tmpProof);

        return true;
    }

    /**
     * Download multiple files to temporary locations, using parallel requests if possible.
     *
     * @param array            $paths   Map of key => remotePath
     * @param StorageInterface $storage
     * @return array Map of key => localTempPath
     */
    protected static function downloadBatchToTemp(array $paths, StorageInterface $storage): array
    {
        $results = []; // key => temp_path
        $httpUrls = []; // key => url
        $failedItems = []; // optimization: batch log failures

        foreach ($paths as $key => $remotePath) {
            // 1. Local Storage Optimization
            if ($storage instanceof \AperturePro\Storage\LocalStorage) {
                $localPath = $storage->getLocalPath($remotePath);
                if ($localPath && is_readable($localPath)) {
                    $tmp = wp_tempnam('ap-proof-');
                    if ($tmp && copy($localPath, $tmp)) {
                        $results[$key] = $tmp;
                        continue;
                    }
                }
            }

            // 2. Prepare for HTTP
            try {
                $httpUrls[$key] = $storage->getUrl($remotePath, ['signed' => true, 'expires' => 600]);
            } catch (\Throwable $e) {
                $failedItems[] = $remotePath;
                continue;
            }
        }

        if (!empty($failedItems)) {
            Logger::log('error', 'proofs', 'Failed to get URLs for batch items', [
                'count' => count($failedItems),
                'paths' => array_slice($failedItems, 0, 20) // Log first 20 only
            ]);
        }

        if (empty($httpUrls)) {
            return $results;
        }

        // 3. Parallel Download
        $httpResults = self::performParallelDownloads($httpUrls);

        return $results + $httpResults;
    }

    /**
     * Perform parallel downloads using curl_multi.
     *
     * @param array $urls Map of key => URL
     * @return array Map of key => localTempPath
     */
    protected static function performParallelDownloads(array $urls): array
    {
        if (!function_exists('curl_multi_init')) {
            $results = [];

            // OPTIMIZATION: Try PHP streams for parallel downloads if curl_multi is unavailable
            // This avoids the severe performance penalty of sequential blocking requests.
            // We use this path optimistically for HTTP/HTTPS URLs.
            // Any complex requests (redirects, chunked) will be skipped and handled by the sequential fallback.
            if (function_exists('stream_socket_client')) {
                $canUseStreams = true;
                foreach ($urls as $url) {
                    if (strncasecmp($url, 'http', 4) !== 0) {
                        $canUseStreams = false;
                        break;
                    }
                }
                if ($canUseStreams) {
                    $results = self::performParallelDownloadsStreams($urls);
                }
            }

            // If we successfully downloaded everything, return early.
            if (count($results) === count($urls)) {
                return $results;
            }

            // Fallback to sequential for any items that failed or were skipped by streams
            $remainingUrls = array_diff_key($urls, $results);

            // OPTIMIZATION: Use persistent curl handle if available to enable keep-alive
            if (function_exists('curl_init')) {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_TIMEOUT, 60);

                // Only enable follow location if open_basedir is not set to avoid warnings
                if (ini_get('open_basedir') === '' || ini_get('open_basedir') === false) {
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                }

                foreach ($remainingUrls as $key => $url) {
                    $tmp = wp_tempnam('ap-proof-');
                    $fp = fopen($tmp, 'w+');
                    if (!$fp) {
                        continue;
                    }

                    curl_setopt($ch, CURLOPT_URL, $url);
                    curl_setopt($ch, CURLOPT_FILE, $fp);

                    $success = curl_exec($ch);
                    $info = curl_getinfo($ch);

                    fclose($fp);

                    if ($success && $info['http_code'] == 200) {
                        $results[$key] = $tmp;
                    } else {
                        @unlink($tmp);
                    }
                }
                curl_close($ch);
            } else {
                foreach ($remainingUrls as $key => $url) {
                    $tmp = wp_tempnam('ap-proof-');
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
            }

            return $results;
        }

        $mh = curl_multi_init();
        $handles = [];
        $files = []; // key => [path, resource]
        $results = [];

        foreach ($urls as $key => $url) {
            $tmp = wp_tempnam('ap-proof-');
            $fp = fopen($tmp, 'w+');
            if (!$fp) {
                continue;
            }

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
            $files[$key] = ['path' => $tmp, 'fp' => $fp];
        }

        $active = null;
        do {
            $mrc = curl_multi_exec($mh, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);

        while ($active && $mrc == CURLM_OK) {
            if (curl_multi_select($mh) == -1) {
                // OPTIMIZATION: Increased sleep to 10000us (10ms) to prevent CPU starvation
                // When select returns -1, we must yield to avoid a tight loop consuming 100% CPU.
                usleep(10000);
            }
            do {
                $mrc = curl_multi_exec($mh, $active);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        }

        // Collect results
        foreach ($handles as $key => $ch) {
            $info = curl_getinfo($ch);
            $error = curl_error($ch);

            // Close file handle first to flush
            if (isset($files[$key]['fp']) && is_resource($files[$key]['fp'])) {
                fclose($files[$key]['fp']);
            }

            if ($info['http_code'] == 200 && empty($error)) {
                $results[$key] = $files[$key]['path'];
            } else {
                Logger::log('error', 'proofs', 'Parallel download failed', ['url' => $urls[$key], 'code' => $info['http_code'], 'curl_error' => $error]);
                @unlink($files[$key]['path']);
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);

        return $results;
    }

    /**
     * Parallel download fallback using PHP Streams (no curl_multi).
     *
     * @param array $urls Map of key => URL
     * @return array Map of key => localTempPath
     */
    protected static function performParallelDownloadsStreams(array $urls): array
    {
        $sockets = [];
        $files = [];
        $results = [];

        // 1. Initialize Sockets
        foreach ($urls as $key => $url) {
            $parts = parse_url($url);
            $host = $parts['host'] ?? '';
            $port = $parts['port'] ?? 80;
            $scheme = $parts['scheme'] ?? 'http';
            $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

            if ($scheme === 'https') {
                $host = 'ssl://' . $host;
                if ($port === 80) $port = 443;
            }

            $errno = 0;
            $errstr = '';
            // Connect asynchronously
            // SECURITY: Default SSL context verifies peers. Do not disable it.
            $ctx = stream_context_create();
            $fp = @stream_socket_client(
                "$host:$port",
                $errno,
                $errstr,
                30,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
                $ctx
            );

            if ($fp) {
                stream_set_blocking($fp, false);
                $sockets[$key] = $fp;

                $tmp = wp_tempnam('ap-proof-');
                $files[$key] = [
                    'path' => $tmp,
                    'fp' => fopen($tmp, 'w+'),
                    'req' => "GET $path HTTP/1.1\r\nHost: {$parts['host']}\r\nConnection: close\r\nUser-Agent: AperturePro\r\n\r\n",
                    'state' => 'connecting',
                    'url' => $url,
                    'redirects' => 0,
                    // Optimization: Use stream for header buffering instead of string concatenation
                    'header_stream' => fopen('php://temp', 'w+'),
                    'tail' => '',
                ];
            } else {
                 Logger::log('error', 'proofs', 'Stream connect failed', ['url' => $url, 'error' => $errstr]);
            }
        }

        if (empty($sockets)) {
            return [];
        }

        // 2. Event Loop
        $start = time();
        while (!empty($sockets) && (time() - $start < 60)) {
            $read = [];
            $write = [];
            $except = [];

            foreach ($sockets as $key => $socket) {
                if ($files[$key]['state'] === 'connecting' || $files[$key]['state'] === 'writing') {
                    $write[] = $socket;
                }
                $read[] = $socket;
            }

            if (empty($read) && empty($write)) {
                break;
            }

            if (@stream_select($read, $write, $except, 1) === false) {
                break;
            }

            // Handle Writes
            foreach ($write as $socket) {
                $key = array_search($socket, $sockets, true);
                if ($key !== false && isset($files[$key])) {
                     if ($files[$key]['state'] === 'connecting') {
                         $files[$key]['state'] = 'writing';
                     }

                     if ($files[$key]['state'] === 'writing') {
                         $req = $files[$key]['req'];
                         $bytes = @fwrite($socket, $req);
                         if ($bytes === false) {
                             fclose($socket);
                             unset($sockets[$key]);
                         } else {
                             $files[$key]['req'] = substr($req, $bytes);
                             if (empty($files[$key]['req'])) {
                                 $files[$key]['state'] = 'reading';
                             }
                         }
                     }
                }
            }

            // Handle Reads
            foreach ($read as $socket) {
                $key = array_search($socket, $sockets, true);
                if ($key !== false && isset($files[$key])) {
                    $data = @fread($socket, 8192);

                    if ($data === false || ($data === '' && feof($socket))) {
                        fclose($socket);
                        unset($sockets[$key]);
                        // Cleanup header stream if incomplete
                        if (isset($files[$key]['header_stream'])) {
                            fclose($files[$key]['header_stream']);
                        }
                    } elseif ($data !== '') {
                        if (!isset($files[$key]['headers_done'])) {
                            // Buffer to temp stream
                            fwrite($files[$key]['header_stream'], $data);

                            // Check for \r\n\r\n in (tail + data)
                            $check = $files[$key]['tail'] . $data;
                            $pos = strpos($check, "\r\n\r\n");

                            if ($pos !== false) {
                                // Headers completed

                                // Reconstruct full headers to parse them
                                rewind($files[$key]['header_stream']);
                                $fullBuffer = stream_get_contents($files[$key]['header_stream']);

                                // Calculate header length
                                // The position in $check needs to be mapped to the whole stream.
                                // But since we have the full content in $fullBuffer (headers + partial body),
                                // we can just search in $fullBuffer.
                                $headerEndPos = strpos($fullBuffer, "\r\n\r\n");

                                if ($headerEndPos !== false) {
                                    $headerStr = substr($fullBuffer, 0, $headerEndPos);

                                    // Detect Chunked Transfer
                                    if (stripos($headerStr, 'Transfer-Encoding: chunked') !== false) {
                                        // Complex response, abort and fallback
                                        fclose($socket);
                                        unset($sockets[$key]);
                                        @unlink($files[$key]['path']);
                                        fclose($files[$key]['header_stream']);
                                        unset($files[$key]);
                                        continue;
                                    }

                                    // Handle Redirects (3xx)
                                    if (preg_match('|^HTTP/[\d\.]+ (3\d\d)|', $headerStr, $mStatus)) {
                                        // Parse Location
                                        if (preg_match('/^Location:\s*(.+)$/mi', $headerStr, $mLoc)) {
                                            $newUrl = trim($mLoc[1]);
                                            $redirectCount = $files[$key]['redirects'] ?? 0;

                                            if ($redirectCount < 5) {
                                                // Close current connection
                                                fclose($socket);
                                                unset($sockets[$key]);
                                                fclose($files[$key]['header_stream']);

                                                // Reset file for reuse
                                                ftruncate($files[$key]['fp'], 0);
                                                rewind($files[$key]['fp']);

                                                // Resolve URL (Simple resolution)
                                                if (strpos($newUrl, 'http') !== 0) {
                                                    // Relative URL
                                                    $currentUrl = $files[$key]['url'];
                                                    $parts = parse_url($currentUrl);
                                                    $scheme = $parts['scheme'] ?? 'http';
                                                    $host = $parts['host'] ?? '';
                                                    if (strpos($newUrl, '/') === 0) {
                                                        $newUrl = "$scheme://$host$newUrl";
                                                    } else {
                                                        $pathDir = isset($parts['path']) ? dirname($parts['path']) : '/';
                                                        if ($pathDir === '/') $pathDir = '';
                                                        $newUrl = "$scheme://$host$pathDir/$newUrl";
                                                    }
                                                }

                                                // Connect to new URL
                                                $parts = parse_url($newUrl);
                                                $host = $parts['host'] ?? '';
                                                $port = $parts['port'] ?? 80;
                                                $scheme = $parts['scheme'] ?? 'http';
                                                $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

                                                if ($scheme === 'https') {
                                                    $host = 'ssl://' . $host;
                                                    if ($port === 80) $port = 443;
                                                }

                                                $errno = 0; $errstr = '';
                                                // Create context
                                                $ctx = stream_context_create();
                                                $fp = @stream_socket_client(
                                                    "$host:$port",
                                                    $errno,
                                                    $errstr,
                                                    30,
                                                    STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
                                                    $ctx
                                                );

                                                if ($fp) {
                                                    stream_set_blocking($fp, false);
                                                    $sockets[$key] = $fp;

                                                    // Update file state
                                                    $files[$key]['req'] = "GET $path HTTP/1.1\r\nHost: {$parts['host']}\r\nConnection: close\r\nUser-Agent: AperturePro\r\n\r\n";
                                                    $files[$key]['state'] = 'connecting';
                                                    $files[$key]['url'] = $newUrl;
                                                    $files[$key]['redirects'] = $redirectCount + 1;
                                                    $files[$key]['header_stream'] = fopen('php://temp', 'w+');
                                                    $files[$key]['tail'] = '';

                                                    // Clear previous state
                                                    unset($files[$key]['headers_done']);
                                                    unset($files[$key]['status']);

                                                    continue; // Continue loop with new socket
                                                } else {
                                                    Logger::log('error', 'proofs', 'Stream redirect connect failed', ['url' => $newUrl, 'error' => $errstr]);
                                                }
                                            }
                                        }

                                        // If we get here, redirect failed or limit reached
                                        fclose($socket);
                                        unset($sockets[$key]);
                                        @unlink($files[$key]['path']);
                                        if (isset($files[$key]['header_stream'])) fclose($files[$key]['header_stream']);
                                        unset($files[$key]);
                                        continue;
                                    }

                                    if (preg_match('|^HTTP/[\d\.]+ (\d+)|', $headerStr, $m)) {
                                        $files[$key]['status'] = (int)$m[1];
                                    }

                                    // Write body part to file
                                    $body = substr($fullBuffer, $headerEndPos + 4);
                                    if ($body !== '') {
                                        fwrite($files[$key]['fp'], $body);
                                    }

                                    $files[$key]['headers_done'] = true;

                                    // Free memory
                                    fclose($files[$key]['header_stream']);
                                    unset($files[$key]['header_stream']);
                                    unset($files[$key]['tail']);
                                }
                            } else {
                                // Update tail (last 3 chars)
                                $len = strlen($data);
                                if ($len >= 3) {
                                    $files[$key]['tail'] = substr($data, -3);
                                } else {
                                    $files[$key]['tail'] = substr($files[$key]['tail'] . $data, -3);
                                }
                            }
                        } else {
                            fwrite($files[$key]['fp'], $data);
                        }
                    }
                }
            }
        }

        // 3. Finalize
        foreach ($files as $key => $file) {
            if (isset($file['fp']) && is_resource($file['fp'])) {
                fclose($file['fp']);
            }
            if (isset($file['header_stream']) && is_resource($file['header_stream'])) {
                fclose($file['header_stream']);
            }

            if (isset($file['status']) && $file['status'] >= 200 && $file['status'] < 300 && filesize($file['path']) > 0) {
                $results[$key] = $file['path'];
            } else {
                @unlink($file['path']);
            }
            // Cleanup remaining sockets
            if (isset($sockets[$key]) && is_resource($sockets[$key])) {
                @fclose($sockets[$key]);
            }
        }

        return $results;
    }

    /**
     * Download a remote file from storage to a temporary local path.
     *
     * NOTE:
     *  - This assumes your StorageInterface has a way to get a stream or contents.
     *    If not, you may need to extend the interface or use a driver-specific method.
     */
    protected static function downloadToTemp(string $remotePath, StorageInterface $storage): ?string
    {
        // OPTIMIZATION: If storage is local, copy directly to avoid HTTP self-fetch overhead/issues.
        if ($storage instanceof \AperturePro\Storage\LocalStorage) {
            $localPath = $storage->getLocalPath($remotePath);
            if ($localPath && is_readable($localPath)) {
                $tmp = wp_tempnam('ap-proof-');
                if ($tmp && copy($localPath, $tmp)) {
                    return $tmp;
                }
            }
        }

        // For simplicity, assume StorageInterface has getUrl() and we use file_get_contents().
        // In a hardened implementation, you might use driver-specific SDK calls instead.
        $url = $storage->getUrl($remotePath, ['signed' => true, 'expires' => 600]);

        $tmp = wp_tempnam('ap-proof-');
        if (!$tmp) {
            return null;
        }

        // OPTIMIZATION: Use wp_remote_get with stream=true to avoid loading entire file into RAM.
        $response = wp_remote_get($url, [
            'timeout'  => 60,
            'stream'   => true,
            'filename' => $tmp,
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            // Logger::log('error', 'proofs', 'Failed to download original via wp_remote_get', ['url' => $url, 'error' => is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($response)]);
            @unlink($tmp);
            return null;
        }

        return $tmp;
    }

    /**
     * Create a watermarked, low-resolution proof image from a local original.
     *
     * - Resizes to a configurable max width/height (default 1600px).
     * - Adds a semi-transparent "PROOF COPY" text overlay.
     * - Intentionally reduces quality (configurable) to discourage reuse.
     *
     * CONFIG:
     *  - aperture_pro_proof_max_size (int)   default 1600, clamped 800–2400
     *  - aperture_pro_proof_quality (int)    default 65,   clamped 40–85
     *
     * @param string $localOriginal
     * @return string|null Path to the generated proof file
     */
    protected static function createWatermarkedLowRes(string $localOriginal): ?string
    {
        $maxSize = self::getConfiguredMaxSize();
        $quality = self::getConfiguredQuality();

        if (!extension_loaded('imagick') && !extension_loaded('gd')) {
            // Fallback: Check if user explicitly allows original exposure (INSECURE)
            $allowOriginal = Config::get('proofing.allow_original_fallback', false);

            if ($allowOriginal) {
                Logger::log('warning', 'proofs', 'Generating proof by copying original (Missing Image Libs)', [
                    'original' => $localOriginal
                ]);

                $tmp = wp_tempnam('ap-proof-');
                if (!$tmp) {
                    return null;
                }
                copy($localOriginal, $tmp);
                return $tmp;
            }

            // Secure Fallback: Return a low-res placeholder (SVG)
            Logger::log('error', 'proofs', 'Missing GD/Imagick. Returning placeholder proof.', [
                'original' => $localOriginal
            ]);

            return self::generateSVGPlaceholder($localOriginal);
        }

        $tmp = wp_tempnam('ap-proof-');
        if (!$tmp) {
            return null;
        }

        // Prefer Imagick if available.
        if (extension_loaded('imagick')) {
            try {
                // OPTIMIZATION: Use pingImage + jpeg:size hint to avoid loading full resolution into RAM.
                // This lets libjpeg downscale during decode, significantly reducing memory usage.
                $img = new \Imagick();
                $img->pingImage($localOriginal);

                // Only apply hint for JPEG formats where libjpeg supports it.
                $format = strtoupper($img->getImageFormat());
                if ($format === 'JPEG' || $format === 'JPG') {
                    // Set size hint slightly larger than target to ensure quality
                    $img->setOption('jpeg:size', ($maxSize) . 'x' . ($maxSize));
                }

                $img->readImage($localOriginal);
                $img->setImageFormat('jpeg');

                // Resize to max dimension.
                $img->thumbnailImage($maxSize, $maxSize, true);

                // Add watermark text.
                $draw = new \ImagickDraw();
                $draw->setFillColor('rgba(255,255,255,0.35)');
                $draw->setFontSize(36);
                $draw->setGravity(\Imagick::GRAVITY_SOUTHEAST);
                $img->annotateImage($draw, 10, 10, 0, 'PROOF COPY - NOT FINAL QUALITY');

                // Lower quality intentionally (configurable, clamped).
                $img->setImageCompressionQuality($quality);

                $img->writeImage($tmp);
                $img->clear();
                $img->destroy();

                return $tmp;
            } catch (\Throwable $e) {
                Logger::log('error', 'proofs', 'Imagick proof generation failed: ' . $e->getMessage());
                @unlink($tmp);
                return null;
            }
        }

        // GD fallback.
        try {
            $src = null;

            // PERFORMANCE: Try to identify image type and use specific loader to avoid
            // loading the entire file string into memory (which file_get_contents does).
            $size = @getimagesize($localOriginal);
            if ($size !== false) {
                switch ($size[2]) {
                    case IMAGETYPE_JPEG:
                        $src = imagecreatefromjpeg($localOriginal);
                        break;
                    case IMAGETYPE_PNG:
                        $src = imagecreatefrompng($localOriginal);
                        break;
                    case IMAGETYPE_WEBP:
                        if (function_exists('imagecreatefromwebp')) {
                            $src = imagecreatefromwebp($localOriginal);
                        }
                        break;
                    case IMAGETYPE_GIF:
                        $src = imagecreatefromgif($localOriginal);
                        break;
                    case IMAGETYPE_BMP:
                        // PHP 7.2+
                        if (function_exists('imagecreatefrombmp')) {
                            $src = imagecreatefrombmp($localOriginal);
                        }
                        break;
                    case IMAGETYPE_WBMP:
                        if (function_exists('imagecreatefromwbmp')) {
                            $src = imagecreatefromwbmp($localOriginal);
                        }
                        break;
                    case IMAGETYPE_XBM:
                        if (function_exists('imagecreatefromxbm')) {
                            $src = imagecreatefromxbm($localOriginal);
                        }
                        break;
                    case 19: // IMAGETYPE_AVIF
                        if (function_exists('imagecreatefromavif')) {
                            $src = imagecreatefromavif($localOriginal);
                        }
                        break;
                }
            }

            if (!$src) {
                Logger::log('error', 'proofs', 'Unsupported image type or loader failure. Fallback disabled for performance safety.', [
                    'file' => $localOriginal,
                    'type' => $size[2] ?? 'unknown'
                ]);
                @unlink($tmp);
                return null;
            }

            $width  = imagesx($src);
            $height = imagesy($src);

            $scale = min($maxSize / max($width, 1), $maxSize / max($height, 1), 1);
            $newW  = (int) ($width * $scale);
            $newH  = (int) ($height * $scale);

            $dst = imagecreatetruecolor($newW, $newH);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);

            // Watermark text.
            $text = 'PROOF COPY - NOT FINAL QUALITY';
            $color = imagecolorallocatealpha($dst, 255, 255, 255, 80); // semi-transparent
            $fontSize = 3; // GD built-in font size
            $textWidth = imagefontwidth($fontSize) * strlen($text);
            $textHeight = imagefontheight($fontSize);

            $x = $newW - $textWidth - 10;
            $y = $newH - $textHeight - 10;

            imagestring($dst, $fontSize, $x, $y, $text, $color);

            imagejpeg($dst, $tmp, $quality);

            imagedestroy($src);
            imagedestroy($dst);

            return $tmp;
        } catch (\Throwable $e) {
            Logger::log('error', 'proofs', 'GD proof generation failed: ' . $e->getMessage());
            @unlink($tmp);
            return null;
        }
    }

    /**
     * Get configured max proof size with safe bounds.
     *
     * - Default: 1600
     * - Clamped: 800–2400
     *
     * @return int
     */
    protected static function getConfiguredMaxSize(): int
    {
        $default = 1600;
        $min = 800;
        $max = 2400;

        $value = (int) apply_filters('aperture_pro_proof_max_size', $default);

        if ($value < $min) {
            $value = $min;
        } elseif ($value > $max) {
            $value = $max;
        }

        return $value;
    }

    /**
     * Get configured JPEG quality with safe bounds.
     *
     * - Default: 65
     * - Clamped: 40–85
     *
     * @return int
     */
    protected static function getConfiguredQuality(): int
    {
        $default = 65;
        $min = 40;
        $max = 85;

        $value = (int) apply_filters('aperture_pro_proof_quality', $default);

        if ($value < $min) {
            $value = $min;
        } elseif ($value > $max) {
            $value = $max;
        }

        return $value;
    }

    /**
     * Generate a placeholder SVG image when image libraries are missing.
     *
     * @param string $path Context (filename) for logging or embedding (optional)
     * @return string|null Path to temp SVG file
     */
    protected static function generateSVGPlaceholder(string $path): ?string
    {
        $tmp = wp_tempnam('ap-proof-');
        if (!$tmp) {
            return null;
        }

        // Simple SVG with text
        $svg = <<<SVG
<svg width="800" height="600" xmlns="http://www.w3.org/2000/svg">
 <rect width="100%" height="100%" fill="#f0f0f0"/>
 <text x="50%" y="50%" font-family="Arial" font-size="24" fill="#666" text-anchor="middle" dy=".3em">Preview Unavailable</text>
 <text x="50%" y="55%" font-family="Arial" font-size="14" fill="#999" text-anchor="middle" dy="1.2em">(Processing Error)</text>
</svg>
SVG;

        if (file_put_contents($tmp, $svg) === false) {
            @unlink($tmp);
            return null;
        }

        return $tmp;
    }

    /**
     * Helper to resolve project/image IDs from an original path.
     *
     * @param string $path
     * @return array|null ['project_id' => int, 'image_id' => int] or null
     */
    protected static function resolveIdsFromPath(string $path): ?array
    {
        global $wpdb;
        $imagesTable = $wpdb->prefix . 'ap_images';
        $galleriesTable = $wpdb->prefix . 'ap_galleries';

        $query = "
            SELECT i.id as image_id, g.project_id
            FROM {$imagesTable} i
            JOIN {$galleriesTable} g ON i.gallery_id = g.id
            WHERE i.storage_key_original = %s
            LIMIT 1
        ";

        $row = $wpdb->get_row($wpdb->prepare($query, $path));

        if ($row) {
            return [
                'project_id' => (int) $row->project_id,
                'image_id'   => (int) $row->image_id,
            ];
        }

        return null;
    }

    /**
     * Helper to resolve IDs for a batch of paths.
     *
     * @param array $paths List of original paths
     * @return array Map of original_path => ['project_id' => int, 'image_id' => int]
     */
    protected static function resolveBatchIds(array $paths): array
    {
        if (empty($paths)) {
            return [];
        }

        global $wpdb;
        $imagesTable = $wpdb->prefix . 'ap_images';
        $galleriesTable = $wpdb->prefix . 'ap_galleries';

        $paths = array_unique($paths);
        $escapedPaths = [];
        foreach ($paths as $p) {
            $escapedPaths[] = $wpdb->prepare('%s', $p);
        }

        // Chunking handled by caller if massive, but let's be safe
        $chunks = array_chunk($escapedPaths, 200);
        $results = [];

        foreach ($chunks as $chunk) {
            $inClause = implode(',', $chunk);
            $query = "
                SELECT i.id as image_id, i.storage_key_original, g.project_id
                FROM {$imagesTable} i
                JOIN {$galleriesTable} g ON i.gallery_id = g.id
                WHERE i.storage_key_original IN ({$inClause})
            ";

            $rows = $wpdb->get_results($query);
            foreach ($rows as $row) {
                $results[$row->storage_key_original] = [
                    'project_id' => (int) $row->project_id,
                    'image_id'   => (int) $row->image_id,
                ];
            }
        }

        return $results;
    }
}
