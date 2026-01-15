<?php
/**
 * Plugin Name: Aperture Pro
 * Description: Aperture Pro plugin bootstrap and REST route registration.
 * Version: 1.0.0
 * Author: Aperture Pro
 */

if (!defined('ABSPATH')) {
    exit;
}

// Composer autoload (if present)
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    // Minimal manual requires for critical classes if not using Composer
    $base = __DIR__;
    $maybe = [
        '/src/Helpers/Crypto.php',
        '/src/Admin/AdminUI.php',
        '/src/ClientPortal/PortalController.php',
        '/src/ClientPortal/PortalRenderer.php',
        '/src/REST/UploadController.php',
        '/src/REST/AuthController.php',
        '/src/REST/ClientProofController.php',
        '/src/REST/AdminController.php',
        '/src/REST/DownloadController.php',
        '/src/REST/PaymentController.php',
        '/src/Upload/ChunkedUploadHandler.php',
        '/src/Upload/Watchdog.php',
        '/src/Email/EmailService.php',
        '/src/Proof/ProofService.php',
        '/src/Services/PaymentService.php',
        // add other required files as needed
    ];
    foreach ($maybe as $rel) {
        $path = $base . $rel;
        if (file_exists($path)) {
            require_once $path;
        }
    }
}

// Initialize helpers and admin UI
if (class_exists('\AperturePro\Helpers\Crypto')) {
    // No explicit init required for Crypto; ensure class is autoloadable
}

if (class_exists('\AperturePro\Admin\AdminUI')) {
    \AperturePro\Admin\AdminUI::init();
}

// Theme variables admin module (optional)
if (class_exists('\AperturePro\Admin\ThemeVariables')) {
    \AperturePro\Admin\ThemeVariables::init();
}

// Client portal initialization
if (class_exists('\AperturePro\ClientPortal\PortalController')) {
    \AperturePro\ClientPortal\PortalController::init();
}

// Register REST controllers on rest_api_init
add_action('rest_api_init', function () {
    $controllers = [
        '\AperturePro\REST\UploadController',
        '\AperturePro\REST\AuthController',
        '\AperturePro\REST\ClientProofController',
        '\AperturePro\REST\AdminController',
        '\AperturePro\REST\DownloadController',
        '\AperturePro\REST\PaymentController',
        // add other controllers as needed
    ];

    foreach ($controllers as $class) {
        if (class_exists($class)) {
            $instance = new $class();
            if (method_exists($instance, 'register_routes')) {
                $instance->register_routes();
            }
        }
    }
});

// Cron schedules: add a 15-minute schedule if not present
add_filter('cron_schedules', function ($schedules) {
    if (!isset($schedules['quarterhour'])) {
        $schedules['quarterhour'] = [
            'interval' => 15 * 60,
            'display' => __('Every 15 Minutes'),
        ];
    }
    // Ensure minute schedule exists for email queue processing
    if (!isset($schedules['minute'])) {
        $schedules['minute'] = [
            'interval' => 60,
            'display' => __('Every Minute'),
        ];
    }
    return $schedules;
});

// Schedule Watchdog cron (every 15 minutes) and admin email queue (every minute)
register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled('aperture_pro_watchdog_cron')) {
        wp_schedule_event(time() + 60, 'quarterhour', 'aperture_pro_watchdog_cron');
    }
    if (!wp_next_scheduled('aperture_pro_send_admin_emails')) {
        wp_schedule_event(time() + 60, 'minute', 'aperture_pro_send_admin_emails');
    }
});

// Clear scheduled events on deactivation
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('aperture_pro_watchdog_cron');
    wp_clear_scheduled_hook('aperture_pro_send_admin_emails');
});

// Hook cron callbacks to service methods
if (class_exists('\AperturePro\Upload\Watchdog')) {
    add_action('aperture_pro_watchdog_cron', ['\AperturePro\Upload\Watchdog', 'run']);
}
if (class_exists('\AperturePro\Email\EmailService')) {
    add_action('aperture_pro_send_admin_emails', ['\AperturePro\Email\EmailService', 'processAdminQueue']);
}

// Optional: expose a small health endpoint or admin notice integration here
// (Health Card reads transients set by Watchdog and controllers)

// End of bootstrap
