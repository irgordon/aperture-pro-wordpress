<?php
/**
 * Plugin Name: Aperture Pro
 */

use AperturePro\Installer\Activator;

register_activation_hook(__FILE__, [Activator::class, 'activate']);
register_uninstall_hook(__FILE__, 'aperture_pro_uninstall');

function aperture_pro_uninstall() {
    require_once __DIR__ . '/uninstall.php';
}
