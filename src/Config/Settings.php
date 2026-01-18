<?php
declare(strict_types=1);

namespace AperturePro\Config;

class Settings
{
    public function get(string $key, $default = null)
    {
        return Config::get($key, $default);
    }
}
