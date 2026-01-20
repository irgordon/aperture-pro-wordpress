<?php

namespace AperturePro\Proof;

use AperturePro\Storage\StorageFactory;
use AperturePro\Storage\StorageInterface;
use AperturePro\Helpers\Logger;
use AperturePro\Proof\ProofCache;
use AperturePro\Proof\ProofQueue;

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

        // 3. Batch check existence
        $pathsToCheck = array_unique(array_values($proofPaths));
        $existenceMap = $storage->existsMany($pathsToCheck);

        // 4. Identify Existing vs Missing
        $toEnqueue = [];
        $existingPaths = [];

        foreach ($proofPaths as $key => $proofPath) {
            $exists = $existenceMap[$proofPath] ?? false;

            if (!$exists) {
                // OFFLOAD: Do not generate synchronously. Queue it.
                $originalPath = $originalPaths[$key];

                $toEnqueue[] = [
                    'original_path' => $originalPath,
                    'proof_path'    => $proofPath,
                ];

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
            ProofQueue::enqueueBatch($toEnqueue);
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
            ProofQueue::enqueue($originalPath, $proofPath);
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
        // Ideally, this points to a real asset in the plugin/theme.
        // For now, we return a generic placeholder or a data URI.
        return apply_filters('aperture_pro_proof_placeholder_url', '/wp-content/plugins/aperture-pro/assets/images/processing-proof.jpg');
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

        // 3. Process & Upload
        foreach ($items as $key => $item) {
            $proofPath = $item['proof_path'];
            $tempOriginal = $tempFiles[$key] ?? null;

            if (!$tempOriginal || !file_exists($tempOriginal)) {
                Logger::log('error', 'proofs', 'Missing original for batch item', ['path' => $item['original_path']]);
                $results[$proofPath] = false;
                continue;
            }

            // Process
            $success = self::processAndUpload($tempOriginal, $proofPath, $storage);
            $results[$proofPath] = $success;

            // Cleanup temp original
            @unlink($tempOriginal);
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
                Logger::log('error', 'proofs', 'Failed to get URL for batch item', ['path' => $remotePath]);
                continue;
            }
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
            // Fallback to sequential
            $results = [];
            foreach ($urls as $key => $url) {
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
                usleep(100);
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
            // As a fallback, just copy the file (not ideal, but avoids hard failure).
            $tmp = wp_tempnam('ap-proof-');
            if (!$tmp) {
                return null;
            }
            copy($localOriginal, $tmp);
            return $tmp;
        }

        $tmp = wp_tempnam('ap-proof-');
        if (!$tmp) {
            return null;
        }

        // Prefer Imagick if available.
        if (extension_loaded('imagick')) {
            try {
                $img = new \Imagick($localOriginal);
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
            $srcData = file_get_contents($localOriginal);
            if ($srcData === false) {
                @unlink($tmp);
                return null;
            }

            $src = imagecreatefromstring($srcData);
            if (!$src) {
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
}
