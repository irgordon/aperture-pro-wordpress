<?php
/**
 * SPA Helper Functions
 */

if (!defined('ABSPATH')) {
    exit;
}

// Example AJAX handler for SPA components
add_action('wp_ajax_aperture_spa_data', function () {
    check_ajax_referer('aperture_spa', 'nonce');

    // Return some data
    wp_send_json_success(['message' => 'Hello from SPA backend']);
});

add_action('wp_ajax_nopriv_aperture_spa_data', function () {
    check_ajax_referer('aperture_spa', 'nonce');
    wp_send_json_success(['message' => 'Hello from SPA backend (guest)']);
});
