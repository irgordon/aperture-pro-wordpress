<?php

namespace AperturePro\ClientPortal;

use AperturePro\ClientPortal\PortalRenderer;
use AperturePro\Auth\CookieService;
use AperturePro\Helpers\Nonce;
use AperturePro\Helpers\Logger;

/**
 * PortalController
 *
 * Responsibilities:
 *  - Register a shortcode [aperture_portal] to render the client portal
 *  - Enqueue portal JS/CSS and localize REST base, nonce, and initial data
 *  - Provide a small helper endpoint for server-side rendered portal fragments (optional)
 *
 * Notes:
 *  - The shortcode accepts a project_id query param (e.g., /client?project_id=123)
 *  - If a client session exists (CookieService), the portal will prefill client info
 *  - All scripts are localized with a WP nonce 'aperture_pro' for REST calls
 */
class PortalController
{
    public static function init(): void
    {
        add_shortcode('aperture_portal', [self::class, 'shortcodePortal']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueueAssets']);
        add_action('rest_api_init', [self::class, 'register_server_render_route']);
        add_action('init', [self::class, 'serve_service_worker']);
    }

    /**
     * Shortcode handler: renders the portal via PortalRenderer.
     *
     * Usage: place [aperture_portal] on a page. Optionally pass ?project_id=123 in URL.
     */
    public static function shortcodePortal($atts = []): string
    {
        $projectId = isset($_GET['project_id']) ? (int) $_GET['project_id'] : 0;

        // Render server-side for first paint for SEO and accessibility
        try {
            $renderer = new PortalRenderer();
            return $renderer->renderPortal($projectId);
        } catch (\Throwable $e) {
            Logger::log('error', 'client_portal', 'Portal render failed: ' . $e->getMessage(), ['notify_admin' => true]);
            // Friendly fallback for clients
            return '<div class="ap-portal-error">We are experiencing technical difficulties. Please try again later or contact support.</div>';
        }
    }

    /**
     * Enqueue portal assets and localize REST base + nonce + initial session info.
     */
    public static function enqueueAssets(): void
    {
        // Only enqueue on pages where shortcode exists (simple heuristic)
        global $post;
        if (empty($post) || strpos($post->post_content ?? '', '[aperture_portal]') === false) {
            return;
        }

        $pluginUrl = plugin_dir_url(__DIR__ . '/../../'); // adjust as needed

        // Toast System
        wp_enqueue_style('ap-toast-css', $pluginUrl . 'assets/css/ap-toast.css', [], '1.0.0');
        wp_enqueue_script('ap-toast-js', $pluginUrl . 'assets/js/ap-toast.js', [], '1.0.0', true);

        // Modal Assets
        wp_enqueue_style('ap-modal-css', $pluginUrl . 'assets/css/ap-modal.css', [], '1.0.0');
        wp_enqueue_script('ap-modal-js', $pluginUrl . 'assets/js/ap-modal.js', [], '1.0.0', true);

        $cssUrl = $pluginUrl . 'assets/css/client-portal.css';
        $jsUrl = $pluginUrl . 'assets/js/client-portal.js';
        $appJsUrl = $pluginUrl . 'assets/js/portal-app.js';

        wp_enqueue_style('aperture-portal-css', $cssUrl, ['ap-modal-css'], '1.0.0');
        wp_enqueue_script('aperture-portal-js', $jsUrl, ['jquery', 'ap-modal-js'], '1.0.0', true);
        wp_enqueue_script('aperture-portal-app-js', $appJsUrl, ['aperture-portal-js'], '1.0.0', true);

        // Localize script with REST base, nonce, and initial session info
        $nonce = Nonce::create('aperture_pro');
        $session = CookieService::getClientSession();

        $initial = [
            'restBase' => rest_url('aperture/v1'),
            'nonce' => $nonce,
            'session' => $session ?: null,
            'swUrl' => site_url('?aperture_sw=1'),
            'strings' => [
                'loading' => 'Loading…',
                'no_project' => 'No project selected. If you were given a link, please open it again or contact your photographer.',
                'contact_support' => 'If you need help, reply to your photographer or contact support.',
            ],
        ];

        wp_localize_script('aperture-portal-js', 'ApertureClient', $initial);
        // Also localize for app js if needed, or it can access ApertureClient global since it depends on it (implicitly order-wise).
        // Since we didn't set dependency in wp_enqueue_script above, we should ensure order or add dependency.
        // Let's add dependency to be safe.
    }

    /**
     * Optional: register a server-render route to fetch portal HTML fragment via REST.
     * This can be used by client-side navigation to re-render portal content without full page reload.
     */
    public static function register_server_render_route(): void
    {
        register_rest_route('aperture/v1', '/client/portal', [
            'methods' => 'GET',
            'callback' => [self::class, 'restRenderPortal'],
            'permission_callback' => '__return_true',
            'args' => [
                'project_id' => ['required' => false],
            ],
        ]);
    }

    public static function restRenderPortal(\WP_REST_Request $request)
    {
        $projectId = (int) $request->get_param('project_id');
        try {
            $renderer = new PortalRenderer();
            $html = $renderer->renderPortal($projectId);
            return rest_ensure_response(['success' => true, 'html' => $html]);
        } catch (\Throwable $e) {
            Logger::log('error', 'client_portal', 'REST portal render failed: ' . $e->getMessage(), ['notify_admin' => true]);
            return rest_ensure_response(['success' => false, 'message' => 'Unable to render portal.']);
        }
    }

    /**
     * Serve the Service Worker with the correct Service-Worker-Allowed header to broaden its scope.
     * Also dynamically replaces asset paths with absolute URLs.
     */
    public static function serve_service_worker(): void
    {
        if (isset($_GET['aperture_sw'])) {
            header('Content-Type: application/javascript');
            header('Service-Worker-Allowed: /');

            $pluginDir = plugin_dir_path(__DIR__ . '/../../');
            $swPath = $pluginDir . 'assets/js/sw.js';

            if (file_exists($swPath)) {
                $content = file_get_contents($swPath);
                $pluginUrl = plugin_dir_url(__DIR__ . '/../../');

                // Replace relative paths with absolute URLs
                // sw.js has: './portal-app.js' and '../css/client-portal.css'
                $content = str_replace("'./portal-app.js'", "'" . $pluginUrl . "assets/js/portal-app.js'", $content);
                $content = str_replace("'../css/client-portal.css'", "'" . $pluginUrl . "assets/css/client-portal.css'", $content);
                // Handle double quotes fallback
                $content = str_replace('"./portal-app.js"', '"' . $pluginUrl . 'assets/js/portal-app.js"', $content);
                $content = str_replace('"../css/client-portal.css"', '"' . $pluginUrl . 'assets/css/client-portal.css"', $content);

                echo $content;
            }
            exit;
        }
    }
}

// Initialize on plugin load
PortalController::init();
