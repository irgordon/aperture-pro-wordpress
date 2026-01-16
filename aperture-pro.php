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

/**
 * -----------------------------------------------------------------------------
 * Constants
 * -----------------------------------------------------------------------------
 *
 * Keep these lightweight and deterministic. Other modules rely on them for
 * asset URLs, template paths, and service worker registration.
 */
if (!defined('APERTURE_PRO_VERSION')) {
    define('APERTURE_PRO_VERSION', '1.0.0');
}
if (!defined('APERTURE_PRO_FILE')) {
    define('APERTURE_PRO_FILE', __FILE__);
}
if (!defined('APERTURE_PRO_DIR')) {
    define('APERTURE_PRO_DIR', plugin_dir_path(__FILE__));
}
if (!defined('APERTURE_PRO_URL')) {
    define('APERTURE_PRO_URL', plugin_dir_url(__FILE__));
}

/**
 * -----------------------------------------------------------------------------
 * Dependency checks (fail-soft)
 * -----------------------------------------------------------------------------
 *
 * We avoid fatal errors where possible to keep admin accessible for remediation.
 * Only truly incompatible environments should block activation.
 */
function aperture_pro_minimum_requirements_met(): bool
{
    // Minimum PHP version for modern crypto/libs and typed code patterns.
    if (version_compare(PHP_VERSION, '7.4.0', '<')) {
        return false;
    }

    return true;
}

function aperture_pro_admin_notice_minimum_requirements(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    if (aperture_pro_minimum_requirements_met()) {
        return;
    }

    echo '<div class="notice notice-error"><p>';
    echo esc_html__('Aperture Pro requires PHP 7.4 or newer. Please upgrade PHP to use this plugin.', 'aperture-pro');
    echo '</p></div>';
}

add_action('admin_notices', 'aperture_pro_admin_notice_minimum_requirements');

/**
 * -----------------------------------------------------------------------------
 * Autoloading
 * -----------------------------------------------------------------------------
 *
 * Prefer Composer autoload. If not present, load only the minimal files needed
 * for bootstrap. Avoid loading everything to prevent memory hogs and slow admin.
 */
$autoload = APERTURE_PRO_DIR . 'vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    // Minimal manual boot requires (keep this list short and explicit).
    $manual = [
        'src/Helpers/Logger.php',
        'src/Helpers/Crypto.php',

        // Admin + installer
        'src/Admin/AdminUI.php',
        'src/Installer/SetupWizard.php',

        // Cron services
        'src/Upload/Watchdog.php',
        'src/Email/EmailService.php',

        // REST base + controllers
        'src/REST/BaseController.php',
        'src/REST/AuthController.php',
        'src/REST/UploadController.php',
        'src/REST/ClientProofController.php',
        'src/REST/AdminController.php',
        'src/REST/DownloadController.php',
        'src/REST/PaymentController.php',

        // Portal (if your portal controller is required for front-end routing)
        'src/ClientPortal/PortalController.php',
        'src/ClientPortal/PortalRenderer.php',
    ];

    foreach ($manual as $rel) {
        $path = APERTURE_PRO_DIR . $rel;
        if (file_exists($path)) {
            require_once $path;
        }
    }
}

/**
 * -----------------------------------------------------------------------------
 * i18n
 * -----------------------------------------------------------------------------
 *
 * Load translations on plugins_loaded (safe, standard).
 */
add_action('plugins_loaded', function () {
    load_plugin_textdomain('aperture-pro', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

/**
 * -----------------------------------------------------------------------------
 * Admin initialization (admin-only)
 * -----------------------------------------------------------------------------
 *
 * Avoid loading admin UI and wizard on front-end and REST requests.
 */
add_action('init', function () {
    if (!is_admin()) {
        return;
    }

    if (!current_user_can('manage_options')) {
        return;
    }

    // Setup wizard (first-run) – only if class exists.
    if (class_exists('\AperturePro\Installer\SetupWizard')) {
        \AperturePro\Installer\SetupWizard::init();
    }

    // Main Admin UI
    if (class_exists('\AperturePro\Admin\AdminUI')) {
        \AperturePro\Admin\AdminUI::init();
    }

    // Optional admin modules (theme variables, health card, etc.)
    if (class_exists('\AperturePro\Admin\ThemeVariables')) {
        \AperturePro\Admin\ThemeVariables::init();
    }
}, 5);

/**
 * -----------------------------------------------------------------------------
 * Client portal initialization (front-end)
 * -----------------------------------------------------------------------------
 *
 * Keep this lightweight: routing hooks + template rendering; avoid heavy work.
 */
add_action('init', function () {
    if (is_admin()) {
        return;
    }

    if (class_exists('\AperturePro\ClientPortal\PortalController')) {
        \AperturePro\ClientPortal\PortalController::init();
    }
}, 5);

/**
 * -----------------------------------------------------------------------------
 * REST route registration (rest_api_init)
 * -----------------------------------------------------------------------------
 *
 * Only instantiate controllers during REST initialization to avoid overhead
 * for non-REST requests.
 */
add_action('rest_api_init', function () {
    $controllers = [
        '\AperturePro\REST\AuthController',
        '\AperturePro\REST\UploadController',
        '\AperturePro\REST\ClientProofController',
        '\AperturePro\REST\AdminController',
        '\AperturePro\REST\DownloadController',
        '\AperturePro\REST\PaymentController',
    ];

    foreach ($controllers as $class) {
        if (!class_exists($class)) {
            continue;
        }

        $instance = new $class();
        if (method_exists($instance, 'register_routes')) {
            $instance->register_routes();
        }
    }
});

/**
 * -----------------------------------------------------------------------------
 * Cron schedules
 * -----------------------------------------------------------------------------
 *
 * Keep schedules centralized. Use conservative intervals; avoid creating many
 * schedules that could be abused.
 */
add_filter('cron_schedules', function ($schedules) {
    if (!isset($schedules['quarterhour'])) {
        $schedules['quarterhour'] = [
            'interval' => 15 * 60,
            'display'  => __('Every 15 Minutes', 'aperture-pro'),
        ];
    }

    if (!isset($schedules['minute'])) {
        $schedules['minute'] = [
            'interval' => 60,
            'display'  => __('Every Minute', 'aperture-pro'),
        ];
    }

    return $schedules;
});

/**
 * -----------------------------------------------------------------------------
 * Activation / deactivation
 * -----------------------------------------------------------------------------
 *
 * Schedule watchdog and email queue processing with small jitter so that on
 * multi-site or fleets you don’t synchronize spikes.
 */
register_activation_hook(__FILE__, function () {
    if (!aperture_pro_minimum_requirements_met()) {
        // Block activation in incompatible environments.
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(esc_html__('Aperture Pro requires PHP 7.4 or newer.', 'aperture-pro'));
    }

    $jitter = rand(30, 120);

    if (!wp_next_scheduled('aperture_pro_watchdog_cron')) {
        wp_schedule_event(time() + $jitter, 'quarterhour', 'aperture_pro_watchdog_cron');
    }

    if (!wp_next_scheduled('aperture_pro_send_admin_emails')) {
        wp_schedule_event(time() + $jitter, 'minute', 'aperture_pro_send_admin_emails');
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('aperture_pro_watchdog_cron');
    wp_clear_scheduled_hook('aperture_pro_send_admin_emails');
});

/**
 * -----------------------------------------------------------------------------
 * Cron callbacks
 * -----------------------------------------------------------------------------
 *
 * Bind cron events to class methods only if classes exist.
 */
if (class_exists('\AperturePro\Upload\Watchdog')) {
    add_action('aperture_pro_watchdog_cron', ['\AperturePro\Upload\Watchdog', 'run']);
}

if (class_exists('\AperturePro\Email\EmailService')) {
    add_action('aperture_pro_send_admin_emails', ['\AperturePro\Email\EmailService', 'processAdminQueue']);
}

/**
 * -----------------------------------------------------------------------------
 * Optional admin dependency notices (non-blocking)
 * -----------------------------------------------------------------------------
 *
 * Helpful signals for operators without breaking the site.
 */
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) {
        return;
    }

    // Crypto extensions: OpenSSL or Sodium recommended.
    if (!function_exists('openssl_encrypt') && !function_exists('sodium_crypto_secretbox')) {
        echo '<div class="notice notice-warning"><p>';
        echo esc_html__('Aperture Pro: OpenSSL or Sodium is not available. Encrypted secret storage may be limited.', 'aperture-pro');
        echo '</p></div>';
    }

    // Image processing: Imagick or GD recommended for proof generation.
    if (!extension_loaded('imagick') && !extension_loaded('gd')) {
        echo '<div class="notice notice-warning"><p>';
        echo esc_html__('Aperture Pro: Imagick or GD is not available. Proof generation may fall back to non-optimized behavior.', 'aperture-pro');
        echo '</p></div>';
    }

    // cURL recommended for parallel ZIP downloads.
    if (!function_exists('curl_multi_init')) {
        echo '<div class="notice notice-warning"><p>';
        echo esc_html__('Aperture Pro: cURL multi is not available. ZIP generation may be slower due to sequential downloads.', 'aperture-pro');
        echo '</p></div>';
    }
});
