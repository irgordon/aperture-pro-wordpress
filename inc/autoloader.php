<?php
/**
 * Simple PSR-4 Autoloader for Aperture Pro.
 *
 * Used as a fallback when Composer autoloading is unavailable
 * (e.g. ZIP installs or constrained hosting environments).
 */

if (!defined('ABSPATH')) {
    exit;
}

spl_autoload_register(function ($class) {
    // Project-specific namespace prefix
    $prefix = 'AperturePro\\';

    // Base directory for the namespace prefix
    // Prefer APERTURE_PRO_DIR if defined, otherwise fall back to relative path
    $baseDir = defined('APERTURE_PRO_DIR')
        ? APERTURE_PRO_DIR . 'src/'
        : __DIR__ . '/../src/';

    // Does the class use the namespace prefix?
    $prefixLength = strlen($prefix);
    if (strncmp($prefix, $class, $prefixLength) !== 0) {
        // Not an Aperture Pro class; allow other autoloaders to handle it
        return;
    }

    // Get the relative class name
    $relativeClass = substr($class, $prefixLength);

    // Replace namespace separators with directory separators, append .php
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    // Load the file if it exists
    if (file_exists($file)) {
        require $file;
    }
});
