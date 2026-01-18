<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://github.com/irgordon/aperture-pro-wordpress
 * @since             1.0.0
 * @package           Aperture_Pro
 *
 * @wordpress-plugin
 * Plugin Name:       Aperture Pro
 * Plugin URI:        https://iangordon.app/aperturepro
 * Description:       Aperture Pro is a modern, production‑grade WordPress plugin built for photography studios that need a secure, elegant, and scalable way to deliver proofs, collect approvals, and provide final downloads. It blends a polished client experience with a robust operational backend designed for reliability, observability, and long‑term maintainability.
 * Version:           1.0.0
 * Author:            Ian Gordon
 * Author URI:        https://iangordon.app/
 * License:           MIT License
 * License URI:       https://mit-license.org/
 * Text Domain:       aperture-pro
 * Domain Path:       /languages
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
 * Plugin Boot
 * ------------------------------------------------------------------------- */

$loader = new \AperturePro\Loader(
    APERTURE_PRO_FILE,
    APERTURE_PRO_DIR,
    APERTURE_PRO_URL,
    APERTURE_PRO_VERSION
);

$loader->boot();

/**
 * Global accessor for Aperture Pro services.
 *
 * @return object
 */
function aperture_pro()
{
    static $instance;
    if (!$instance) {
        $instance = new class {
            public $settings;
            public function __construct()
            {
                $this->settings = new \AperturePro\Config\Settings();
            }
        };
    }
    return $instance;
}
