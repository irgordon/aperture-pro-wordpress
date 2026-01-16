<?php
/**
 * Aperture Pro Marketing Theme
 * Fallback index.php required by WordPress.
 *
 * This theme uses block templates exclusively.
 * All rendering is handled by:
 *   /templates/*.html
 *   /templates/parts/*.html
 *
 * This file intentionally contains no template logic.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Load the block template loader.
echo wp_kses_post( wp_template_loader() );
