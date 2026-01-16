<?php
/**
 * Plugin Name: Aperture Pro
 * Description: Photography Studio SaaS for Image Proofing, Download and Gallery Management powered by WordPress
 * Version: 1.0.0
 * Author: Aperture Pro
 * Text Domain: aperture-pro
 */

if (!defined('ABSPATH')) {
    exit;
}

/* -------------------------------------------------------------------------
 * Constants
 * ------------------------------------------------------------------------- */

define('APERTURE_PRO_VERSION', '1.0.0');
define('APERTURE_PRO_FILE', __FILE__);
define('APERTURE_PRO_DIR', plugin_dir_path(__FILE__));
define('APERTURE_PRO_URL', plugin_dir_url(__FILE__));

/* -------------------------------------------------------------------------
 * Autoload
 * ------------------------------------------------------------------------- */

$autoload = APERTURE_PRO_DIR . 'vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    require_once APERTURE_PRO_DIR . 'inc/autoloader.php';
}

/* -------------------------------------------------------------------------
 * Activation: set one‑time setup redirect flag
 * ------------------------------------------------------------------------- */

register_activation_hook(__FILE__, function () {
    if (!current_user_can('manage_options')) {
        return;
    }

    // Flag consumed on next admin load
    set_transient('aperture_pro_do_setup_redirect', 1, 60);
});

/* -------------------------------------------------------------------------
 * One‑time setup redirect (admin only)
 * ------------------------------------------------------------------------- */

add_action('admin_init', function () {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (!get_transient('aperture_pro_do_setup_redirect')) {
        return;
    }

    delete_transient('aperture_pro_do_setup_redirect');

    // Avoid redirecting during AJAX, REST, cron, or if already on setup page
    if (
        wp_doing_ajax() ||
        defined('REST_REQUEST') ||
        wp_doing_cron() ||
        (isset($_GET['page']) && $_GET['page'] === 'aperture-pro-setup')
    ) {
        return;
    }

    wp_safe_redirect(admin_url('admin.php?page=aperture-pro-setup'));
    exit;
});

/* -------------------------------------------------------------------------
 * Admin initialization
 * ------------------------------------------------------------------------- */

add_action('init', function () {
    if (!is_admin() || !current_user_can('manage_options')) {
        return;
    }

    if (class_exists('\AperturePro\Installer\SetupWizard')) {
        \AperturePro\Installer\SetupWizard::init();
    }

    if (class_exists('\AperturePro\Admin\AdminUI')) {
        \AperturePro\Admin\AdminUI::init();
    }

    if (class_exists('\AperturePro\Admin\HealthCard')) {
        \AperturePro\Admin\HealthCard::init();
    }
}, 5);
