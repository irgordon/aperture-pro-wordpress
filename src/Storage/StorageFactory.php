<?php

namespace AperturePro\Storage;

use AperturePro\Config\Config;

class StorageFactory {
    public static function make(): StorageInterface {
        $driver = Config::get('storage.driver');

        return match ($driver) {
            'local'     => new LocalStorage(),
            'imagekit'  => new ImageKitStorage(),
            'cloudinary'=> new CloudinaryStorage(),
            default     => new LocalStorage(),
        };
    }
}
