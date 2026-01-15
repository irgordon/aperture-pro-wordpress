<?php
namespace AperturePro\Admin;

use AperturePro\Helpers\Logger;

/**
 * AdminUI
 *
 * Registers the plugin admin settings page, settings fields, sanitization, and AJAX validation endpoints.
 *
 * Key features:
 *  - Single settings option: 'aperture_pro_settings' (associative array)
 *  - Fields: storage driver, local storage path, cloud API key, imagekit/cloudinary keys, email sender, webhook secret, theme overrides toggle
 *  - Tooltips and help text for each field
 *  - AJAX endpoints to test API keys and webhook secrets
 *  - Enqueues admin JS/CSS only on the plugin settings page
 */
class AdminUI
{
    const OPTION_KEY = 'aperture_pro_settings';
    const PAGE_SLUG = 'aperture-pro-settings';
    const NONCE_ACTION = 'aperture_pro_admin_nonce';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_action('wp_ajax_aperture_pro_test_api_key', [self::class, 'ajax_test_api_key']);
        add_action('wp_ajax_aperture_pro_validate_webhook', [self::class, 'ajax_validate_webhook']);
    }

    public static function register_menu(): void
    {
        add_menu_page(
            'Aperture Pro',
            'Aperture Pro',
            'manage_options',
            'aperture-pro',
            [self::class, 'render_overview'],
            'dashicons-camera',
            58
        );

        add_submenu_page(
            'aperture-pro',
            'Settings',
            'Settings',
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render_settings_page']
        );
    }

    public static function render_overview(): void
    {
        // Simple overview page linking to settings and health
        ?>
        <div class="wrap">
            <h1>Aperture Pro</h1>
            <p>Welcome to Aperture Pro. Use the links below to configure the plugin and view system health.</p>
            <p>
                <a class="page-title-action" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>">Open Settings</a>
                <a class="page-title-action" href="<?php echo esc_url(admin_url('admin.php?page=aperture-pro-health')); ?>">Health Check</a>
            </p>
        </div>
        <?php
    }

    public static function register_settings(): void
    {
        register_setting('aperture_pro_group', self::OPTION_KEY, [self::class, 'sanitize_options']);

        add_settings_section(
            'aperture_pro_section_general',
            'General Settings',
            function () {
                echo '<p>Configure core Aperture Pro settings. Hover the help icons for guidance.</p>';
            },
            self::PAGE_SLUG
        );

        // Fields
        add_settings_field('storage_driver', 'Storage Driver', [self::class, 'field_storage_driver'], self::PAGE_SLUG, 'aperture_pro_section_general');
        add_settings_field('local_storage_path', 'Local Storage Path', [self::class, 'field_local_storage_path'], self::PAGE_SLUG, 'aperture_pro_section_general');
        add_settings_field('cloud_provider', 'Cloud Provider', [self::class, 'field_cloud_provider'], self::PAGE_SLUG, 'aperture_pro_section_general');
        add_settings_field('cloud_api_key', 'Cloud API Key', [self::class, 'field_cloud_api_key'], self::PAGE_SLUG, 'aperture_pro_section_general');
        add_settings_field('email_sender', 'Email Sender', [self::class, 'field_email_sender'], self::PAGE_SLUG, 'aperture_pro_section_general');
        add_settings_field('webhook_secret', 'Payment Webhook Secret', [self::class, 'field_webhook_secret'], self::PAGE_SLUG, 'aperture_pro_section_general');
        add_settings_field('require_otp', 'Require OTP for Downloads', [self::class, 'field_require_otp'], self::PAGE_SLUG, 'aperture_pro_section_general');
        add_settings_field('theme_overrides', 'Enable Theme Overrides', [self::class, 'field_theme_overrides'], self::PAGE_SLUG, 'aperture_pro_section_general');
    }

    public static function sanitize_options($input)
    {
        $out = [];

        // Storage driver
        $allowedDrivers = ['local', 'cloudinary', 'imagekit'];
        $driver = isset($input['storage_driver']) ? sanitize_text_field($input['storage_driver']) : 'local';
        $out['storage_driver'] = in_array($driver, $allowedDrivers, true) ? $driver : 'local';

        // Local storage path
        $out['local_storage_path'] = isset($input['local_storage_path']) ? sanitize_text_field($input['local_storage_path']) : '';

        // Cloud provider
        $allowedCloud = ['none', 'cloudinary', 'imagekit'];
        $cloud = isset($input['cloud_provider']) ? sanitize_text_field($input['cloud_provider']) : 'none';
        $out['cloud_provider'] = in_array($cloud, $allowedCloud, true) ? $cloud : 'none';

        // Cloud API key (store encrypted in future; for now sanitize)
        $out['cloud_api_key'] = isset($input['cloud_api_key']) ? sanitize_text_field($input['cloud_api_key']) : '';

        // Email sender
        $out['email_sender'] = isset($input['email_sender']) ? sanitize_email($input['email_sender']) : get_option('admin_email');

        // Webhook secret
        $out['webhook_secret'] = isset($input['webhook_secret']) ? sanitize_text_field($input['webhook_secret']) : '';

        // Require OTP
        $out['require_otp'] = !empty($input['require_otp']) ? 1 : 0;

        // Theme overrides
        $out['theme_overrides'] = !empty($input['theme_overrides']) ? 1 : 0;

        // Log changes for audit (non-sensitive fields only)
        Logger::log('info', 'admin_settings', 'Aperture Pro settings updated', [
            'storage_driver' => $out['storage_driver'],
            'cloud_provider' => $out['cloud_provider'],
            'require_otp' => $out['require_otp'],
            'theme_overrides' => $out['theme_overrides'],
        ]);

        return $out;
    }

    public static function enqueue_assets($hook)
    {
        // Only enqueue on our settings page
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        $isOurPage = ($screen->id === 'toplevel_page_aperture-pro' || $screen->id === 'aperture-pro_page_' . self::PAGE_SLUG);
        if (!$isOurPage) {
            return;
        }

        $pluginUrl = plugin_dir_url(__DIR__ . '/../../');
        wp_enqueue_style('ap-admin-ui-css', $pluginUrl . 'assets/css/admin-ui.css', [], '1.0.0');
        wp_enqueue_script('ap-admin-ui-js', $pluginUrl . 'assets/js/admin-ui.js', ['jquery'], '1.0.0', true);

        // Localize script with REST endpoints and nonce
        $data = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'testApiKeyAction' => 'aperture_pro_test_api_key',
            'validateWebhookAction' => 'aperture_pro_validate_webhook',
            'strings' => [
                'testing' => 'Testing…',
                'test_success' => 'Test succeeded',
                'test_failed' => 'Test failed',
                'invalid_key' => 'Invalid API key format',
            ],
        ];
        wp_localize_script('ap-admin-ui-js', 'ApertureAdmin', $data);
    }

    public static function render_settings_page()
    {
        // Load current options
        $opts = get_option(self::OPTION_KEY, []);
        include __DIR__ . '/../../templates/admin/settings-page.php';
    }

    /**
     * AJAX: test API key (placeholder)
     *
     * Security: requires manage_options and nonce.
     * In production, replace the placeholder test with a provider-specific API call.
     */
    public static function ajax_test_api_key()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $key = isset($_POST['key']) ? sanitize_text_field(wp_unslash($_POST['key'])) : '';
        $provider = isset($_POST['provider']) ? sanitize_text_field(wp_unslash($_POST['provider'])) : 'cloudinary';

        if (empty($key)) {
            wp_send_json_error(['message' => 'Missing key'], 400);
        }

        // Basic format validation
        if (strlen($key) < 8) {
            wp_send_json_error(['message' => 'Key appears too short'], 400);
        }

        // Placeholder: simulate a remote test with a short delay
        // In production, perform a real API call to the provider to validate the key.
        sleep(1);

        // Simulate success for keys that contain 'live' or 'sk_'
        $ok = (stripos($key, 'live') !== false) || (stripos($key, 'sk_') !== false) || (stripos($key, 'cloud') !== false);

        if ($ok) {
            wp_send_json_success(['message' => 'API key validated (simulated).']);
        }

        // Log failure for admin attention
        Logger::log('warning', 'admin_api_test', 'API key test failed', ['provider' => $provider, 'key_preview' => substr($key, 0, 6) . '...']);
        wp_send_json_error(['message' => 'API key validation failed (simulated). Please verify the key and provider.'], 400);
    }

    /**
     * AJAX: validate webhook secret format (placeholder)
     */
    public static function ajax_validate_webhook()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $secret = isset($_POST['secret']) ? sanitize_text_field(wp_unslash($_POST['secret'])) : '';
        if (empty($secret)) {
            wp_send_json_error(['message' => 'Missing secret'], 400);
        }

        // Basic validation: length and allowed chars
        if (!preg_match('/^[A-Za-z0-9_\-]{8,128}$/', $secret)) {
            wp_send_json_error(['message' => 'Webhook secret contains invalid characters or is too short.'], 400);
        }

        // Simulate verification success
        wp_send_json_success(['message' => 'Webhook secret format looks valid.']);
    }
}
