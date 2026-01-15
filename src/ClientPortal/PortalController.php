<?php

namespace AperturePro\ClientPortal;

class PortalController {

    public function __construct() {
        add_shortcode('aperture_client_portal', [$this, 'shortcode']);
    }

    public function shortcode($atts) {
        ob_start();
        PortalRenderer::render();
        return ob_get_clean();
    }
}
