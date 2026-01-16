<?php
/**
 * Aperture Pro Marketing Theme
 * Core bootstrap file for theme initialization.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ---------------------------------------------------------
 * 1. THEME SETUP
 * ---------------------------------------------------------
 * Registers theme supports, menus, and editor features.
 */
add_action('after_setup_theme', function () {

    // Enable block theme features
    add_theme_support('wp-block-styles');
    add_theme_support('editor-styles');
    add_theme_support('responsive-embeds');
    add_theme_support('automatic-feed-links');
    add_theme_support('title-tag');

    // Load editor styles (theme.json + tokens.css)
    add_editor_style([
        'assets/css/tokens.css',
        'assets/css/blocks.css'
    ]);
});


/**
 * ---------------------------------------------------------
 * 2. LOAD DEPENDENCIES
 * ---------------------------------------------------------
 * Modular includes for enqueue, blocks, SPA, SEO, etc.
 */
$ap_theme_includes = [
    'inc/enqueue.php',   // Frontend + SPA asset loading
    'inc/blocks.php',    // Block pattern registration
    'inc/spa.php',       // SPA hydration mapping
    'inc/seo.php',       // SEO plugin compatibility
];

foreach ($ap_theme_includes as $file) {
    $path = get_theme_file_path($file);
    if (file_exists($path)) {
        require_once $path;
    } else {
        error_log("Aperture Pro Theme: Missing include file: {$file}");
    }
}


/**
 * ---------------------------------------------------------
 * 3. REGISTER NAVIGATION MENUS
 * ---------------------------------------------------------
 * Useful for header/footer block template parts.
 */
add_action('init', function () {
    register_nav_menus([
        'primary'   => __('Primary Navigation', 'aperture-pro'),
        'footer'    => __('Footer Navigation', 'aperture-pro'),
    ]);
});


/**
 * ---------------------------------------------------------
 * 4. DISABLE EMOJI BLOAT (OPTIONAL)
 * ---------------------------------------------------------
 * Small performance win for marketing pages.
 */
add_action('init', function () {
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('wp_print_styles', 'print_emoji_styles');
});


/**
 * ---------------------------------------------------------
 * 5. ALLOW SVG UPLOADS (OPTIONAL)
 * ---------------------------------------------------------
 * Useful for logos, icons, and vector assets.
 */
add_filter('upload_mimes', function ($mimes) {
    $mimes['svg'] = 'image/svg+xml';
    return $mimes;
});
