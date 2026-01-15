<?php

namespace AperturePro\Config;

class Config {
    const OPTION_KEY = 'aperture_pro_config';

    public static function get($key, $default = null) {
        $config = get_option(self::OPTION_KEY, Defaults::all());
        return $config[$key] ?? $default;
    }

    public static function set($key, $value) {
        $config = get_option(self::OPTION_KEY, Defaults::all());
        $config[$key] = $value;
        update_option(self::OPTION_KEY, $config);
    }

    public static function all() {
        return get_option(self::OPTION_KEY, Defaults::all());
    }
}
