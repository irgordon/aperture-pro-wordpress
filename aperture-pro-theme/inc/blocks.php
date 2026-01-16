<?php
/**
 * Block Pattern Registration
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('init', function () {
    register_block_pattern_category(
        'pricing',
        ['label' => __('Pricing', 'aperture-pro-theme')]
    );
    register_block_pattern_category(
        'testimonials',
        ['label' => __('Testimonials', 'aperture-pro-theme')]
    );
});
