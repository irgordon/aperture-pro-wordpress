<?php

namespace AperturePro\Config;

class Defaults {
    public static function all() {
        return [
            'storage.driver' => 'local',
            'storage.threshold_mb' => 25,
            'tokens.magic_link_lifetime' => 3600,
            'tokens.download_lifetime' => 7200,
            'logging.enabled' => true,
        ];
    }
}
