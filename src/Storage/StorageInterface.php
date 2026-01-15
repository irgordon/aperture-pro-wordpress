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
     * Upload a file to storage.
     *
     * @param string $localPath Absolute path to the local file to upload.
     * @param string $remoteKey Desired remote key / path (driver-specific).
     * @param array  $options   Optional driver-specific options (ACL, content-type, metadata).
     * @return array            Returns an associative array with at least:
     *                          - 'success' => bool
     *                          - 'key'     => string remote key
     *                          - 'url'     => string public or signed URL (if available)
     *                          - 'meta'    => array driver metadata
     */
    public function upload(string $localPath, string $remoteKey, array $options = []): array;

    /**
     * Get a public or signed URL for a stored object.
     *
     * @param string $remoteKey
     * @param array  $options   Optional: ['expires' => seconds, 'signed' => bool]
     * @return string|null      URL or null if not available
     */
    public function getUrl(string $remoteKey, array $options = []): ?string;

    /**
     * Delete an object from storage.
     *
     * @param string $remoteKey
     * @return bool
     */
    public function delete(string $remoteKey): bool;

    /**
     * Check whether an object exists.
     *
     * @param string $remoteKey
     * @return bool
     */
    public function exists(string $remoteKey): bool;

    /**
     * List objects under a prefix/path.
     *
     * @param string $prefix
     * @param array  $options  Optional pagination or filtering options
     * @return array           Array of object metadata arrays
     */
    public function list(string $prefix = '', array $options = []): array;
}
