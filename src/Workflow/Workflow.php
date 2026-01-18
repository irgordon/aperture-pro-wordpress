<?php

namespace AperturePro\Workflow;

use AperturePro\Auth\MagicLinkService;
use AperturePro\Helpers\Logger;
use AperturePro\Payments\PaymentService;
use AperturePro\Proofing\ProofQueue;

class Workflow
{
    /**
     * Get current project status.
     * Returns null if project does not exist.
     */
    public static function getProjectStatus(int $projectId): ?string
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ap_projects';

        $status = $wpdb->get_var(
            $wpdb->prepare("SELECT status FROM {$table} WHERE id = %d", $projectId)
        );

        return $status !== null ? (string) $status : null;
    }

    /**
     * Handle project creation.
     * Idempotent: safe to call multiple times.
     */
    public static function onProjectCreated(int $projectId, int $clientId): void
    {
        global $wpdb;
        $galleries = $wpdb->prefix . 'ap_galleries';

        // Idempotency guard: ensure proof gallery does not already exist
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$galleries} WHERE project_id = %d AND type = %s LIMIT 1",
                $projectId,
                'proof'
            )
        );

        if (!$existing) {
            $wpdb->insert($galleries, [
                'project_id' => $projectId,
                'type'       => 'proof',
                'status'     => 'awaiting_proofs',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ]);

            if ($wpdb->last_error) {
                Logger::log('error', 'workflow', 'Failed to create proof gallery', [
                    'project_id' => $projectId,
                    'error'      => $wpdb->last_error,
                ]);
                return;
            }
        }

        // Generate magic link (idempotent by design)
        $token = MagicLinkService::create($projectId, $clientId);

        do_action('aperture_pro_email_project_created', $projectId, $clientId, $token);

        Logger::log('info', 'workflow', 'Project created', [
            'project_id' => $projectId,
            'client_id'  => $clientId,
        ]);
    }

    /**
     * Handle proof approval.
     */
    public static function onProofsApproved(int $projectId, int $galleryId): void
    {
        global $wpdb;
        $projects = $wpdb->prefix . 'ap_projects';

        $currentStatus = self::getProjectStatus($projectId);

        // Guard against invalid transitions
        if ($currentStatus !== 'proofing') {
            Logger::log('warning', 'workflow', 'Proof approval ignored due to invalid state', [
                'project_id' => $projectId,
                'status'     => $currentStatus,
            ]);
            return;
        }

        $wpdb->update(
            $projects,
            [
                'status'     => 'editing',
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $projectId],
            ['%s', '%s'],
            ['%d']
        );

        if ($wpdb->last_error) {
            Logger::log('error', 'workflow', 'Failed to update project status after proof approval', [
                'project_id' => $projectId,
                'error'      => $wpdb->last_error,
            ]);
            return;
        }

        do_action('aperture_pro_email_proofs_approved', $projectId, $galleryId);

        Logger::log('info', 'workflow', 'Proofs approved', [
            'project_id' => $projectId,
            'gallery_id' => $galleryId,
        ]);
    }

    /**
     * Handle payment receipt.
     *
     * NOTE: PaymentService is the source of truth for payment state.
     */
    public static function onPaymentReceived(int $projectId): void
    {
        // Delegate payment validation to PaymentService
        if (!PaymentService::isPaid($projectId)) {
            Logger::log('warning', 'workflow', 'Payment received event ignored (not fully paid)', [
                'project_id' => $projectId,
            ]);
            return;
        }

        global $wpdb;
        $projects = $wpdb->prefix . 'ap_projects';

        $wpdb->update(
            $projects,
            [
                'status'     => 'pending_proofs',
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $projectId],
            ['%s', '%s'],
            ['%d']
        );

        if ($wpdb->last_error) {
            Logger::log('error', 'workflow', 'Failed to update project after payment', [
                'project_id' => $projectId,
                'error'      => $wpdb->last_error,
            ]);
            return;
        }

        do_action('aperture_pro_payment_received', $projectId);

        Logger::log('info', 'workflow', 'Payment processed', [
            'project_id' => $projectId,
        ]);
    }

    /**
     * Generate a download token for final gallery.
     *
     * TODO: Replace transient-based tokens with ap_download_tokens table.
     */
    public static function generateDownloadTokenForProject(int $projectId): ?string
    {
        global $wpdb;
        $galleries = $wpdb->prefix . 'ap_galleries';

        $galleryId = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$galleries} WHERE project_id = %d AND type = %s LIMIT 1",
                $projectId,
                'final'
            )
        );

        if (!$galleryId) {
            Logger::log('warning', 'workflow', 'No final gallery found for download token', [
                'project_id' => $projectId,
            ]);
            return null;
        }

        $token = bin2hex(random_bytes(32));

        set_transient(
            "ap_download_{$token}",
            [
                'gallery_id' => $galleryId,
                'project_id' => $projectId,
                'created_at' => time(),
            ],
            2 * HOUR_IN_SECONDS
        );

        Logger::log('info', 'workflow', 'Download token generated', [
            'project_id' => $projectId,
            'gallery_id' => $galleryId,
        ]);

        return $token;
    }
}
