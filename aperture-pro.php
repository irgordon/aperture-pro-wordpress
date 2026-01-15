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
}

// Fallback autoloader or manual requires could be added here if not using Composer.

// Register REST controllers and cron hooks on init
add_action('rest_api_init', function () {
    // Instantiate controllers and register routes
    $controllers = [
        new \AperturePro\REST\UploadController(),
        new \AperturePro\REST\AuthController(),
        new \AperturePro\REST\ClientProofController(),
        new \AperturePro\REST\AdminController(),
        new \AperturePro\REST\DownloadController(),
        // other controllers already present in the plugin...
    ];

    foreach ($controllers as $controller) {
        if (method_exists($controller, 'register_routes')) {
            $controller->register_routes();
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
    return $schedules;
});

// Schedule Watchdog cron (every 15 minutes)
register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled('aperture_pro_watchdog_cron')) {
        wp_schedule_event(time() + 60, 'quarterhour', 'aperture_pro_watchdog_cron');
    }
    // Schedule admin email queue processor (every minute)
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
add_action('aperture_pro_watchdog_cron', ['\AperturePro\Upload\Watchdog', 'run']);
add_action('aperture_pro_send_admin_emails', ['\AperturePro\Email\EmailService', 'processAdminQueue']);

// Optionally, ensure EmailService cron hook is registered for processing admin queue
// (EmailService::CRON_HOOK constant exists in EmailService; we also schedule above).
