<?php
namespace AperturePro\Admin;

use AperturePro\Helpers\Logger;

/**
 * ThemeVariables
 *
 * Small admin settings page to customize CSS variables used by the client portal.
 *
 * Usage:
 *  - Include this file in plugin bootstrap (or autoload).
 *  - It registers a Settings page under Settings -> Aperture Portal Theme.
 *  - It prints a <style> block in wp_head with the saved variables.
 */

class ThemeVariables
{
    const OPTION_KEY = 'ap_portal_theme_vars';
    const PAGE_SLUG = 'aperture-portal-theme';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_admin_page']);
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('wp_head', [self::class, 'print_frontend_overrides'], 5);
    }

    public static function register_admin_page(): void
    {
        add_options_page(
            'Aperture Portal Theme',
            'Aperture Portal Theme',
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render_admin_page']
        );
    }

    public static function register_settings(): void
    {
        register_setting('ap_portal_theme_group', self::OPTION_KEY, [self::class, 'sanitize_options']);

        add_settings_section(
            'ap_portal_theme_section',
            'Portal Theme Variables',
            function () {
                echo '<p>Customize the client portal appearance. Values are validated and applied site-wide.</p>';
            },
            self::PAGE_SLUG
        );

        $fields = self::get_fields();
        foreach ($fields as $key => $meta) {
            add_settings_field(
                $key,
                $meta['label'],
                function () use ($key, $meta) {
                    $opts = get_option(self::OPTION_KEY, []);
                    $value = $opts[$key] ?? $meta['default'];
                    $type = $meta['type'] ?? 'text';
                    printf(
                        '<input type="%s" id="%s" name="%s[%s]" value="%s" class="regular-text" />',
                        esc_attr($type),
                        esc_attr($key),
                        esc_attr(self::OPTION_KEY),
                        esc_attr($key),
                        esc_attr($value)
                    );
                    if (!empty($meta['help'])) {
                        printf('<p class="description">%s</p>', esc_html($meta['help']));
                    }
                },
                self::PAGE_SLUG,
                'ap_portal_theme_section'
            );
        }
    }

    public static function sanitize_options($input)
    {
        $fields = self::get_fields();
        $out = [];
        foreach ($fields as $key => $meta) {
            $val = $input[$key] ?? $meta['default'];
            switch ($meta['type']) {
                case 'color':
                    $out[$key] = self::sanitize_color($val, $meta['default']);
                    break;
                case 'number':
                    $out[$key] = intval($val);
                    break;
                case 'text':
                default:
                    $out[$key] = sanitize_text_field($val);
                    break;
            }
        }
        // Persist and log
        update_option(self::OPTION_KEY, $out, false);
        Logger::log('info', 'theme', 'Portal theme variables updated', ['vars' => $out]);
        return $out;
    }

    protected static function sanitize_color($val, $fallback)
    {
        $val = trim($val);
        if (preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $val)) {
            return $val;
        }
        // allow rgb() or rgba() minimally
        if (preg_match('/^rgba?\([0-9,\s\.%]+\)$/i', $val)) {
            return $val;
        }
        return $fallback;
    }

    public static function render_admin_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        ?>
        <div class="wrap">
            <h1>Aperture Portal Theme</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('ap_portal_theme_group');
                do_settings_sections(self::PAGE_SLUG);
                submit_button('Save Theme Variables');
                ?>
            </form>
        </div>
        <?php
    }

    public static function print_frontend_overrides()
    {
        $opts = get_option(self::OPTION_KEY, []);
        if (empty($opts) || !is_array($opts)) {
            return;
        }

        // Map option keys to CSS variables
        $map = [
            'bg' => '--ap-bg',
            'surface' => '--ap-surface',
            'card_bg' => '--ap-card-bg',
            'border' => '--ap-border',
            'text' => '--ap-text',
            'muted' => '--ap-muted',
            'primary' => '--ap-primary',
            'primary_contrast' => '--ap-primary-contrast',
            'success' => '--ap-success',
            'warning' => '--ap-warning',
            'danger' => '--ap-danger',
            'radius' => '--ap-radius',
            'radius_sm' => '--ap-radius-sm',
            'gap' => '--ap-gap',
            'max_width' => '--ap-max-width',
            'font_family' => '--ap-font-family',
        ];

        $css = ':root{';
        foreach ($map as $optKey => $varName) {
            if (!isset($opts[$optKey]) || $opts[$optKey] === '') {
                continue;
            }
            $val = $opts[$optKey];
            // numeric conversions for gap and max_width
            if (in_array($optKey, ['gap'])) {
                $val = intval($val) . 'px';
            } elseif ($optKey === 'max_width') {
                $val = intval($val) . 'px';
            } elseif ($optKey === 'font_family') {
                // allow comma-separated font stack
                $val = $val;
            }
            $css .= $varName . ':' . $val . ';';
        }
        $css .= '}';

        echo "<style id=\"ap-portal-theme-overrides\">\n" . $css . "\n</style>\n";
    }

    protected static function get_fields()
    {
        return [
            'bg' => ['label' => 'Background color', 'type' => 'color', 'default' => '#ffffff', 'help' => 'Page background color'],
            'surface' => ['label' => 'Surface color', 'type' => 'color', 'default' => '#f8f9fb', 'help' => 'Surface / card background'],
            'card_bg' => ['label' => 'Card background', 'type' => 'color', 'default' => '#ffffff'],
            'border' => ['label' => 'Border color', 'type' => 'color', 'default' => '#e6e9ee'],
            'text' => ['label' => 'Primary text color', 'type' => 'color', 'default' => '#1f2933'],
            'muted' => ['label' => 'Muted text color', 'type' => 'color', 'default' => '#6b7280'],
            'primary' => ['label' => 'Primary color', 'type' => 'color', 'default' => '#0b74de'],
            'primary_contrast' => ['label' => 'Primary contrast color', 'type' => 'color', 'default' => '#ffffff'],
            'success' => ['label' => 'Success color', 'type' => 'color', 'default' => '#16a34a'],
            'warning' => ['label' => 'Warning color', 'type' => 'color', 'default' => '#f59e0b'],
            'danger' => ['label' => 'Danger color', 'type' => 'color', 'default' => '#ef4444'],
            'radius' => ['label' => 'Border radius (px)', 'type' => 'number', 'default' => 8],
            'radius_sm' => ['label' => 'Small radius (px)', 'type' => 'number', 'default' => 6],
            'gap' => ['label' => 'Spacing gap (px)', 'type' => 'number', 'default' => 16],
            'max_width' => ['label' => 'Max container width (px)', 'type' => 'number', 'default' => 1200],
            'font_family' => ['label' => 'Font stack', 'type' => 'text', 'default' => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans"'],
        ];
    }
}

// Initialize
ThemeVariables::init();
