<?php
/**
 * Aperture Pro Marketing Theme
 * Header loader for block theme.
 *
 * This file simply loads the header template part:
 *   /templates/parts/header.html
 */

if (!defined('ABSPATH')) {
    exit;
}

echo wp_kses_post( wp_template_part( 'header' ) );
