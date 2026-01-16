<?php
/**
 * Simple PSR-4 Autoloader for Aperture Pro.
 * Handles loading classes from src/ when Composer is not available.
 */

if (!defined('ABSPATH')) {
    exit;
}

spl_autoload_register(function ($class) {
    // Project-specific namespace prefix
    $prefix = 'AperturePro\\';

    // Base directory for the namespace prefix
    // Ensure APERTURE_PRO_DIR is defined, or fallback to relative path
    $base_dir = defined('APERTURE_PRO_DIR')
        ? APERTURE_PRO_DIR . 'src/'
        : __DIR__ . '/../src/';

    // Does the class use the namespace prefix?
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        // no, move to the next registered autoloader
        return;
    }

    // Get the relative class name
    $relative_class = substr($class, $len);

    // Replace the namespace prefix with the base directory, replace namespace
    // separators with directory separators in the relative class name, append
    // with .php
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    // If the file exists, require it
    if (file_exists($file)) {
        require $file;
    }
});
