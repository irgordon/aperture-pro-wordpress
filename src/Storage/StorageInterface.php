<?php

namespace AperturePro\Storage;

/**
 * StorageInterface
 *
 * Defines the contract for storage drivers used by Aperture Pro.
 * Implementations must be safe to call from both admin and front-end contexts.
 */
interface StorageInterface
{
    /**
     * Human-readable driver name (e.g. "Local", "S3").
     */
    public function getName(): string;

    /**
     * Store a file at the given path.
     *
     * @param string $source  Absolute path to local temp file or content.
     * @param string $target  Relative path/key in the storage backend.
     * @param array  $options Optional driver-specific options (ACL, content-type, metadata).
     *
     * @return string Public or signed URL to the stored file.
     *
     * @throws \RuntimeException If upload fails.
     */
    public function upload(string $source, string $target, array $options = []): string;

    /**
     * Store multiple files at once.
     *
     * @param array $files Array of ['source' => ..., 'target' => ..., 'options' => ...]
     * @return array Keyed by target, value is result array ['success' => bool, 'url' => string|null, 'error' => string|null]
     */
    public function uploadMany(array $files): array;

    /**
     * Delete a file from storage.
     *
     * @param string $target Relative path/key in the storage backend.
     *
     * @throws \RuntimeException If deletion fails.
     */
    public function delete(string $target): void;

    /**
     * Return a signed or direct URL for a stored file.
     *
     * @param string $target  Relative path/key in the storage backend.
     * @param array  $options Optional: ['expires' => seconds, 'signed' => bool]
     *
     * @return string URL to the file.
     */
    public function getUrl(string $target, array $options = []): string;

    /**
     * Check whether an object exists.
     *
     * @param string $target Relative path/key in the storage backend.
     * @return bool
     */
    public function exists(string $target): bool;

    /**
     * Check whether multiple objects exist.
     *
     * @param array $targets Array of relative paths/keys.
     * @return array Key-value pair where key is the target and value is boolean existence.
     */
    public function existsMany(array $targets): array;

    /**
     * Sign a single path.
     *
     * @param string $path
     * @return string|null
     */
    public function sign(string $path): ?string;

    /**
     * Sign multiple paths in a batch.
     *
     * @param array $paths
     * @return array
     */
    public function signMany(array $paths): array;

    /**
     * Return storage statistics for health + UI.
     *
     * Expected shape:
     * [
     *   'healthy'         => bool,
     *   'used_bytes'      => int|null,
     *   'available_bytes' => int|null,
     *   'used_human'      => string|null,
     *   'available_human' => string|null,
     * ]
     */
    public function getStats(): array;
}
