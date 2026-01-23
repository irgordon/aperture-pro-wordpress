<?php

define('ABSPATH', __DIR__ . '/../');
require_once __DIR__ . '/../inc/autoloader.php';

use AperturePro\Environment;

echo "Testing Environment class...\n";

$env = new Environment('/path/to/plugin', 'https://example.com/plugin', '1.0.0');

if ($env->getPath() !== '/path/to/plugin') {
    die("FAILED: getPath() returned " . $env->getPath() . "\n");
}

if ($env->getUrl() !== 'https://example.com/plugin') {
    die("FAILED: getUrl() returned " . $env->getUrl() . "\n");
}

if ($env->getVersion() !== '1.0.0') {
    die("FAILED: getVersion() returned " . $env->getVersion() . "\n");
}

echo "Environment tests passed.\n";
