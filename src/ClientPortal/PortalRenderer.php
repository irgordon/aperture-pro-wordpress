<?php

namespace AperturePro\ClientPortal;

use AperturePro\Storage\StorageFactory;
use AperturePro\Auth\CookieService;
use AperturePro\Helpers\Utils;
use AperturePro\Helpers\Logger;
use AperturePro\Repositories\ProjectRepository;

/**
 * PortalRenderer
 *
 * Server-side renderer for the client portal. Loads templates from templates/client/.
 * Provides data context to templates: project, client, gallery, images, photographer notes, payment status.
 *
 * Notes:
 *  - This class performs safe DB queries and sanitizes outputs before including templates.
 *  - Templates are plain PHP files that expect a $context array.
 */
class PortalRenderer
{
    protected string $templateDir;

    public function __construct()
    {
        $this->templateDir = plugin_dir_path(__DIR__ . '/../../') . 'templates/client/';
    }

    /**
     * Render the full portal HTML for a given project id.
     *
     * @param int $projectId
     * @return string HTML
     */
    public function renderPortal(int $projectId = 0): string
    {
        $context = $this->gatherContext($projectId);

        // Load main portal template
        $tpl = $this->templateDir . 'portal.php';
        if (!file_exists($tpl)) {
            Logger::log('error', 'client_portal', 'Portal template missing', ['template' => $tpl, 'notify_admin' => true]);
            return '<div class="ap-portal-error">Portal template missing.</div>';
        }

        // Capture output
        ob_start();
        include $tpl;
        return ob_get_clean();
    }

    /**
     * Gather data context for templates.
     */
    protected function gatherContext(int $projectId): array
    {
        global $wpdb;

        $context = [
            'project' => null,
            'client' => null,
            'gallery' => null,
            'images' => [],
            'photographer_notes' => null,
            'payment_status' => null,
            'session' => CookieService::getClientSession(),
            'health' => get_transient('ap_health_items') ?: [],
            'messages' => [],
        ];

        if ($projectId <= 0) {
            $context['messages'][] = 'No project selected.';
            return $context;
        }

        // Fetch project
        $projectRepo = new ProjectRepository();
        $project = $projectRepo->find($projectId);

        if (!$project) {
            $context['messages'][] = 'Project not found.';
            return $context;
        }

        $context['project'] = $this->sanitizeProjectRow($project);

        // Fetch client
        $clientsTable = $wpdb->prefix . 'ap_clients';
        $client = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$clientsTable} WHERE id = %d LIMIT 1", (int)$project->client_id));
        if ($client) {
            $context['client'] = [
                'id' => (int)$client->id,
                'name' => esc_html($client->name),
                'email' => esc_html($client->email),
                'phone' => esc_html($client->phone),
            ];
        }

        // Photographer notes and payment status (fields may not exist in older schemas)
        $context['photographer_notes'] = property_exists($project, 'photographer_notes') ? esc_html($project->photographer_notes) : null;
        $context['payment_status'] = property_exists($project, 'payment_status') ? esc_html($project->payment_status) : null;

        // Determine proof gallery
        $galleriesTable = $wpdb->prefix . 'ap_galleries';
        $gallery = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$galleriesTable} WHERE project_id = %d AND type = %s LIMIT 1", $projectId, 'proof'));
        if ($gallery) {
            $context['gallery'] = [
                'id' => (int)$gallery->id,
                'status' => esc_html($gallery->status),
                'created_at' => esc_html($gallery->created_at),
            ];

            // Fetch images
            $imagesTable = $wpdb->prefix . 'ap_images';
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$imagesTable} WHERE gallery_id = %d ORDER BY sort_order ASC, id ASC", (int)$gallery->id));
            $storage = StorageFactory::make();

            // Collect paths once
            $paths = array_map(static fn ($r) => $r->storage_key_original, $rows);

            // Batch sign (request + cross-request cached)
            $signedUrls = $storage->signMany($paths);

            foreach ($rows as $r) {
                $comments = [];
                if (!empty($r->client_comments)) {
                    if ($r->client_comments === '[]') {
                        // Optimization: Skip expensive json_decode for empty array
                    } else {
                        $decoded = json_decode($r->client_comments, true);
                        if (is_array($decoded)) {
                            $comments = $decoded;
                        }
                    }
                }

                $url = $signedUrls[$r->storage_key_original] ?? null;

                $context['images'][] = [
                    'id' => (int)$r->id,
                    'url' => $url,
                    'is_selected' => (bool)$r->is_selected,
                    'comments' => $comments,
                ];
            }
        }

        // If final gallery exists, provide download link if payment ok
        $finalGalleryId = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$galleriesTable} WHERE project_id = %d AND type = %s LIMIT 1", $projectId, 'final'));
        if ($finalGalleryId) {
            // If payment_status exists and is not 'paid', block download and show alert
            if ($context['payment_status'] && strtolower($context['payment_status']) !== 'paid') {
                $context['messages'][] = 'Payment is required before final delivery. Please contact your photographer or complete payment to download final files.';
            } else {
                // Attempt to find an existing download token (DB)
                $downloadsTable = $wpdb->prefix . 'ap_download_tokens';
                $row = $wpdb->get_row($wpdb->prepare("SELECT token, expires_at FROM {$downloadsTable} WHERE project_id = %d ORDER BY id DESC LIMIT 1", $projectId));
                if ($row) {
                    $context['download'] = [
                        'url' => add_query_arg('ap_download', $row->token, home_url('/')),
                        'expires_at' => $row->expires_at,
                    ];
                } else {
                    $context['download'] = null;
                }
            }
        }

        return $context;
    }

    protected function sanitizeProjectRow($project): array
    {
        return [
            'id' => (int)$project->id,
            'title' => esc_html($project->title),
            'status' => esc_html($project->status),
            'session_date' => esc_html($project->session_date),
            'created_at' => esc_html($project->created_at),
            'updated_at' => esc_html($project->updated_at),
        ];
    }
}
