<?php
namespace AperturePro\Admin;

use AperturePro\Helpers\Logger;
use AperturePro\Helpers\Crypto;

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
 *
 * Notes:
 *  - Sensitive values (cloud_api_key, webhook_secret) are encrypted before being persisted using Crypto::encrypt().
 *  - Decryption must be performed by consumers using Crypto::decrypt() when the secret is required at runtime.
 *  - AJAX endpoints are protected by capability checks and a nonce.
 */
class AdminUI
{
    const OPTION_KEY = 'aperture_pro_settings';
    const PAGE_SLUG = 'aperture-pro-settings';
    const NONCE_ACTION = 'aperture_pro_admin_nonce';

    /**
     * Initialize hooks.
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_action('wp_ajax_aperture_pro_test_api_key', [self::class, 'ajax_test_api_key']);
        add_action('wp_ajax_aperture_pro_validate_webhook', [self::class, 'ajax_validate_webhook']);
    }

    /**
     * Register top-level menu and settings submenu.
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
            'Settings',
            'Settings',
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render_settings_page']
        );
    }

    /**
     * Simple overview page.
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
     * Register settings and fields using the Settings API.
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

    /**
     * Sanitize and persist options.
     *
     * Sensitive fields are encrypted before saving.
     *
     * @param array $input
     * @return array
     */
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

        // Cloud API key (encrypt before storing)
        $rawApiKey = isset($input['cloud_api_key']) ? sanitize_text_field($input['cloud_api_key']) : '';
        if (!empty($rawApiKey)) {
            try {
                $encrypted = Crypto::encrypt($rawApiKey);
                $out['cloud_api_key'] = $encrypted;
            } catch (\Throwable $e) {
                // If encryption fails, do not store the raw key; log and return empty
                Logger::log('error', 'admin', 'Failed to encrypt cloud API key: ' . $e->getMessage(), ['notify_admin' => true]);
                $out['cloud_api_key'] = '';
            }
        } else {
            // Preserve existing encrypted key if input is empty? For safety, we clear only if explicitly empty.
            $existing = get_option(self::OPTION_KEY, []);
            $out['cloud_api_key'] = $existing['cloud_api_key'] ?? '';
        }
// S3 bucket
$out['s3_bucket'] = isset($input['s3_bucket'])
    ? sanitize_text_field($input['s3_bucket'])
    : '';

// S3 region
$out['s3_region'] = isset($input['s3_region'])
    ? sanitize_text_field($input['s3_region'])
    : '';

// S3 access key (encrypted)
if (!empty($input['s3_access_key'])) {
    $out['s3_access_key'] = Crypto::encrypt(
        sanitize_text_field($input['s3_access_key'])
    );
} else {
    $existing = get_option(self::OPTION_KEY, []);
    $out['s3_access_key'] = $existing['s3_access_key'] ?? '';
}

// S3 secret key (encrypted)
if (!empty($input['s3_secret_key'])) {
    $out['s3_secret_key'] = Crypto::encrypt(
        sanitize_text_field($input['s3_secret_key'])
    );
} else {
    $existing = get_option(self::OPTION_KEY, []);
    $out['s3_secret_key'] = $existing['s3_secret_key'] ?? '';
}

// CloudFront domain
$out['cloudfront_domain'] = isset($input['cloudfront_domain'])
    ? esc_url_raw($input['cloudfront_domain'])
    : '';

// CloudFront key pair ID
$out['cloudfront_key_pair_id'] = isset($input['cloudfront_key_pair_id'])
    ? sanitize_text_field($input['cloudfront_key_pair_id'])
    : '';

// CloudFront private key (encrypted)
if (!empty($input['cloudfront_private_key'])) {
    $out['cloudfront_private_key'] = Crypto::encrypt(
        trim($input['cloudfront_private_key'])
    );
} else {
    $existing = get_option(self::OPTION_KEY, []);
    $out['cloudfront_private_key'] = $existing['cloudfront_private_key'] ?? '';
}

        // Email sender
        $out['email_sender'] = isset($input['email_sender']) ? sanitize_email($input['email_sender']) : get_option('admin_email');

        // Webhook secret (encrypt before storing)
        $rawWebhook = isset($input['webhook_secret']) ? sanitize_text_field($input['webhook_secret']) : '';
        if (!empty($rawWebhook)) {
            try {
                $out['webhook_secret'] = Crypto::encrypt($rawWebhook);
            } catch (\Throwable $e) {
                Logger::log('error', 'admin', 'Failed to encrypt webhook secret: ' . $e->getMessage(), ['notify_admin' => true]);
                $out['webhook_secret'] = '';
            }
        } else {
            $existing = get_option(self::OPTION_KEY, []);
            $out['webhook_secret'] = $existing['webhook_secret'] ?? '';
        }

        // Require OTP
        $out['require_otp'] = !empty($input['require_otp']) ? 1 : 0;

        // Theme overrides
        $out['theme_overrides'] = !empty($input['theme_overrides']) ? 1 : 0;

        // Log non-sensitive metadata for audit
        Logger::log('info', 'admin_settings', 'Aperture Pro settings updated', [
            'storage_driver' => $out['storage_driver'],
            'cloud_provider' => $out['cloud_provider'],
            'require_otp' => $out['require_otp'],
            'theme_overrides' => $out['theme_overrides'],
        ]);

        return $out;
    }

    /**
     * Enqueue admin assets only on plugin pages.
     *
     * @param string $hook
     */
    public static function enqueue_assets($hook)
    {
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

    /**
     * Render the settings page.
     */
    public static function render_settings_page()
    {
        $opts = get_option(self::OPTION_KEY, []);
        include __DIR__ . '/../../templates/admin/settings-page.php';
    }

    /**
     * AJAX: test API key (placeholder).
     *
     * Security: requires manage_options and nonce.
     */
    /**
 * S3: Bucket
 */
public static function field_s3_bucket()
{
    $opts = get_option(self::OPTION_KEY, []);
    $bucket = $opts['s3_bucket'] ?? '';
    ?>
    <input type="text"
           id="s3_bucket"
           name="<?php echo esc_attr(self::OPTION_KEY); ?>[s3_bucket]"
           value="<?php echo esc_attr($bucket); ?>"
           class="regular-text" />
    <span class="ap-tooltip" title="Your S3 bucket name. Must already exist.">?</span>
    <p class="description">Example: <code>my-studio-photos</code></p>
    <?php
}

/**
 * S3: Region
 */
public static function field_s3_region()
{
    $opts = get_option(self::OPTION_KEY, []);
    $region = $opts['s3_region'] ?? '';
    ?>
    <input type="text"
           id="s3_region"
           name="<?php echo esc_attr(self::OPTION_KEY); ?>[s3_region]"
           value="<?php echo esc_attr($region); ?>"
           class="regular-text" />
    <span class="ap-tooltip" title="AWS region where your bucket lives.">?</span>
    <p class="description">Example: <code>us-east-1</code></p>
    <?php
}

/**
 * S3: Access Key (encrypted)
 */
public static function field_s3_access_key()
{
    $opts = get_option(self::OPTION_KEY, []);
    $hasKey = !empty($opts['s3_access_key']);
    ?>
    <input type="password"
           id="s3_access_key"
           name="<?php echo esc_attr(self::OPTION_KEY); ?>[s3_access_key]"
           value=""
           class="regular-text"
           placeholder="<?php echo $hasKey ? '••••••••' : ''; ?>"
           autocomplete="new-password" />
    <span class="ap-tooltip" title="AWS IAM Access Key with PutObject/GetObject permissions.">?</span>
    <p class="description">Stored encrypted. Use a dedicated IAM user with minimal permissions.</p>
    <?php
}

/**
 * S3: Secret Key (encrypted)
 */
public static function field_s3_secret_key()
{
    $opts = get_option(self::OPTION_KEY, []);
    $hasKey = !empty($opts['s3_secret_key']);
    ?>
    <input type="password"
           id="s3_secret_key"
           name="<?php echo esc_attr(self::OPTION_KEY); ?>[s3_secret_key]"
           value=""
           class="regular-text"
           placeholder="<?php echo $hasKey ? '••••••••' : ''; ?>"
           autocomplete="new-password" />
    <span class="ap-tooltip" title="AWS IAM Secret Key. Stored encrypted.">?</span>
    <?php
}

/**
 * CloudFront: Domain
 */
public static function field_cloudfront_domain()
{
    $opts = get_option(self::OPTION_KEY, []);
    $domain = $opts['cloudfront_domain'] ?? '';
    ?>
    <input type="text"
           id="cloudfront_domain"
           name="<?php echo esc_attr(self::OPTION_KEY); ?>[cloudfront_domain]"
           value="<?php echo esc_attr($domain); ?>"
           class="regular-text" />
    <span class="ap-tooltip" title="Your CloudFront distribution domain. Optional but recommended.">?</span>
    <p class="description">Example: <code>https://d123abcd.cloudfront.net</code></p>
    <?php
}

/**
 * CloudFront: Key Pair ID
 */
public static function field_cloudfront_key_pair_id()
{
    $opts = get_option(self::OPTION_KEY, []);
    $id = $opts['cloudfront_key_pair_id'] ?? '';
    ?>
    <input type="text"
           id="cloudfront_key_pair_id"
           name="<?php echo esc_attr(self::OPTION_KEY); ?>[cloudfront_key_pair_id]"
           value="<?php echo esc_attr($id); ?>"
           class="regular-text" />
    <span class="ap-tooltip" title="CloudFront Key Pair ID for signed URLs. Optional.">?</span>
    <?php
}

/**
 * CloudFront: Private Key (encrypted)
 */
public static function field_cloudfront_private_key()
{
    $opts = get_option(self::OPTION_KEY, []);
    $hasKey = !empty($opts['cloudfront_private_key']);
    ?>
    <textarea id="cloudfront_private_key"
              name="<?php echo esc_attr(self::OPTION_KEY); ?>[cloudfront_private_key]"
              class="large-text code"
              rows="6"
              placeholder="<?php echo $hasKey ? '•••••••• (stored encrypted)' : ''; ?>"></textarea>
    <span class="ap-tooltip" title="Paste your CloudFront private key (PEM). Stored encrypted.">?</span>
    <p class="description">Only required if you want signed CloudFront URLs.</p>
    <?php
}

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

        // Log failure for admin attention (do not log full key)
        Logger::log('warning', 'admin_api_test', 'API key test failed', ['provider' => $provider, 'key_preview' => substr($key, 0, 6) . '...']);
        wp_send_json_error(['message' => 'API key validation failed (simulated). Please verify the key and provider.'], 400);
    }

    /**
     * AJAX: validate webhook secret format (placeholder).
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

    /* -------------------------
     * Field rendering callbacks
     * ------------------------- */

    public static function field_storage_driver()
    {
        $opts = get_option(self::OPTION_KEY, []);
        $storage_driver = $opts['storage_driver'] ?? 'local';
        ?>
        <select id="storage_driver" name="<?php echo esc_attr(self::OPTION_KEY); ?>[storage_driver]">
            <option value="local" <?php selected($storage_driver, 'local'); ?>>Local (server)</option>
            <option value="cloudinary" <?php selected($storage_driver, 'cloudinary'); ?>>Cloudinary</option>
            <option value="imagekit" <?php selected($storage_driver, 'imagekit'); ?>>ImageKit</option>
        </select>
        <span class="ap-tooltip" title="Choose where files are stored. Local stores on your server; Cloudinary and ImageKit use cloud providers.">?</span>
        <?php
    }

    public static function field_local_storage_path()
    {
        $opts = get_option(self::OPTION_KEY, []);
        $local_storage_path = $opts['local_storage_path'] ?? '';
        ?>
        <input type="text" id="local_storage_path" name="<?php echo esc_attr(self::OPTION_KEY); ?>[local_storage_path]" value="<?php echo esc_attr($local_storage_path); ?>" class="regular-text" />
        <p class="description">Relative to WP uploads directory. Leave blank to use default uploads/aperture/</p>
        <?php
    }

    public static function field_cloud_provider()
    {
        $opts = get_option(self::OPTION_KEY, []);
        $cloud_provider = $opts['cloud_provider'] ?? 'none';
        ?>
        <select id="cloud_provider" name="<?php echo esc_attr(self::OPTION_KEY); ?>[cloud_provider]">
            <option value="none" <?php selected($cloud_provider, 'none'); ?>>None</option>
            <option value="cloudinary" <?php selected($cloud_provider, 'cloudinary'); ?>>Cloudinary</option>
            <option value="imagekit" <?php selected($cloud_provider, 'imagekit'); ?>>ImageKit</option>
        </select>
        <span class="ap-tooltip" title="If using a cloud provider, select it here and provide the API key below.">?</span>
        <?php
    }

    public static function field_cloud_api_key()
    {
        $opts = get_option(self::OPTION_KEY, []);
        // Do not decrypt and display the key in the admin UI for security.
        // Show a placeholder if a key exists.
        $hasKey = !empty($opts['cloud_api_key']);
        ?>
        <input type="password" id="cloud_api_key" name="<?php echo esc_attr(self::OPTION_KEY); ?>[cloud_api_key]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $hasKey ? '••••••••' : ''; ?>" />
        <button type="button" class="button" id="ap-test-api-key" data-provider="<?php echo esc_attr($opts['cloud_provider'] ?? 'cloudinary'); ?>">Test</button>
        <span id="ap-test-api-key-result" class="ap-test-result"></span>
        <p class="description">Enter your cloud provider API key. For security we do not display stored keys. Use Test to verify.</p>
        <?php
    }

    public static function field_email_sender()
    {
        $opts = get_option(self::OPTION_KEY, []);
        $email_sender = $opts['email_sender'] ?? get_option('admin_email');
        ?>
        <input type="email" id="email_sender" name="<?php echo esc_attr(self::OPTION_KEY); ?>[email_sender]" value="<?php echo esc_attr($email_sender); ?>" class="regular-text" />
        <p class="description">The 'From' address used for transactional emails (OTP, download links).</p>
        <?php
    }

    public static function field_webhook_secret()
    {
        $opts = get_option(self::OPTION_KEY, []);
        $hasSecret = !empty($opts['webhook_secret']);
        ?>
        <input type="password" id="webhook_secret" name="<?php echo esc_attr(self::OPTION_KEY); ?>[webhook_secret]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $hasSecret ? '••••••••' : ''; ?>" />
        <button type="button" class="button" id="ap-validate-webhook">Validate</button>
        <span id="ap-validate-webhook-result" class="ap-test-result"></span>
        <p class="description">This secret is used to verify incoming payment webhooks. Keep it private.</p>
        <?php
    }

    public static function field_require_otp()
    {
        $opts = get_option(self::OPTION_KEY, []);
        $require_otp = !empty($opts['require_otp']);
        ?>
        <label><input type="checkbox" id="require_otp" name="<?php echo esc_attr(self::OPTION_KEY); ?>[require_otp]" value="1" <?php checked($require_otp); ?> /> Enable OTP verification</label>
        <p class="description">When enabled, clients must verify a one-time code sent to their email before downloading final files.</p>
        <?php
    }

    public static function field_theme_overrides()
    {
        $opts = get_option(self::OPTION_KEY, []);
        $theme_overrides = !empty($opts['theme_overrides']);
        ?>
        <label><input type="checkbox" id="theme_overrides" name="<?php echo esc_attr(self::OPTION_KEY); ?>[theme_overrides]" value="1" <?php checked($theme_overrides); ?> /> Enable</label>
        <p class="description">When enabled, you can customize colors and spacing for the client portal from Settings → Aperture Portal Theme.</p>
        <?php
    }
}

// Initialize Admin UI
AdminUI::init();
