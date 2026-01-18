<?php

namespace AperturePro\Workflow;

use AperturePro\Auth\MagicLinkService;
use AperturePro\Helpers\Logger;

class Workflow {

    public static function getProjectStatus(int $projectId): string {
        global $wpdb;
        $table = $wpdb->prefix . 'ap_projects';

        return (string) $wpdb->get_var(
            $wpdb->prepare("SELECT status FROM $table WHERE id = %d", $projectId)
        );
    }

    public static function onProjectCreated(int $projectId, int $clientId): void {
        // Create initial proof gallery
        global $wpdb;
        $galleries = $wpdb->prefix . 'ap_galleries';

        $wpdb->insert($galleries, [
            'project_id' => $projectId,
            'type'       => 'proof',
            'status'     => 'awaiting_proofs',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);

        // Send magic link to client
        $token = MagicLinkService::create($projectId, $clientId);

        // Email event (stub)
        do_action('aperture_pro_email_project_created', $projectId, $clientId, $token);

        Logger::log('info', 'workflow', "Project created: $projectId");
    }

    public static function onProofsApproved(int $projectId, int $galleryId): void {
        // Move project to editing
        global $wpdb;
        $projects = $wpdb->prefix . 'ap_projects';

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

        // Notify photographer
        do_action('aperture_pro_email_proofs_approved', $projectId, $galleryId);

        Logger::log('info', 'workflow', "Proofs approved for project $projectId");
    }

    public static function onPaymentReceived(int $projectId): void {
        global $wpdb;
        $projects = $wpdb->prefix . 'ap_projects';

        // Update status if needed, e.g. from 'lead' to 'booked' or 'pending_proofs'
        // For now we just log and fire action
        Logger::log('info', 'workflow', "Payment received for project $projectId");

        do_action('aperture_pro_payment_received', $projectId);
    }

    public static function generateDownloadTokenForProject(int $projectId): ?string {
        global $wpdb;

        $galleries = $wpdb->prefix . 'ap_galleries';
        $galleryId = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $galleries WHERE project_id = %d AND type = %s LIMIT 1",
            $projectId,
            'final'
        ));

        if (!$galleryId) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        set_transient("ap_download_$token", [
            'gallery_id' => $galleryId,
            'project_id' => $projectId,
            'created_at' => time(),
        ], 7200);

        return $token;
    }
}
