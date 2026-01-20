<?php
/**
 * AdminUI
 *
 * This class registers the Aperture Pro admin settings UI, handles sanitization,
 * encrypts sensitive fields, and exposes AJAX endpoints for validating API keys
 * and webhook secrets.
 *
 * DESIGN GOALS:
 *  - Provide a modern SaaS-style admin UI while respecting WordPress admin styling.
 *  - Make configuration simple for photographers (tooltips, examples, inline help).
 *  - Store sensitive values (API keys, webhook secrets, CloudFront private keys)
 *    encrypted at rest using Crypto::encrypt().
 *  - Never expose decrypted secrets in the UI.
 *  - Provide AJAX "Test" buttons for API keys and webhook secrets.
 *  - Keep the file readable and maintainable with clear comments.
 */

namespace AperturePro\Admin;

use AperturePro\Helpers\Logger;
use AperturePro\Helpers\Crypto;

class AdminUI
{
    /** Option key where all settings are stored */
    const OPTION_KEY = 'aperture_pro_settings';

    /** Slug for the settings page */
    const PAGE_SLUG = 'aperture-pro-settings';

    /** Slug for the command center page */
    const PAGE_COMMAND_CENTER = 'aperture-pro-command-center';

    /** Nonce action for AJAX security */
    const NONCE_ACTION = 'aperture_pro_admin_nonce';

    /**
     * Initialize all admin hooks.
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);

        // AJAX endpoints for testing API keys and webhook secrets
        add_action('wp_ajax_aperture_pro_test_api_key', [self::class, 'ajax_test_api_key']);
        add_action('wp_ajax_aperture_pro_validate_webhook', [self::class, 'ajax_validate_webhook']);
        add_filter('script_loader_tag', [self::class, 'add_module_type'], 10, 3);
    }

    /**
     * Register the top-level "Aperture Pro" menu and the Settings submenu.
     */
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
            'Command Center',
            'Command Center',
            'manage_options',
            self::PAGE_COMMAND_CENTER,
            [self::class, 'render_command_center']
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

    /**
     * Render the Command Center page.
     */
    public static function render_command_center(): void
    {
        include __DIR__ . '/../../templates/admin/command-center.php';
    }

    /**
     * Simple overview page linking to Settings and Health.
     */
    public static function render_overview(): void
    {
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

    /**
     * Register settings and fields using the WordPress Settings API.
     */
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

        // Core storage fields
        add_settings_field('storage_driver', 'Storage Driver', [self::class, 'field_storage_driver'], self::PAGE_SLUG, 'aperture_pro_section_general');
        add_settings_field('local_storage_path', 'Local Storage Path', [self::class, 'field_local_storage_path'], self::PAGE_SLUG, 'aperture_pro_section_general');
        add_settings_field('cloud_provider', 'Cloud Provider', [self::class, 'field_cloud_provider'], self::PAGE_SLUG, 'aperture_pro_section_general');
        add_settings_field('cloudinary_cloud_name', 'Cloud Name', [self::class, 'field_cloudinary_cloud_name'], self::PAGE_SLUG, 'aperture_pro_section_general');
        add_settings_field('cloud_api_key', 'Cloud API Key', [self::class, 'field_cloud_api_key'], self::PAGE_SLUG, 'aperture_pro_section_general');
        add_settings_field('cloudinary_api_secret', 'Cloudinary API Secret', [self::class, 'field_cloudinary_api_secret'], self::PAGE_SLUG, 'aperture_pro_section_general');

        // AWS S3 + CloudFront fields
        add_settings_field('s3_bucket', 'S3 Bucket', [self::class, 'field_s3_bucket'], self::PAGE_SLUG, 'aperture_pro_section_general');
        add_settings_field('s3_region', 'S3 Region', [self::class, 'field_s3_region'], self::PAGE_SLUG, 'aperture_pro_section_general');
        add_settings_field('s3_access_key', 'S3 Access Key', [self::class, 'field_s3_access_key'], self::PAGE_SLUG, 'aperture_pro_section_general');
        add_settings_field('s3_secret_key', 'S3 Secret Key', [self::class, 'field_s3_secret_key'], self::PAGE_SLUG, 'aperture_pro_section_general');
        add_settings_field('cloudfront_domain', 'CloudFront Domain', [self::class, 'field_cloudfront_domain'], self::PAGE_SLUG, 'aperture_pro_section_general');
        add_settings_field('cloudfront_key_pair_id', 'CloudFront Key Pair ID', [self::class, 'field_cloudfront_key_pair_id'], self::PAGE_SLUG, 'aperture_pro_section_general');
        add_settings_field('cloudfront_private_key', 'CloudFront Private Key', [self::class, 'field_cloudfront_private_key'], self::PAGE_SLUG, 'aperture_pro_section_general');

        // Email + notifications
        add_settings_field('email_sender', 'Email Sender', [self::class, 'field_email_sender'], self::PAGE_SLUG, 'aperture_pro_section_general');
        add_settings_field('webhook_secret', 'Payment Webhook Secret', [self::class, 'field_webhook_secret'], self::PAGE_SLUG, 'aperture_pro_section_general');
        add_settings_field('require_otp', 'Require OTP for Downloads', [self::class, 'field_require_otp'], self::PAGE_SLUG, 'aperture_pro_section_general');

        // Theme overrides
        add_settings_field('theme_overrides', 'Enable Theme Overrides', [self::class, 'field_theme_overrides'], self::PAGE_SLUG, 'aperture_pro_section_general');
    }

    /**
     * Sanitize and persist options.
     *
     * IMPORTANT:
     *  - Sensitive fields are encrypted using Crypto::encrypt().
     *  - Existing encrypted values are preserved if the admin leaves fields blank.
     *  - Only non-sensitive metadata is logged.
     */
    public static function sanitize_options($input)
    {
        $out = [];

        // -----------------------------
        // STORAGE DRIVER
        // -----------------------------
        $allowedDrivers = ['local', 'cloudinary', 'imagekit', 's3'];
        $driver = sanitize_text_field($input['storage_driver'] ?? 'local');
        $out['storage_driver'] = in_array($driver, $allowedDrivers, true) ? $driver : 'local';

        // Local storage path
        $out['local_storage_path'] = sanitize_text_field($input['local_storage_path'] ?? '');

        // Cloud provider
        $allowedCloud = ['none', 'cloudinary', 'imagekit'];
        $cloud = sanitize_text_field($input['cloud_provider'] ?? 'none');
        $out['cloud_provider'] = in_array($cloud, $allowedCloud, true) ? $cloud : 'none';

        // Cloud API key (encrypted)
        $existing = get_option(self::OPTION_KEY, []);
        if (!empty($input['cloud_api_key'])) {
            $out['cloud_api_key'] = Crypto::encrypt(sanitize_text_field($input['cloud_api_key']));
        } else {
            $out['cloud_api_key'] = $existing['cloud_api_key'] ?? '';
        }

        // Cloudinary Cloud Name
        $out['cloudinary_cloud_name'] = sanitize_text_field($input['cloudinary_cloud_name'] ?? '');

        // Cloudinary API Secret (encrypted)
        if (!empty($input['cloudinary_api_secret'])) {
            $out['cloudinary_api_secret'] = Crypto::encrypt(sanitize_text_field($input['cloudinary_api_secret']));
        } else {
            $out['cloudinary_api_secret'] = $existing['cloudinary_api_secret'] ?? '';
        }

        // -----------------------------
        // AWS S3 + CLOUDFRONT
        // -----------------------------
        $out['s3_bucket'] = sanitize_text_field($input['s3_bucket'] ?? '');
        $out['s3_region'] = sanitize_text_field($input['s3_region'] ?? '');

        // S3 Access Key (encrypted)
        if (!empty($input['s3_access_key'])) {
            $out['s3_access_key'] = Crypto::encrypt(sanitize_text_field($input['s3_access_key']));
        } else {
            $out['s3_access_key'] = $existing['s3_access_key'] ?? '';
        }

        // S3 Secret Key (encrypted)
        if (!empty($input['s3_secret_key'])) {
            $out['s3_secret_key'] = Crypto::encrypt(sanitize_text_field($input['s3_secret_key']));
        } else {
            $out['s3_secret_key'] = $existing['s3_secret_key'] ?? '';
        }

        // CloudFront domain
        $out['cloudfront_domain'] = esc_url_raw($input['cloudfront_domain'] ?? '');

        // CloudFront Key Pair ID
        $out['cloudfront_key_pair_id'] = sanitize_text_field($input['cloudfront_key_pair_id'] ?? '');

        // CloudFront Private Key (encrypted)
        if (!empty($input['cloudfront_private_key'])) {
            $out['cloudfront_private_key'] = Crypto::encrypt(trim($input['cloudfront_private_key']));
        } else {
            $out['cloudfront_private_key'] = $existing['cloudfront_private_key'] ?? '';
        }

        // -----------------------------
        // EMAIL + WEBHOOK
        // -----------------------------
        $out['email_sender'] = sanitize_email($input['email_sender'] ?? get_option('admin_email'));

        // Webhook secret (encrypted)
        if (!empty($input['webhook_secret'])) {
            $out['webhook_secret'] = Crypto::encrypt(sanitize_text_field($input['webhook_secret']));
        } else {
            $out['webhook_secret'] = $existing['webhook_secret'] ?? '';
        }

        // OTP requirement
        $out['require_otp'] = !empty($input['require_otp']) ? 1 : 0;

        // Theme overrides
        $out['theme_overrides'] = !empty($input['theme_overrides']) ? 1 : 0;

        // Log non-sensitive metadata
        Logger::log('info', 'admin_settings', 'Aperture Pro settings updated', [
            'storage_driver'  => $out['storage_driver'],
            'cloud_provider'  => $out['cloud_provider'],
            'require_otp'     => $out['require_otp'],
            'theme_overrides' => $out['theme_overrides'],
        ]);

        return $out;
    }

    /**
     * Enqueue admin CSS + JS only on plugin pages.
     */
    public static function enqueue_assets($hook)
    {
        $screen = get_current_screen();
        if (!$screen) return;

        $pluginUrl = plugin_dir_url(__DIR__ . '/../../');

        // Main Admin UI (Settings, etc.)
        $isSettingsPage =
            $screen->id === 'toplevel_page_aperture-pro' ||
            $screen->id === 'aperture-pro_page_' . self::PAGE_SLUG ||
            $screen->id === 'aperture-pro_page_' . self::PAGE_COMMAND_CENTER;

        if ($isSettingsPage) {
            // Toast System
            wp_enqueue_style('ap-toast-css', $pluginUrl . 'assets/css/ap-toast.css', [], '1.0.0');
            wp_enqueue_script('ap-toast-js', $pluginUrl . 'assets/js/ap-toast.js', [], '1.0.0', true);

            wp_enqueue_style('ap-admin-ui-css', $pluginUrl . 'assets/css/admin-ui.css', [], '1.0.0');
            wp_enqueue_script('ap-admin-ui-js', $pluginUrl . 'assets/js/admin-ui.js', ['jquery'], '1.0.0', true);

            wp_localize_script('ap-admin-ui-js', 'ApertureAdmin', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce(self::NONCE_ACTION),
                'restBase' => rest_url('aperture/v1'),
                'restNonce' => wp_create_nonce('wp_rest'),
                'testApiKeyAction' => 'aperture_pro_test_api_key',
                'validateWebhookAction' => 'aperture_pro_validate_webhook',
                'strings' => [
                    'testing' => 'Testing…',
                    'test_success' => 'Test succeeded',
                    'test_failed' => 'Test failed',
                    'invalid_key' => 'Invalid API key format',
                ],
            ]);

            // Enqueue SPA bootstrap for Command Center
            if ($screen->id === 'aperture-pro_page_' . self::PAGE_COMMAND_CENTER) {
                 wp_enqueue_script('aperture-admin-spa', $pluginUrl . 'assets/spa/bootstrap.js', ['ap-admin-ui-js'], '1.0.0', true);
            }

            return;
        }

        // Setup Wizard
        if (strpos($screen->id, 'aperture-pro-setup') !== false) {
            // Toast System
            wp_enqueue_style('ap-toast-css', $pluginUrl . 'assets/css/ap-toast.css', [], '1.0.0');
            wp_enqueue_script('ap-toast-js', $pluginUrl . 'assets/js/ap-toast.js', [], '1.0.0', true);

            // Modal System
            wp_enqueue_style('ap-modal-css', $pluginUrl . 'assets/css/ap-modal.css', [], '1.0.0');
            wp_enqueue_script('ap-modal-js', $pluginUrl . 'assets/js/ap-modal.js', [], '1.0.0', true);

            // Wizard Assets
            wp_enqueue_style('ap-setup-wizard-css', $pluginUrl . 'assets/css/setup-wizard.css', ['ap-modal-css'], '1.0.0');
            wp_enqueue_script('ap-setup-wizard-js', $pluginUrl . 'assets/js/setup-wizard.js', ['ap-modal-js'], '1.0.0', true);

            wp_localize_script('ap-setup-wizard-js', 'ApertureSetup', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce(self::NONCE_ACTION),
            ]);
        }
    }

    /**
     * Render the settings page template.
     */
    public static function render_settings_page()
    {
        $opts = get_option(self::OPTION_KEY, []);
        include __DIR__ . '/../../templates/admin/settings-page.php';
    }

    /**
     * AJAX: Test API key (simulated).
     *
     * SECURITY:
     *  - Requires manage_options capability.
     *  - Requires nonce.
     *  - Does NOT log full keys.
     */
    public static function ajax_test_api_key()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $key = sanitize_text_field($_POST['key'] ?? '');
        $provider = sanitize_text_field($_POST['provider'] ?? 'cloudinary');

        if (empty($key)) {
            wp_send_json_error(['message' => 'Missing key'], 400);
        }

        if (strlen($key) < 8) {
            wp_send_json_error(['message' => 'Key appears too short'], 400);
        }

        $ok = stripos($key, 'live') !== false ||
              stripos($key, 'sk_') !== false ||
              stripos($key, 'cloud') !== false;

        if ($ok) {
            wp_send_json_success(['message' => 'API key validated (simulated).']);
        }

        Logger::log('warning', 'admin_api_test', 'API key test failed', [
            'provider' => $provider,
            'key_preview' => substr($key, 0, 6) . '...',
        ]);

        wp_send_json_error(['message' => 'API key validation failed (simulated).'], 400);
    }

    /**
     * AJAX: Validate webhook secret format.
     */
    public static function ajax_validate_webhook()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $secret = sanitize_text_field($_POST['secret'] ?? '');

        if (empty($secret)) {
            wp_send_json_error(['message' => 'Missing secret'], 400);
        }

        if (!preg_match('/^[A-Za-z0-9_\-]{8,128}$/', $secret)) {
            wp_send_json_error(['message' => 'Webhook secret contains invalid characters or is too short.'], 400);
        }

        wp_send_json_success(['message' => 'Webhook secret format looks valid.']);
    }

    /**
     * Add type="module" to SPA scripts.
     */
    public static function add_module_type($tag, $handle, $src)
    {
        if ($handle === 'aperture-admin-spa') {
            return str_replace(' src=', ' type="module" src=', $tag);
        }
        return $tag;
    }

    /* ============================================================
     * FIELD RENDERERS
     * ============================================================ */

    public static function field_storage_driver()
    {
        $opts = get_option(self::OPTION_KEY, []);
        $value = $opts['storage_driver'] ?? 'local';
        ?>
        <select id="storage_driver" name="<?php echo esc_attr(self::OPTION_KEY); ?>[storage_driver]">
            <option value="local" <?php selected($value, 'local'); ?>>Local (server)</option>
            <option value="cloudinary" <?php selected($value, 'cloudinary'); ?>>Cloudinary</option>
            <option value="imagekit" <?php selected($value, 'imagekit'); ?>>ImageKit</option>
            <option value="s3" <?php selected($value, 's3'); ?>>Amazon S3</option>
        </select>
        <span class="ap-tooltip" title="Choose where files are stored.">?</span>
        <?php
    }

    public static function field_local_storage_path()
    {
        $opts = get_option(self::OPTION_KEY, []);
        $value = $opts['local_storage_path'] ?? '';
        ?>
        <input type="text" id="local_storage_path"
               name="<?php echo esc_attr(self::OPTION_KEY); ?>[local_storage_path]"
               value="<?php echo esc_attr($value); ?>"
               class="regular-text" />
        <p class="description">Relative to WP uploads directory.</p>
        <?php
    }

    public static function field_cloud_provider()
    {
        $opts = get_option(self::OPTION_KEY, []);
        $value = $opts['cloud_provider'] ?? 'none';
        ?>
        <select id="cloud_provider" name="<?php echo esc_attr(self::OPTION_KEY); ?>[cloud_provider]">
            <option value="none" <?php selected($value, 'none'); ?>>None</option>
            <option value="cloudinary" <?php selected($value, 'cloudinary'); ?>>Cloudinary</option>
            <option value="imagekit" <?php selected($value, 'imagekit'); ?>>ImageKit</option>
        </select>
        <span class="ap-tooltip" title="Optional cloud provider for proofing/delivery.">?</span>
        <?php
    }

    public static function field_cloud_api_key()
    {
        $opts = get_option(self::OPTION_KEY, []);
        $hasKey = !empty($opts['cloud_api_key']);
        ?>
        <input type="password"
               id="cloud_api_key"
               name="<?php echo esc_attr(self::OPTION_KEY); ?>[cloud_api_key]"
               value=""
               class="regular-text"
               placeholder="<?php echo $hasKey ? '••••••••' : ''; ?>" />
        <button type="button" class="button" id="ap-test-api-key">Test</button>
        <span id="ap-test-api-key-result" class="ap-test-result"></span>
        <p class="description">Stored encrypted. Click Test to validate.</p>
        <?php
    }

    public static function field_cloudinary_cloud_name()
    {
        $opts = get_option(self::OPTION_KEY, []);
        $value = $opts['cloudinary_cloud_name'] ?? '';
        ?>
        <input type="text"
               id="cloudinary_cloud_name"
               name="<?php echo esc_attr(self::OPTION_KEY); ?>[cloudinary_cloud_name]"
               value="<?php echo esc_attr($value); ?>"
               class="regular-text" />
        <?php
    }

    public static function field_cloudinary_api_secret()
    {
        $opts = get_option(self::OPTION_KEY, []);
        $hasKey = !empty($opts['cloudinary_api_secret']);
        ?>
        <input type="password"
               id="cloudinary_api_secret"
               name="<?php echo esc_attr(self::OPTION_KEY); ?>[cloudinary_api_secret]"
               value=""
               class="regular-text"
               placeholder="<?php echo $hasKey ? '••••••••' : ''; ?>" />
        <?php
    }

    /* -------------------------
     * S3 + CloudFront fields
     * ------------------------- */

    public static function field_s3_bucket()
    {
        $opts = get_option(self::OPTION_KEY, []);
        $value = $opts['s3_bucket'] ?? '';
        ?>
        <input type="text" id="s3_bucket"
               name="<?php echo esc_attr(self::OPTION_KEY); ?>[s3_bucket]"
               value="<?php echo esc_attr($value); ?>"
               class="regular-text" />
        <span class="ap-tooltip" title="Your S3 bucket name. Must already exist.">?</span>
        <?php
    }

    public static function field_s3_region()
    {
        $opts = get_option(self::OPTION_KEY, []);
        $value = $opts['s3_region'] ?? '';
        ?>
        <input type="text" id="s3_region"
               name="<?php echo esc_attr(self::OPTION_KEY); ?>[s3_region]"
               value="<?php echo esc_attr($value); ?>"
               class="regular-text" />
        <?php
    }

    public static function field_s3_access_key()
    {
        $opts = get_option(self::OPTION_KEY, []);
        $hasKey = !empty($opts['s3_access_key']);
        ?>
        <input type="password" id="s3_access_key"
               name="<?php echo esc_attr(self::OPTION_KEY); ?>[s3_access_key]"
               value=""
               placeholder="<?php echo $hasKey ? '••••••••' : ''; ?>"
               class="regular-text" />
        <?php
    }

    public static function field_s3_secret_key()
    {
        $opts = get_option(self::OPTION_KEY, []);
        $hasKey = !empty($opts['s3_secret_key']);
        ?>
        <input type="password" id="s3_secret_key"
               name="<?php echo esc_attr(self::OPTION_KEY); ?>[s3_secret_key]"
               value=""
               placeholder="<?php echo $hasKey ? '••••••••' : ''; ?>"
               class="regular-text" />
        <?php
    }

    public static function field_cloudfront_domain()
    {
        $opts = get_option(self::OPTION_KEY, []);
        $value = $opts['cloudfront_domain'] ?? '';
        ?>
        <input type="text" id="cloudfront_domain"
               name="<?php echo esc_attr(self::OPTION_KEY); ?>[cloudfront_domain]"
               value="<?php echo esc_attr($value); ?>"
               class="regular-text" />
        <?php
    }

    public static function field_cloudfront_key_pair_id()
    {
        $opts = get_option(self::OPTION_KEY, []);
        $value = $opts['cloudfront_key_pair_id'] ?? '';
        ?>
        <input type="text" id="cloudfront_key_pair_id"
               name="<?php echo esc_attr(self::OPTION_KEY); ?>[cloudfront_key_pair_id]"
               value="<?php echo esc_attr($value); ?>"
               class="regular-text" />
        <?php
    }

    public static function field_cloudfront_private_key()
    {
        $opts = get_option(self::OPTION_KEY, []);
        $hasKey = !empty($opts['cloudfront_private_key']);
        ?>
        <textarea id="cloudfront_private_key"
                  name="<?php echo esc_attr(self::OPTION_KEY); ?>[cloudfront_private_key]"
                  rows="5" cols="50"
                  class="large-text code"
                  placeholder="<?php echo $hasKey ? 'Stored encrypted.' : '-----BEGIN RSA PRIVATE KEY-----'; ?>"></textarea>
        <?php
    }

    public static function field_email_sender()
    {
        $opts = get_option(self::OPTION_KEY, []);
        $value = $opts['email_sender'] ?? get_option('admin_email');
        ?>
        <input type="email" id="email_sender"
               name="<?php echo esc_attr(self::OPTION_KEY); ?>[email_sender]"
               value="<?php echo esc_attr($value); ?>"
               class="regular-text" />
        <?php
    }

    public static function field_webhook_secret()
    {
        $opts = get_option(self::OPTION_KEY, []);
        $hasKey = !empty($opts['webhook_secret']);
        ?>
        <input type="password" id="webhook_secret"
               name="<?php echo esc_attr(self::OPTION_KEY); ?>[webhook_secret]"
               value=""
               placeholder="<?php echo $hasKey ? '••••••••' : ''; ?>"
               class="regular-text" />
        <button type="button" class="button" id="ap-validate-webhook">Validate</button>
        <?php
    }

    public static function field_require_otp()
    {
        $opts = get_option(self::OPTION_KEY, []);
        $value = !empty($opts['require_otp']);
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[require_otp]"
                   value="1" <?php checked($value); ?> />
            Require email OTP for downloads
        </label>
        <?php
    }

    public static function field_theme_overrides()
    {
        $opts = get_option(self::OPTION_KEY, []);
        $value = !empty($opts['theme_overrides']);
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[theme_overrides]"
                   value="1" <?php checked($value); ?> />
            Enable theme overrides
        </label>
        <?php
    }
}
