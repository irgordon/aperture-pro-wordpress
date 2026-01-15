<?php

namespace AperturePro\Admin;

class AdminUI {

    public function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function register_menu() {
        add_menu_page(
            'Client Galleries',
            'Client Galleries',
            'manage_options',
            'aperture-pro',
            [$this, 'render_project_list'],
            'dashicons-format-gallery',
            58
        );

        add_submenu_page(
            null,
            'Command Center',
            'Command Center',
            'manage_options',
            'aperture-pro-command',
            [$this, 'render_command_center']
        );
    }

    public function enqueue_assets($hook) {
        if (strpos($hook, 'aperture-pro') === false) {
            return;
        }

        wp_enqueue_script(
            'aperture-admin-command',
            plugins_url('../../assets/js/admin-command-center.js', __FILE__),
            ['wp-api', 'jquery'],
            '1.0',
            true
        );

        wp_localize_script('aperture-admin-command', 'ApertureAdmin', [
            'nonce' => wp_create_nonce('wp_rest'),
            'rest'  => esc_url_raw(rest_url('aperture/v1')),
        ]);
    }

    public function render_project_list() {
        include __DIR__ . '/../../templates/admin/project-list.php';
    }

    public function render_command_center() {
        include __DIR__ . '/../../templates/admin/command-center.php';
    }
}
