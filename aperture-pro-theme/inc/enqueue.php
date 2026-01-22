<?php
/**
 * Enqueue front-end and SPA assets for the Aperture Pro marketing theme.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_enqueue_scripts', function () {

    $version = wp_get_theme()->get('Version');

    /**
     * ---------------------------------------------------------
     * CSS: GLOBAL THEME STYLES
     * ---------------------------------------------------------
     */

    // Design tokens (shared across SPA + blocks)
    wp_enqueue_style(
        'aperture-theme-tokens',
        get_theme_file_uri('/assets/css/tokens.css'),
        [],
        $version
    );

    // Header Styles
    wp_enqueue_style(
        'aperture-theme-header',
        get_theme_file_uri('/assets/css/header.css'),
        ['aperture-theme-tokens'],
        $version
    );

    // Navigation Styles
    wp_enqueue_style(
        'aperture-theme-navigation',
        get_theme_file_uri('/assets/css/navigation.css'),
        ['aperture-theme-tokens'],
        $version
    );

    // Global frontend styles
    wp_enqueue_style(
        'aperture-theme-frontend',
        get_theme_file_uri('/assets/css/frontend.css'),
        ['aperture-theme-tokens'],
        $version
    );

    // Block overrides
    wp_enqueue_style(
        'aperture-theme-blocks',
        get_theme_file_uri('/assets/css/blocks.css'),
        ['aperture-theme-tokens'],
        $version
    );

    // Animation library
    wp_enqueue_style(
        'aperture-theme-animations',
        get_theme_file_uri('/assets/css/animations.css'),
        ['aperture-theme-tokens'],
        $version
    );

    /**
     * ---------------------------------------------------------
     * CSS: SHARED UI COMPONENTS (MODAL + TOAST)
     * ---------------------------------------------------------
     */

    wp_enqueue_style(
        'aperture-ui-modal',
        get_theme_file_uri('/assets/css/ap-modal.css'),
        [],
        $version
    );

    wp_enqueue_style(
        'aperture-ui-toast',
        get_theme_file_uri('/assets/css/ap-toast.css'),
        [],
        $version
    );

    wp_enqueue_style(
        'aperture-theme-footer',
        get_theme_file_uri('/assets/css/footer.css'),
        ['aperture-theme-tokens'],
        $version
    );

    /**
     * ---------------------------------------------------------
     * JS: SHARED UI COMPONENTS
     * ---------------------------------------------------------
     */

    wp_enqueue_script(
        'aperture-ui-modal-js',
        get_theme_file_uri('/assets/js/ap-modal.js'),
        [],
        $version,
        true
    );

    wp_enqueue_script(
        'aperture-ui-toast-js',
        get_theme_file_uri('/assets/js/ap-toast.js'),
        [],
        $version,
        true
    );

    /**
     * ---------------------------------------------------------
     * SPA ASSETS
     * ---------------------------------------------------------
     * The SPA enhances interactive islands but does not replace
     * server-rendered HTML. This keeps SEO perfect.
     */

    // SPA CSS
    wp_enqueue_style(
        'aperture-spa',
        get_theme_file_uri('/assets/css/spa.css'),
        ['aperture-theme-tokens'],
        $version
    );

    // SPA bootstrap loader
    wp_enqueue_script(
        'aperture-spa-bootstrap',
        get_theme_file_uri('/assets/js/spa/bootstrap.js'),
        [],
        $version,
        true
    );

    // SPA main entry
    wp_enqueue_script(
        'aperture-spa-index',
        get_theme_file_uri('/assets/js/spa/index.js'),
        ['aperture-spa-bootstrap'],
        $version,
        true
    );

    /**
     * ---------------------------------------------------------
     * LOCALIZED CONFIG FOR SPA
     * ---------------------------------------------------------
     * Exposes theme URLs, AJAX endpoint, nonce, and branding
     * settings (social URLs) to the SPA.
     */

    wp_localize_script('aperture-spa-index', 'ApertureSPAConfig', [
        'themeUrl' => get_theme_file_uri(),
        'ajaxUrl'  => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('aperture_spa'),
        'debug'    => defined('WP_DEBUG') && WP_DEBUG,

        // Social branding URLs from Admin Settings → General
        'social'   => [
            'facebook'  => get_option('aperture_brand_facebook', ''),
            'instagram' => get_option('aperture_brand_instagram', ''),
            'twitter'   => get_option('aperture_brand_twitter', ''),
        ],
    ]);
});

/**
 * Add type="module" to SPA scripts
 */
add_filter('script_loader_tag', function ($tag, $handle, $src) {
    if (in_array($handle, ['aperture-spa-index', 'aperture-spa-bootstrap'])) {
        return '<script type="module" src="' . esc_url($src) . '"></script>';
    }
    return $tag;
}, 10, 3);
