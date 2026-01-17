<?php
/**
 * Plugin Index
 *
 * This file prevents direct access to the plugin directory
 * and safely routes execution to the main plugin bootstrap.
 *
 * @package Aperture_Pro
 */

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Optional: If someone loads this file directly in a browser,
// show a minimal, safe message instead of a blank screen.
if ( php_sapi_name() !== 'cli' ) {
    header( 'Content-Type: text/plain; charset=utf-8' );
    echo "Aperture Pro Plugin\n";
    echo "This file is not meant to be accessed directly.";
}

return;
