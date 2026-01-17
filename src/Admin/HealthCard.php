<?php

namespace AperturePro\Admin;

use AperturePro\Health\HealthService;
use AperturePro\Health\HealthCardRegistry;

class HealthCard
{
    /** Slug for the health page */
    const PAGE_SLUG = 'aperture-pro-health';

    /**
     * Initialize hooks.
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_filter('script_loader_tag', [self::class, 'add_module_type'], 10, 3);
    }

    /**
     * Register the Health Check submenu.
     */
    public static function register_menu(): void
    {
        add_submenu_page(
            'aperture-pro',
            'System Health',
            'Health Check',
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render_page']
        );
    }

    /**
     * Render the health check page.
     */
    public static function render_page(): void
    {
        // Fetch health status synchronously for the initial render
        $healthData = HealthService::check();

        $registry = new HealthCardRegistry();
        $cards = $registry->getCards();

        include APERTURE_PRO_DIR . 'templates/admin/health-card.php';
    }

    /**
     * Enqueue assets if needed.
     */
    public static function enqueue_assets($hook)
    {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'aperture-pro_page_' . self::PAGE_SLUG) {
            return;
        }

        wp_enqueue_style('ap-admin-ui-css', APERTURE_PRO_URL . 'assets/css/admin-ui.css', [], '1.0.0');

        // We need AdminUI JS for the ApertureAdmin global (used by metrics hooks)
        wp_enqueue_script('ap-admin-ui-js', APERTURE_PRO_URL . 'assets/js/admin-ui.js', ['jquery'], '1.0.0', true);

        $registry = new HealthCardRegistry();
        $cards = $registry->getCards();

        wp_localize_script('ap-admin-ui-js', 'ApertureAdmin', [
            'ajaxUrl'     => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce(\AperturePro\Admin\AdminUI::NONCE_ACTION),
            'restBase'    => rest_url('aperture/v1'),
            'restNonce'   => wp_create_nonce('wp_rest'),
            'healthCards' => $cards,
        ]);

        // Enqueue SPA bootstrap
        wp_enqueue_script('aperture-admin-spa', APERTURE_PRO_URL . 'assets/spa/bootstrap.js', ['ap-admin-ui-js'], '1.0.0', true);
    }

    /**
     * Add type="module" to SPA scripts.
     */
    public static function add_module_type($tag, $handle, $src)
    {
        if ($handle === 'aperture-admin-spa') {
            // Safe injection preserving other attributes (id, async, etc.)
            return str_replace(' src=', ' type="module" src=', $tag);
        }
        return $tag;
    }
}
