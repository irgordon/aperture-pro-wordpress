<?php

namespace AperturePro\ClientPortal;

class PortalRenderer {

    public static function render() {
        wp_enqueue_style(
            'aperture-portal',
            plugins_url('../../assets/css/portal.css', __FILE__),
            [],
            '1.0'
        );

        wp_enqueue_script(
            'aperture-portal',
            plugins_url('../../assets/js/portal-app.js', __FILE__),
            ['wp-element'],
            '1.0',
            true
        );

        wp_localize_script('aperture-portal', 'AperturePortal', [
            'rest'  => esc_url_raw(rest_url('aperture/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
        ]);

        echo '<div id="aperture-portal-root"></div>';
    }
}
