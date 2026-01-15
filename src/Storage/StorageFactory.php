<?php

namespace AperturePro\Storage;

use AperturePro\Config\Config;
use AperturePro\Helpers\Logger;

/**
 * StorageFactory
 *
 * Returns an instance of StorageInterface based on plugin configuration.
 */
class StorageFactory
{
    /**
     * Make a storage driver instance based on configuration.
     *
     * @return StorageInterface
     * @throws \RuntimeException if driver cannot be instantiated
     */
    public static function make(): StorageInterface
    {
        $config = Config::all();
        $driver = $config['storage']['driver'] ?? 'local';

        switch (strtolower($driver)) {
            case 'local':
                return new LocalStorage($config['storage'] ?? []);
            case 'imagekit':
                return new ImageKitStorage($config['storage'] ?? []);
            case 'cloudinary':
                return new CloudinaryStorage($config['storage'] ?? []);
            default:
                Logger::log('error', 'storage_factory', 'Unknown storage driver requested', ['driver' => $driver]);
                throw new \RuntimeException("Unknown storage driver: {$driver}");
        }
    }
}
