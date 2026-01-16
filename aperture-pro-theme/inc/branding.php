<?php
/**
 * Branding Settings for Social Media URLs
 *
 * This file registers social media URL fields in:
 *   Settings → General
 *
 * These values are used in:
 *   - footer.html (inline SVG social icons)
 *   - enqueue.php (localized SPA config)
 *
 * All values are sanitized and stored using WordPress options.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ---------------------------------------------------------
 * REGISTER BRANDING FIELDS IN SETTINGS → GENERAL
 * ---------------------------------------------------------
 */
add_action('admin_init', function () {

    // Add a section header (no description callback needed)
    add_settings_section(
        'aperture_branding_section',
        'Aperture Branding Settings',
        '__return_false',
        'general'
    );

    /**
     * Social media fields to register.
     * Key = option name
     * Label = field label in admin
     */
    $fields = [
        'aperture_brand_facebook'  => 'Facebook URL',
        'aperture_brand_instagram' => 'Instagram URL',
        'aperture_brand_twitter'   => 'Twitter/X URL',
    ];

    foreach ($fields as $key => $label) {

        // Register the option
        register_setting('general', $key, [
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default'           => '',
        ]);

        // Add the field to the General Settings page
        add_settings_field(
            $key,
            $label,
            function () use ($key) {
                $value = esc_url(get_option($key, ''));
                echo '<input type="url" name="' . esc_attr($key) . '" value="' . $value . '" class="regular-text" />';
            },
            'general',
            'aperture_branding_section'
        );
    }
});
