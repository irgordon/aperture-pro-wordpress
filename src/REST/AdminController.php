<?php

namespace AperturePro\REST;

use WP_REST_Request;
use AperturePro\Config\Config;
use AperturePro\Storage\StorageFactory;
use AperturePro\Helpers\Logger;
use AperturePro\Health\HealthService;
use AperturePro\Workflow\Workflow;
use AperturePro\Email\EmailService;

/**
 * AdminController
 *
 * Admin-only endpoints for project creation, download link generation, logs, and health.
 * All endpoints use with_error_boundary and surface critical issues to Health Card and admin queue.
 */
class AdminController extends BaseController
{
    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/admin/projects', [
            'methods'             => 'POST',
            'callback'            => [$this, 'create_project'],
            'permission_callback' => [$this, 'require_admin'],
        ]);

        register_rest_route($this->namespace, '/admin/projects/(?P<project_id>\d+)/generate-download-link', [
            'methods'             => 'POST',
            'callback'            => [$this, 'generate_download_link'],
            'permission_callback' => [$this, 'require_admin'],
        ]);

        register_rest_route($this->namespace, '/admin/logs', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_logs'],
            'permission_callback' => [$this, 'require_admin'],
        ]);

        register_rest_route($this->namespace, '/admin/health-check', [
            'methods'             => 'GET',
            'callback'            => [$this, 'health_check'],
            'permission_callback' => [$this, 'require_admin'],
        ]);

        register_rest_route($this->namespace, '/admin/health-metrics', [
            'methods'             => 'GET',
            'callback'            => [$this, 'health_metrics'],
            'permission_callback' => [$this, 'require_admin'],
        ]);
    }

    public function require_admin(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Create a project and client if needed.
     */
    public function create_project(WP_REST_Request $request)
    {
        return $this->with_error_boundary(function () use ($request) {
            $client      = $request->get_param('client');
            $title       = sanitize_text_field((string) $request->get_param('title'));
            $sessionDate = sanitize_text_field((string) $request->get_param('session_date'));

            if (!is_array($client)) {
                return $this->respond_error('invalid_input', 'Client payload is required.', 400);
            }

            $email = sanitize_email((string) ($client['email'] ?? ''));
            $name  = sanitize_text_field((string) ($client['name'] ?? ''));
            $phone = sanitize_text_field((string) ($client['phone'] ?? ''));

            if ($email === '' || $title === '') {
                return $this->respond_error('invalid_input', 'Client email and title are required.', 400);
            }

            global $wpdb;

            $clientsTable  = $wpdb->prefix . 'ap_clients';
            $projectsTable = $wpdb->prefix . 'ap_projects';

            $wpdb->query('START TRANSACTION');

            try {
                $clientRow = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT * FROM {$clientsTable} WHERE email = %s LIMIT 1",
                        $email
                    )
                );

                if (!$clientRow) {
                    $inserted = $wpdb->insert(
                        $clientsTable,
                        [
                            'name'       => $name,
                            'email'      => $email,
                            'phone'      => $phone,
                            'created_at' => current_time('mysql'),
                        ],
                        [
                            '%s',
                            '%s',
                            '%s',
                            '%s',
                        ]
                    );

                    if ($inserted === false) {
                        throw new \RuntimeException('Failed to insert client row.');
                    }

                    $clientId = (int) $wpdb->insert_id;
                } else {
                    $clientId = (int) $clientRow->id;
                }

                $insertedProject = $wpdb->insert(
                    $projectsTable,
                    [
                        'client_id'    => $clientId,
                        'title'        => $title,
                        'status'       => 'booked',
                        'session_date' => $sessionDate !== '' ? $sessionDate : null,
                        'created_at'   => current_time('mysql'),
                        'updated_at'   => current_time('mysql'),
                    ],
                    [
                        '%d',
                        '%s',
                        '%s',
                        '%s',
                        '%s',
                        '%s',
                    ]
                );

                if ($insertedProject === false) {
                    throw new \RuntimeException('Failed to insert project row.');
                }

                $projectId = (int) $wpdb->insert_id;

                Workflow::onProjectCreated($projectId, $clientId);

                $wpdb->query('COMMIT');

                Logger::log(
                    'info',
                    'admin',
                    'Project created',
                    [
                        'project_id' => $projectId,
                        'client_id'  => $clientId,
                    ]
                );

                return $this->respond_success([
                    'project_id' => $projectId,
                    'client_id'  => $clientId,
                ]);
            } catch (\Throwable $e) {
                $wpdb->query('ROLLBACK');

                Logger::log(
                    'error',
                    'admin',
                    'Create project failed: ' . $e->getMessage(),
                    [
                        'trace' => $e->getTraceAsString(),
                        'notify_admin' => true,
                    ]
                );

                EmailService::enqueueAdminNotification('error', 'admin_create_project', 'Project creation failed', ['error' => $e->getMessage()]);

                return $this->respond_error('create_failed', 'Could not create project.', 500);
            }
        }, ['endpoint' => 'admin_create_project']);
    }

    /**
     * Generate a download token for a project (admin-triggered).
     * Accepts optional email to bind the token to a recipient.
     */
    public function generate_download_link(WP_REST_Request $request)
    {
        return $this->with_error_boundary(function () use ($request) {
            $projectId = (int) $request['project_id'];
            $email = sanitize_email((string) $request->get_param('email'));

            if ($projectId <= 0) {
                return $this->respond_error('invalid_input', 'Invalid project id.', 400);
            }

            global $wpdb;
            $downloadsTable = $wpdb->prefix . 'ap_download_tokens';

            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + (7 * 24 * 3600)); // 7 days

            $galleryId = $this->get_final_gallery_id_for_project($projectId);

            $inserted = $wpdb->insert(
                $downloadsTable,
                [
                    'gallery_id' => $galleryId,
                    'project_id' => $projectId,
                    'token'      => $token,
                    'expires_at' => $expiresAt,
                    'created_at' => current_time('mysql'),
                    'email'      => $email,
                ],
                ['%d', '%d', '%s', '%s', '%s', '%s']
            );

            if ($inserted === false) {
                Logger::log('error', 'admin', 'Failed to persist download token', ['project_id' => $projectId, 'notify_admin' => true]);
                return $this->respond_error('token_failed', 'Could not generate download link.', 500);
            }

            // Set transient for quick lookup
            $transientKey = 'ap_download_' . $token;
            $payload = [
                'gallery_id' => $galleryId,
                'project_id' => $projectId,
                'email'      => $email,
                'created_at' => time(),
                'expires_at' => strtotime($expiresAt),
            ];
            set_transient($transientKey, $payload, 7 * 24 * 3600);

            $url = add_query_arg('ap_download', $token, home_url('/'));

            Logger::log('info', 'admin', 'Download link generated', ['project_id' => $projectId, 'token' => $token]);

            return $this->respond_success([
                'download_url' => $url,
                'expires_at'   => $expiresAt,
            ]);
        }, ['endpoint' => 'admin_generate_download_link']);
    }

    /**
     * Helper: find final gallery id for a project (first final gallery)
     */
    protected function get_final_gallery_id_for_project(int $projectId): ?int
    {
        global $wpdb;
        $galleries = $wpdb->prefix . 'ap_galleries';
        $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$galleries} WHERE project_id = %d AND type = %s LIMIT 1", $projectId, 'final'));
        return $id ? (int)$id : null;
    }

    /**
     * Return recent logs for admin UI.
     */
    public function get_logs(WP_REST_Request $request)
    {
        return $this->with_error_boundary(function () use ($request) {
            global $wpdb;

            $limit = (int) $request->get_param('limit');
            if ($limit <= 0 || $limit > 200) {
                $limit = 50;
            }

            $logsTable = $wpdb->prefix . 'ap_logs';

            $rows = $wpdb->get_results(
                "SELECT id, level, context, message, trace_id, meta, created_at
                 FROM {$logsTable}
                 ORDER BY id DESC
                 LIMIT {$limit}"
            );

            $data = [];
            foreach ($rows as $row) {
                $meta = null;
                if (!empty($row->meta)) {
                    $decoded = json_decode($row->meta, true);
                    if (is_array($decoded)) {
                        $meta = $decoded;
                    }
                }

                $data[] = [
                    'id'         => (int) $row->id,
                    'level'      => (string) $row->level,
                    'context'    => (string) $row->context,
                    'message'    => (string) $row->message,
                    'trace_id'   => $row->trace_id ? (string) $row->trace_id : null,
                    'meta'       => $meta,
                    'created_at' => (string) $row->created_at,
                ];
            }

            return $this->respond_success($data);
        }, ['endpoint' => 'admin_get_logs']);
    }

    /**
     * Health check endpoint used by Admin Command Center.
     * Includes storage, DB tables, config, and upload watchdog health.
     */
    public function health_check(WP_REST_Request $request)
    {
        return $this->with_error_boundary(function () {
            $results = HealthService::check();
            return $this->respond_success($results);
        }, ['endpoint' => 'admin_health_check']);
    }

    /**
     * Metrics endpoint used by Admin Dashboard (Performance/Storage cards).
     */
    public function health_metrics(WP_REST_Request $request)
    {
        return $this->with_error_boundary(function () {
            $metrics = HealthService::getMetrics();
            return $this->respond_success($metrics);
        }, ['endpoint' => 'admin_health_metrics']);
    }
}
