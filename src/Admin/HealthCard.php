<?php

namespace AperturePro\Admin;

use AperturePro\Health\HealthService;

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
    }
}
