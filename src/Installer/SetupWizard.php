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
