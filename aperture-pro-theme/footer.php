<?php
/**
 * Aperture Pro Marketing Theme
 * Footer loader for block theme.
 *
 * This file simply loads the footer template part:
 *   /templates/parts/footer.html
 */

if (!defined('ABSPATH')) {
    exit;
}

echo wp_kses_post( wp_template_part( 'footer' ) );
