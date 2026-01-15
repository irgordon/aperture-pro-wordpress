<?php

namespace AperturePro\Proof;

use AperturePro\Storage\StorageFactory;
use AperturePro\Helpers\Logger;

/**
 * ProofService
 *
 * Generates low-resolution, watermarked proof images and stores them via StorageInterface.
 *
 * Usage:
 *  $url = ProofService::getProofUrlForImage($imageId, $options);
 *
 * Options:
 *  - 'expires' => seconds for signed URL TTL (default 300)
 *  - 'watermark_text' => override watermark text
 *
 * Notes:
 *  - Requires write access to storage driver.
 *  - Uses Imagick if available, otherwise falls back to GD.
 *  - Stores generated proof key in ap_images.proof_key column (if present).
 */
class ProofService
{
    const PROOF_PREFIX = 'proofs/';
    const DEFAULT_MAX_WIDTH = 1200;
    const DEFAULT_QUALITY = 60; // JPEG quality for low-res proofs

    private static $_hasProofKeyColumn = null;

    /**
     * Ensure a proof derivative exists for the given image DB row and return a signed URL.
     *
     * @param array|object $imageRow DB row or associative array with at least id, storage_key_original
     * @param array $options
     * @return string|null signed URL or null on failure
     */
    public static function getProofUrlForImage($imageRow, array $options = []): ?string
    {
        if (is_object($imageRow)) {
            $image = (array) $imageRow;
        } else {
            $image = $imageRow;
        }

        $imageId = (int) ($image['id'] ?? 0);
        $origKey = $image['storage_key_original'] ?? null;

        if ($imageId <= 0 || empty($origKey)) {
            return null;
        }

        global $wpdb;
        $imagesTable = $wpdb->prefix . 'ap_images';

        // Check if proof_key column exists and has a value
        $proofKey = null;
        if (property_exists((object)$image, 'proof_key') || isset($image['proof_key'])) {
            $proofKey = $image['proof_key'] ?? null;
        } else {
            // Try to read from DB
            $row = $wpdb->get_row($wpdb->prepare("SELECT proof_key FROM {$imagesTable} WHERE id = %d LIMIT 1", $imageId), ARRAY_A);
            if ($row && !empty($row['proof_key'])) {
                $proofKey = $row['proof_key'];
            }
        }

        $storage = StorageFactory::make();

        if (!empty($proofKey)) {
            // Return signed URL for existing proof
            try {
                return $storage->getUrl($proofKey, ['signed' => true, 'expires' => $options['expires'] ?? 300]);
            } catch (\Throwable $e) {
                Logger::log('warning', 'proof', 'Failed to get URL for existing proof key', ['image_id' => $imageId, 'proof_key' => $proofKey, 'error' => $e->getMessage()]);
                // Fall through to attempt regeneration
            }
        }

        // Generate proof derivative
        try {
            // 1) Download original to temp file (use storage->getUrl and fopen)
            $origUrl = $storage->getUrl($origKey, ['signed' => true, 'expires' => 300]);
            if (empty($origUrl)) {
                Logger::log('warning', 'proof', 'Could not obtain original URL for image', ['image_id' => $imageId, 'origKey' => $origKey]);
                return null;
            }

            $tmpIn = tmpfile();
            if ($tmpIn === false) {
                Logger::log('error', 'proof', 'Failed to create tmpfile for download', ['image_id' => $imageId, 'notify_admin' => true]);
                return null;
            }

            // Attempt to fetch via fopen or curl into tmpfile
            $fetched = false;
            $context = stream_context_create(['http' => ['timeout' => 30]]);
            $handle = @fopen($origUrl, 'rb', false, $context);
            if ($handle !== false) {
                while (!feof($handle)) {
                    $chunk = fread($handle, 1024 * 1024);
                    if ($chunk === false) {
                        break;
                    }
                    fwrite($tmpIn, $chunk);
                }
                fclose($handle);
                $fetched = true;
            } elseif (function_exists('curl_init')) {
                // curl fallback
                $tmpMeta = stream_get_meta_data($tmpIn);
                $tmpPath = $tmpMeta['uri'];
                $ch = curl_init($origUrl);
                $out = fopen($tmpPath, 'wb');
                curl_setopt($ch, CURLOPT_FILE, $out);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                curl_exec($ch);
                $err = curl_error($ch);
                curl_close($ch);
                fclose($out);
                if (empty($err)) {
                    $tmpIn = fopen($tmpPath, 'rb');
                    $fetched = true;
                }
            }

            if (!$fetched) {
                Logger::log('warning', 'proof', 'Failed to fetch original image for proof generation', ['image_id' => $imageId, 'origUrl' => $origUrl]);
                fclose($tmpIn);
                return null;
            }

            // Rewind tmp file
            rewind($tmpIn);
            $tmpMeta = stream_get_meta_data($tmpIn);
            $tmpPath = $tmpMeta['uri'];

            // 2) Create derivative (low-res + watermark)
            $proofTmp = self::createWatermarkedProof($tmpPath, $imageId, $options);

            // Close input tmp
            @fclose($tmpIn);

            if ($proofTmp === null) {
                Logger::log('error', 'proof', 'Proof creation failed', ['image_id' => $imageId]);
                return null;
            }

            // 3) Upload derivative via storage->upload
            $remoteKey = self::PROOF_PREFIX . 'image_' . $imageId . '_proof.jpg';
            $uploadResult = $storage->upload($proofTmp, $remoteKey, ['signed' => true]);

            // Remove local proof tmp
            @unlink($proofTmp);

            if (empty($uploadResult['success'])) {
                Logger::log('error', 'proof', 'Failed to upload proof derivative', ['image_id' => $imageId, 'remoteKey' => $remoteKey, 'meta' => $uploadResult, 'notify_admin' => true]);
                return null;
            }

            $proofKey = $uploadResult['key'] ?? $remoteKey;

            // 4) Persist proof_key in DB if column exists
            if (self::hasProofKeyColumn()) {
                $wpdb->update($imagesTable, ['proof_key' => $proofKey, 'updated_at' => current_time('mysql')], ['id' => $imageId], ['%s', '%s'], ['%d']);
            }

            // 5) Return signed URL
            return $storage->getUrl($proofKey, ['signed' => true, 'expires' => $options['expires'] ?? 300]);
        } catch (\Throwable $e) {
            Logger::log('error', 'proof', 'Exception generating proof: ' . $e->getMessage(), ['image_id' => $imageId, 'notify_admin' => true]);
            return null;
        }
    }

    /**
     * Check if the ap_images table has a proof_key column, using a static cache.
     *
     * @return boolean
     */
    private static function hasProofKeyColumn(): bool
    {
        if (self::$_hasProofKeyColumn !== null) {
            return self::$_hasProofKeyColumn;
        }

        global $wpdb;
        $imagesTable = $wpdb->prefix . 'ap_images';

        $hasColumn = $wpdb->get_var("SHOW COLUMNS FROM `{$imagesTable}` LIKE 'proof_key'");

        return self::$_hasProofKeyColumn = !empty($hasColumn);
    }

    /**
     * Create a watermarked low-res JPEG proof from $inputPath and return path to temp file.
     *
     * @param string $inputPath
     * @param int $imageId
     * @param array $options
     * @return string|null path to temp proof file or null on failure
     */
    protected static function createWatermarkedProof(string $inputPath, int $imageId, array $options = []): ?string
    {
        $maxWidth = (int) ($options['max_width'] ?? self::DEFAULT_MAX_WIDTH);
        $quality = (int) ($options['quality'] ?? self::DEFAULT_QUALITY);
        $watermarkText = $options['watermark_text'] ?? 'PROOF COPY — NOT FINAL';

        // Prefer Imagick if available
        if (class_exists('\Imagick')) {
            try {
                $img = new \Imagick($inputPath);
                $img->setImageColorspace(\Imagick::COLORSPACE_RGB);
                $img->stripImage();

                // Resize preserving aspect ratio
                $width = $img->getImageWidth();
                if ($width > $maxWidth) {
                    $img->resizeImage($maxWidth, 0, \Imagick::FILTER_LANCZOS, 1);
                }

                // Create watermark overlay
                $draw = new \ImagickDraw();
                $pixel = new \ImagickPixel('rgba(255,255,255,0.25)');
                $draw->setFillColor($pixel);
                $draw->setFontSize(max(18, intval($img->getImageWidth() / 20)));
                $draw->setGravity(\Imagick::GRAVITY_CENTER);

                // Create a transparent canvas for watermark
                $watermark = new \Imagick();
                $watermark->newImage($img->getImageWidth(), $img->getImageHeight(), new \ImagickPixel('transparent'));
                $watermark->setImageFormat('png');

                // Annotate watermark text multiple times diagonally
                $draw->setFillColor(new \ImagickPixel('rgba(255,255,255,0.25)'));
                $watermark->annotateImage($draw, 0, 0, 45, $watermarkText);

                // Composite watermark over image
                $img->compositeImage($watermark, \Imagick::COMPOSITE_OVER, 0, 0);

                // Add small footer text with image id
                $footer = new \ImagickDraw();
                $footer->setFillColor(new \ImagickPixel('rgba(255,255,255,0.6)'));
                $footer->setFontSize(12);
                $footer->setGravity(\Imagick::GRAVITY_SOUTHWEST);
                $img->annotateImage($footer, 10, 10, 0, "Proof ID: {$imageId}");

                $img->setImageCompression(\Imagick::COMPRESSION_JPEG);
                $img->setImageCompressionQuality($quality);
                $img->setImageFormat('jpeg');

                $tmp = tempnam(sys_get_temp_dir(), 'ap_proof_') . '.jpg';
                $img->writeImage($tmp);
                $img->clear();
                $img->destroy();

                return $tmp;
            } catch (\Throwable $e) {
                Logger::log('warning', 'proof', 'Imagick proof generation failed, falling back to GD: ' . $e->getMessage());
                // Fall through to GD
            }
        }

        // GD fallback
        try {
            $info = getimagesize($inputPath);
            if ($info === false) {
                return null;
            }

            $mime = $info['mime'];
            switch ($mime) {
                case 'image/jpeg':
                    $src = imagecreatefromjpeg($inputPath);
                    break;
                case 'image/png':
                    $src = imagecreatefrompng($inputPath);
                    break;
                case 'image/webp':
                    if (function_exists('imagecreatefromwebp')) {
                        $src = imagecreatefromwebp($inputPath);
                    } else {
                        return null;
                    }
                    break;
                default:
                    return null;
            }

            $width = imagesx($src);
            $height = imagesy($src);
            $ratio = $width / $height;
            if ($width > $maxWidth) {
                $newWidth = $maxWidth;
                $newHeight = intval($newWidth / $ratio);
            } else {
                $newWidth = $width;
                $newHeight = $height;
            }

            $dst = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

            // Watermark text
            $text = $watermarkText;
            $fontSize = max(12, intval($newWidth / 20));
            $fontFile = __DIR__ . '/../../assets/fonts/arial.ttf';
            if (!file_exists($fontFile)) {
                // Use built-in font fallback
                $color = imagecolorallocatealpha($dst, 255, 255, 255, 80);
                imagestring($dst, 5, intval($newWidth / 10), intval($newHeight / 2), $text, $color);
            } else {
                $color = imagecolorallocatealpha($dst, 255, 255, 255, 80);
                // Diagonal repeated watermark (simple)
                $angle = -30;
                $bbox = imagettfbbox($fontSize, $angle, $fontFile, $text);
                $textWidth = abs($bbox[4] - $bbox[0]);
                $textHeight = abs($bbox[5] - $bbox[1]);

                for ($y = -$textHeight; $y < $newHeight + $textHeight; $y += $textHeight * 4) {
                    for ($x = -$textWidth; $x < $newWidth + $textWidth; $x += $textWidth * 4) {
                        imagettftext($dst, $fontSize, $angle, $x, $y + $textHeight, $color, $fontFile, $text);
                    }
                }

                // Footer
                $footerText = "Proof ID: {$imageId}";
                imagettftext($dst, 10, 0, 8, $newHeight - 8, imagecolorallocatealpha($dst, 255, 255, 255, 60), $fontFile, $footerText);
            }

            $tmp = tempnam(sys_get_temp_dir(), 'ap_proof_') . '.jpg';
            imagejpeg($dst, $tmp, $quality);
            imagedestroy($dst);
            imagedestroy($src);

            return $tmp;
        } catch (\Throwable $e) {
            Logger::log('error', 'proof', 'GD proof generation failed: ' . $e->getMessage(), ['notify_admin' => true]);
            return null;
        }
    }
}
