<?php

namespace AperturePro\Installer;

/**
 * SetupWizard
 *
 * Registers the setup wizard page.
 * Redirect logic is intentionally NOT handled here.
 * Redirects are managed centrally in aperture-pro.php
 * using a one‑time activation flag.
 */
class SetupWizard
{
    const SETUP_COMPLETE_OPTION = 'aperture_pro_setup_complete';

    /**
     * Register wizard page.
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_page']);
        add_action('wp_ajax_aperture_pro_save_wizard', [self::class, 'save_wizard']);
    }

    /**
     * AJAX handler to save wizard settings.
     */
    public static function save_wizard(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        check_ajax_referer('aperture_pro_admin_nonce', 'nonce');

        // Reuse AdminUI logic for sanitization and encryption
        $input = $_POST['settings'] ?? [];
        $sanitized = \AperturePro\Admin\AdminUI::sanitize_options($input);

        // Merge with existing
        $existing = get_option(\AperturePro\Admin\AdminUI::OPTION_KEY, []);
        $merged = array_merge($existing, $sanitized);

        update_option(\AperturePro\Admin\AdminUI::OPTION_KEY, $merged);
        self::mark_complete();

        wp_send_json_success(['message' => 'Setup complete', 'redirect' => admin_url('admin.php?page=aperture-pro')]);
    }

    /**
     * Register hidden setup wizard page.
     */
    public static function register_page(): void
    {
        add_submenu_page(
            null,
            __('Aperture Pro Setup', 'aperture-pro'),
            __('Aperture Pro Setup', 'aperture-pro'),
            'manage_options',
            'aperture-pro-setup',
            [self::class, 'render']
        );
    }

    /**
     * Render setup wizard UI.
     */
    public static function render(): void
    {
        include APERTURE_PRO_DIR . 'templates/admin/setup-wizard.php';
    }

    /**
     * Mark setup as complete.
     * Call this when the wizard finishes successfully.
     */
    public static function mark_complete(): void
    {
        update_option(self::SETUP_COMPLETE_OPTION, 1);
    }
}
