<?php
/**
 * Branding Settings for Social Media URLs
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_init', function () {

    add_settings_section(
        'aperture_branding_section',
        'Aperture Branding Settings',
        '__return_false',
        'general'
    );

    $fields = [
        'aperture_brand_facebook'  => 'Facebook URL',
        'aperture_brand_instagram' => 'Instagram URL',
        'aperture_brand_twitter'   => 'Twitter/X URL',
    ];

    foreach ($fields as $key => $label) {
        register_setting('general', $key, ['type' => 'string', 'sanitize_callback' => 'esc_url_raw']);

        add_settings_field(
            $key,
            $label,
            function () use ($key) {
                $value = esc_url( get_option($key, '') );
                echo '<input type="url" name="' . esc_attr($key) . '" value="' . $value . '" class="regular-text" />';
            },
            'general',
            'aperture_branding_section'
        );
    }
});
