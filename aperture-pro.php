<?php

/**
 * The plugin bootstrap file
 *
 * @link              https://github.com/irgordon/aperture-pro-wordpress
 * @since             1.0.0
 * @package           Aperture_Pro
 *
 * @wordpress-plugin
 * Plugin Name:       Aperture Pro
 * Plugin URI:        https://iangordon.app/aperturepro
 * Description:       Aperture Pro is a modern, production‑grade WordPress plugin built for photography studios that need a secure, elegant, and scalable way to deliver proofs, collect approvals, and provide final downloads.
 * Version:           1.0.86
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

use AperturePro\Installer\Schema;
use AperturePro\Installer\Activator;

/* -------------------------------------------------------------------------
 * Constants
 * ------------------------------------------------------------------------- */

define('APERTURE_PRO_VERSION', '1.0.86');
define('APERTURE_PRO_FILE', __FILE__);
define('APERTURE_PRO_DIR', plugin_dir_path(__FILE__));
define('APERTURE_PRO_URL', plugin_dir_url(__FILE__));

/* -------------------------------------------------------------------------
 * Autoload (Composer-first, fail-soft fallback)
 * ------------------------------------------------------------------------- */

// Prefer Composer autoload when available (dev installs, CI, advanced hosts).
// Fallback autoloader supports ZIP installs and environments without Composer.
$composerAutoload = APERTURE_PRO_DIR . 'vendor/autoload.php';

if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
} else {
    require_once APERTURE_PRO_DIR . 'inc/autoloader.php';
}

/* -------------------------------------------------------------------------
 * Activation: one-time setup + schema install
 * ------------------------------------------------------------------------- */

register_activation_hook(__FILE__, function (): void {
    Activator::activate();

    if (!current_user_can('manage_options')) {
        return;
    }

    // Flag consumed on next admin load
    set_transient('aperture_pro_do_setup_redirect', 1, 60);
});

/*
 * Production safety:
 * Run lightweight upgrade checks on every load so schema changes
 * apply without requiring deactivate/activate cycles.
 */
add_action('plugins_loaded', static function (): void {
    Schema::maybe_upgrade();
}, 5);

/* -------------------------------------------------------------------------
 * One-time setup redirect (admin only)
 * ------------------------------------------------------------------------- */

add_action('admin_init', function (): void {
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

try {
    $environment = new \AperturePro\Environment(
        APERTURE_PRO_DIR,
        APERTURE_PRO_URL,
        APERTURE_PRO_VERSION
    );

    $loader = new \AperturePro\Loader(
        $environment
    );

    $loader->boot();
} catch (\Throwable $e) {
    // Fail-safe: Log catastrophic boot failures to PHP error log
    error_log(sprintf(
        '[Aperture Pro] Boot failure: %s in %s:%d',
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    if (defined('WP_DEBUG') && WP_DEBUG) {
        add_action('admin_notices', function () use ($e) {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>Aperture Pro Error:</strong> ' . esc_html($e->getMessage());
            echo '</p></div>';
        });
    }
}

/* -------------------------------------------------------------------------
 * Global accessor (legacy convenience)
 * ------------------------------------------------------------------------- */

/**
 * Global accessor for Aperture Pro services.
 *
 * NOTE:
 * This is a lightweight convenience wrapper.
 * Core services should be accessed via dependency injection where possible.
 *
 * @deprecated 1.0.36 Prefer dependency injection via Loader/Environment
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
