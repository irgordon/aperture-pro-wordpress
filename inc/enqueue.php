<?php
/**
 * Enqueue front-end and SPA assets for the Aperture Pro marketing theme.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_enqueue_scripts', function () {

    // Base theme stylesheet (global)
    wp_enqueue_style(
        'aperture-theme-frontend',
        get_theme_file_uri('/assets/css/frontend.css'),
        [],
        wp_get_theme()->get('Version')
    );

    // Design tokens (shared across SPA + blocks)
    wp_enqueue_style(
        'aperture-theme-tokens',
        get_theme_file_uri('/assets/css/tokens.css'),
        [],
        wp_get_theme()->get('Version')
    );

    // Block overrides (optional)
    wp_enqueue_style(
        'aperture-theme-blocks',
        get_theme_file_uri('/assets/css/blocks.css'),
        ['aperture-theme-tokens'],
        wp_get_theme()->get('Version')
    );

    // Shared UI components (Modal + Toast)
    wp_enqueue_style(
        'aperture-ui-modal',
        get_theme_file_uri('/assets/css/ap-modal.css'),
        [],
        wp_get_theme()->get('Version')
    );

    wp_enqueue_style(
        'aperture-ui-toast',
        get_theme_file_uri('/assets/css/ap-toast.css'),
        [],
        wp_get_theme()->get('Version')
    );

    wp_enqueue_script(
        'aperture-ui-modal-js',
        get_theme_file_uri('/assets/js/ap-modal.js'),
        [],
        wp_get_theme()->get('Version'),
        true
    );

    wp_enqueue_script(
        'aperture-ui-toast-js',
        get_theme_file_uri('/assets/js/ap-toast.js'),
        [],
        wp_get_theme()->get('Version'),
        true
    );

    /**
     * SPA assets — loaded only on the front-end (not admin, not login)
     * The SPA enhances interactive islands but does not replace the page.
     */
    wp_enqueue_style(
        'aperture-spa',
        get_theme_file_uri('/assets/css/spa.css'),
        ['aperture-theme-tokens'],
        wp_get_theme()->get('Version')
    );

    wp_enqueue_script(
        'aperture-spa-bootstrap',
        get_theme_file_uri('/assets/spa/bootstrap.js'),
        [],
        wp_get_theme()->get('Version'),
        true
    );

    wp_enqueue_script(
        'aperture-spa-index',
        get_theme_file_uri('/assets/spa/index.js'),
        ['aperture-spa-bootstrap'],
        wp_get_theme()->get('Version'),
        true
    );

    // Pass configuration to the SPA (optional)
    wp_localize_script('aperture-spa-index', 'ApertureSPAConfig', [
        'themeUrl' => get_theme_file_uri(),
        'ajaxUrl'  => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('aperture_spa'),
    ]);
});
