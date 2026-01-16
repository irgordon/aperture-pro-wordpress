<?php
/**
 * Basic SEO Functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_head', function () {
    if (is_front_page()) {
        echo '<meta name="description" content="Aperture Pro - Client Proofing for Photographers.">';
    }
});
