<?php

namespace AperturePro\Installer;

use AperturePro\Admin\AdminUI;

/**
 * SetupWizard
 *
 * Handles first-run setup wizard with stepper UX.
 */
class SetupWizard
{
    const OPTION_KEY = 'aperture_pro_setup_complete';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_page']);
        add_action('admin_init', [self::class, 'maybe_redirect']);
    }

    public static function maybe_redirect(): void
    {
        if (get_option(self::OPTION_KEY)) {
            return;
        }

        if (is_admin() && current_user_can('manage_options')) {
            if (!isset($_GET['page']) || $_GET['page'] !== 'aperture-pro-setup') {
                wp_safe_redirect(admin_url('admin.php?page=aperture-pro-setup'));
                exit;
            }
        }
    }

    public static function register_page(): void
    {
        add_submenu_page(
            null,
            'Aperture Pro Setup',
            'Aperture Pro Setup',
            'manage_options',
            'aperture-pro-setup',
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        include __DIR__ . '/../../templates/admin/setup-wizard.php';
    }
}
