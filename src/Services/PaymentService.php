<?php

namespace AperturePro\Services;

use AperturePro\Helpers\Logger;
use AperturePro\Email\EmailService;
use AperturePro\Storage\StorageFactory;

/**
 * PaymentService
 *
 * Minimal payment webhook processing. Designed to be provider-agnostic but uses HMAC signature verification.
 */
class PaymentService
{
    /**
     * Verify webhook signature using HMAC-SHA256.
     *
     * @param string $payload raw request body
     * @param string $signatureHeader header value from provider
     * @param string $secret configured webhook secret
     * @return bool
     */
    public static function verifySignature(string $payload, string $signatureHeader, string $secret): bool
    {
        if (empty($secret) || empty($signatureHeader)) {
            return false;
        }

        // Provider-specific: here we assume signatureHeader is hex HMAC of payload
        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signatureHeader);
    }

    /**
     * Process a webhook event payload (decoded JSON).
     *
     * @param array $event
     * @return array ['success'=>bool,'message'=>string]
     */
    public static function processEvent(array $event): array
    {
        global $wpdb;

        // Example event structure:
        // { "type": "payment_intent.succeeded", "data": { "object": { "metadata": { "project_id": "123", "client_email": "a@b.com" } } } }
        $type = $event['type'] ?? '';
        $data = $event['data']['object'] ?? [];

        try {
            if ($type === 'payment_intent.succeeded' || $type === 'charge.succeeded') {
                $metadata = $data['metadata'] ?? [];
                $projectId = isset($metadata['project_id']) ? (int)$metadata['project_id'] : null;
                $email = isset($metadata['client_email']) ? sanitize_email($metadata['client_email']) : null;

                if (!$projectId) {
                    Logger::log('warning', 'payment', 'Webhook missing project_id in metadata', ['event' => $event]);
                    return ['success' => false, 'message' => 'Missing project_id'];
                }

                // Update project payment_status
                $projectsTable = $wpdb->prefix . 'ap_projects';
                $updated = $wpdb->update($projectsTable, ['payment_status' => 'paid', 'updated_at' => current_time('mysql')], ['id' => $projectId], ['%s', '%s'], ['%d']);
                if ($updated === false) {
                    Logger::log('error', 'payment', 'Failed to update project payment_status', ['project_id' => $projectId, 'notify_admin' => true]);
                    return ['success' => false, 'message' => 'DB update failed'];
                }

                // Generate download token bound to project and email
                $downloadsTable = $wpdb->prefix . 'ap_download_tokens';
                $token = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', time() + (7 * 24 * 3600));

                $galleryId = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}ap_galleries WHERE project_id = %d AND type = %s LIMIT 1", $projectId, 'final'));

                $inserted = $wpdb->insert($downloadsTable, [
                    'gallery_id' => $galleryId ?: null,
                    'project_id' => $projectId,
                    'token' => $token,
                    'expires_at' => $expiresAt,
                    'created_at' => current_time('mysql'),
                    'email' => $email,
                ], ['%d', '%d', '%s', '%s', '%s', '%s']);

                if ($inserted === false) {
                    Logger::log('error', 'payment', 'Failed to persist download token after payment', ['project_id' => $projectId, 'notify_admin' => true]);
                    return ['success' => false, 'message' => 'Failed to persist token'];
                }

                // Set transient for quick lookup
                set_transient('ap_download_' . $token, [
                    'gallery_id' => $galleryId,
                    'project_id' => $projectId,
                    'email' => $email,
                    'created_at' => time(),
                    'expires_at' => strtotime($expiresAt),
                ], 7 * 24 * 3600);

                // Notify client via email with download link
                if ($email) {
                    $downloadUrl = add_query_arg('ap_download', $token, home_url('/'));
                    EmailService::sendTemplate('final-gallery-ready', $email, [
                        'client_name' => $email,
                        'project_title' => 'Your project',
                        'gallery_url' => $downloadUrl,
                    ]);
                }

                Logger::log('info', 'payment', 'Payment processed and download token created', ['project_id' => $projectId, 'token' => $token]);
                return ['success' => true, 'message' => 'Processed payment and created token'];
            }

            // Other event types can be handled here
            Logger::log('info', 'payment', 'Unhandled payment event type', ['type' => $type]);
            return ['success' => true, 'message' => 'Event ignored'];
        } catch (\Throwable $e) {
            Logger::log('error', 'payment', 'Exception processing payment event: ' . $e->getMessage(), ['notify_admin' => true]);
            return ['success' => false, 'message' => 'Exception processing event'];
        }
    }
}
