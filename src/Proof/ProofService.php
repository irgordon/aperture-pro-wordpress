<?php

namespace AperturePro\Proof;

use AperturePro\Storage\StorageFactory;
use AperturePro\Storage\StorageInterface;
use AperturePro\Helpers\Logger;

/**
 * ProofService
 *
 * Responsible for generating and serving proof images:
 *  - Watermarked, low-resolution variants of originals
 *  - URLs clearly labeled as "proof copies"
 *
 * PERFORMANCE CHANGE:
 *  - getProofUrlForImage() now accepts an optional StorageInterface instance.
 *    If provided, we reuse it instead of calling StorageFactory::create()
 *    internally, avoiding repeated config decryption in tight loops.
 */
class ProofService
{
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

        // Ensure proof exists; generate if missing.
        if (!$storage->exists($proofPath)) {
            $generated = self::generateProofVariant($originalPath, $proofPath, $storage);
            if (!$generated) {
                throw new \RuntimeException('Failed to generate proof variant.');
            }
        }

        // Use signed URL or public URL depending on your policy.
        // For proof copies, short-lived signed URLs are recommended.
        $url = $storage->getSignedUrl($proofPath, 3600);

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
        // Simple example: prefix with "proofs/" and add "_proof" before extension.
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
     * Generate a watermarked, low-resolution proof variant.
     *
     * SECURITY / UX:
     *  - Proofs are intentionally lower resolution and watermarked to deter
     *    unauthorized use.
     *  - Overlay text clearly indicates "PROOF COPY - NOT FINAL QUALITY".
     *
     * @param string           $originalPath
     * @param string           $proofPath
     * @param StorageInterface $storage
     *
     * @return bool
     */
    protected static function generateProofVariant(string $originalPath, string $proofPath, StorageInterface $storage): bool
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

            $tmpProof = self::createWatermarkedLowRes($tmpOriginal);

            if (!$tmpProof || !is_readable($tmpProof)) {
                Logger::log('error', 'proofs', 'Failed to create watermarked proof', [
                    'originalPath' => $originalPath,
                ]);
                @unlink($tmpOriginal);
                return false;
            }

            // Upload proof back to storage.
            $ok = $storage->putFile($tmpProof, $proofPath, [
                'content_type' => 'image/jpeg',
                'acl'          => 'private', // proofs should not be world-readable by default
            ]);

            @unlink($tmpOriginal);
            @unlink($tmpProof);

            return $ok;
        } catch (\Throwable $e) {
            Logger::log('error', 'proofs', 'Exception during proof generation: ' . $e->getMessage(), [
                'originalPath' => $originalPath,
            ]);
            return false;
        }
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
        // For simplicity, assume StorageInterface has getSignedUrl() and we use file_get_contents().
        // In a hardened implementation, you might use driver-specific SDK calls instead.
        $url = $storage->getSignedUrl($remotePath, 600);

        $contents = @file_get_contents($url);
        if ($contents === false) {
            return null;
        }

        $tmp = wp_tempnam('ap-proof-');
        if (!$tmp) {
            return null;
        }

        file_put_contents($tmp, $contents);
        return $tmp;
    }

    /**
     * Create a watermarked, low-resolution proof image from a local original.
     *
     * - Resizes to a max width/height (e.g., 1600px).
     * - Adds a semi-transparent "PROOF COPY" text overlay.
     * - Intentionally reduces quality to discourage reuse.
     *
     * @param string $localOriginal
     * @return string|null Path to the generated proof file
     */
    protected static function createWatermarkedLowRes(string $localOriginal): ?string
    {
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

                // Resize to max 1600px on the longest side.
                $img->thumbnailImage(1600, 1600, true);

                // Add watermark text.
                $draw = new \ImagickDraw();
                $draw->setFillColor('rgba(255,255,255,0.35)');
                $draw->setFontSize(36);
                $draw->setGravity(\Imagick::GRAVITY_SOUTHEAST);
                $img->annotateImage($draw, 10, 10, 0, 'PROOF COPY - NOT FINAL QUALITY');

                // Lower quality intentionally.
                $img->setImageCompressionQuality(65);

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
            $src = imagecreatefromstring(file_get_contents($localOriginal));
            if (!$src) {
                @unlink($tmp);
                return null;
            }

            $width  = imagesx($src);
            $height = imagesy($src);
            $max    = 1600;

            $scale = min($max / max($width, 1), $max / max($height, 1), 1);
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

            imagejpeg($dst, $tmp, 65);

            imagedestroy($src);
            imagedestroy($dst);

            return $tmp;
        } catch (\Throwable $e) {
            Logger::log('error', 'proofs', 'GD proof generation failed: ' . $e->getMessage());
            @unlink($tmp);
            return null;
        }
    }
}
