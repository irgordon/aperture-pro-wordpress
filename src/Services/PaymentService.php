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
        $type = $event['type'] ?? '';
        $data = $event['data']['object'] ?? [];
        $object = (object) $data;

        try {
            switch ($type) {
                case 'payment_intent.succeeded':
                case 'charge.succeeded':
                    return self::handlePaymentSucceeded($object);

                case 'payment_intent.payment_failed':
                    return self::handlePaymentFailed($object);

                case 'charge.refunded':
                    return self::handlePaymentRefunded($object);

                default:
                    Logger::log('info', 'payment', 'Unhandled payment event type', ['type' => $type]);
                    return ['success' => true, 'message' => 'Event ignored'];
            }
        } catch (\Throwable $e) {
            Logger::log('error', 'payment', 'Exception processing payment event: ' . $e->getMessage(), ['notify_admin' => true]);
            return ['success' => false, 'message' => 'Exception processing event'];
        }
    }

    protected static function handlePaymentSucceeded($pi): array
    {
        global $wpdb;

        $project = self::findProjectByPaymentIntent($pi->id);

        // Fallback to metadata if available
        if (!$project && isset($pi->metadata['project_id'])) {
            $projectId = (int) $pi->metadata['project_id'];
            $project = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ap_projects WHERE id = %d", $projectId));
        }

        if (!$project) {
            self::logEvent(null, 'payment_intent_succeeded_unmatched', $pi);
            Logger::log('warning', 'payment', 'Webhook missing project_id or unmatched payment_intent', ['event' => $pi]);
            return ['success' => false, 'message' => 'Missing project_id'];
        }

        // Idempotency: skip if already marked paid
        if ($project->payment_status === 'paid') {
            return ['success' => true, 'message' => 'Already paid'];
        }

        // Calculate amount
        $amountReceived = isset($pi->amount_received) ? $pi->amount_received / 100 : ($pi->amount / 100);

        // Update project
        $updated = $wpdb->update(
            $wpdb->prefix . 'ap_projects',
            [
                'payment_status'        => 'paid',
                'payment_amount_received' => $amountReceived,
                'payment_currency'      => strtoupper($pi->currency ?? 'USD'),
                'payment_last_update'   => current_time('mysql'),
                'payment_intent_id'     => $pi->id ?? ($pi->payment_intent ?? null),
                'payment_provider'      => 'stripe', // Assuming stripe for now
                'updated_at'            => current_time('mysql')
            ],
            ['id' => $project->id],
            ['%s', '%f', '%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
             Logger::log('error', 'payment', 'Failed to update project payment_status', ['project_id' => $project->id]);
             return ['success' => false, 'message' => 'DB update failed'];
        }

        self::logEvent($project->id, 'payment_succeeded', $pi);

        // Trigger workflow transition
        if (class_exists(\AperturePro\Workflow\Workflow::class)) {
            \AperturePro\Workflow\Workflow::onPaymentReceived($project->id);
        }

        // Legacy logic: Generate download token
        self::generateDownloadToken($project, $pi);

        return ['success' => true, 'message' => 'Processed payment'];
    }

    protected static function handlePaymentFailed($pi): array
    {
        global $wpdb;

        $project = self::findProjectByPaymentIntent($pi->id);

        if (!$project) {
            self::logEvent(null, 'payment_failed_unmatched', $pi);
            return ['success' => true, 'message' => 'Unmatched project'];
        }

        $wpdb->update(
            $wpdb->prefix . 'ap_projects',
            [
                'payment_status'      => 'failed',
                'payment_last_update' => current_time('mysql'),
            ],
            ['id' => $project->id],
            ['%s', '%s'],
            ['%d']
        );

        self::logEvent($project->id, 'payment_failed', $pi);

        return ['success' => true, 'message' => 'Recorded payment failure'];
    }

    protected static function handlePaymentRefunded($charge): array
    {
        global $wpdb;

        // Charge object usually has payment_intent field
        $intentId = $charge->payment_intent ?? $charge->id; // Fallback
        $project = self::findProjectByPaymentIntent($intentId);

        if (!$project) {
            self::logEvent(null, 'refund_unmatched', $charge);
            return ['success' => true, 'message' => 'Unmatched project'];
        }

        $wpdb->update(
            $wpdb->prefix . 'ap_projects',
            [
                'payment_status'      => 'refunded',
                'payment_last_update' => current_time('mysql'),
            ],
            ['id' => $project->id],
            ['%s', '%s'],
            ['%d']
        );

        self::logEvent($project->id, 'payment_refunded', $charge);

        return ['success' => true, 'message' => 'Recorded refund'];
    }

    protected static function findProjectByPaymentIntent($intentId)
    {
        global $wpdb;
        if (!$intentId) return null;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ap_projects WHERE payment_intent_id = %s",
            $intentId
        ));
    }

    protected static function logEvent($projectId, $eventType, $payload)
    {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'ap_payment_events',
            [
                'project_id' => $projectId ?: 0, // 0 for unmatched
                'event_type' => $eventType,
                'payload'    => wp_json_encode($payload),
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s']
        );
    }

    protected static function generateDownloadToken($project, $pi)
    {
        global $wpdb;

        // Extract email from metadata or customer info
        $email = $pi->metadata['client_email'] ?? ($pi->billing_details['email'] ?? null);
        if (!$email) return;

        $downloadsTable = $wpdb->prefix . 'ap_download_tokens';
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + (7 * 24 * 3600));

        $galleryId = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}ap_galleries WHERE project_id = %d AND type = %s LIMIT 1", $project->id, 'final'));

        $inserted = $wpdb->insert($downloadsTable, [
            'gallery_id' => $galleryId ?: null,
            'project_id' => $project->id,
            'token' => $token,
            'expires_at' => $expiresAt,
            'created_at' => current_time('mysql'),
            'email' => $email,
        ], ['%d', '%d', '%s', '%s', '%s', '%s']);

        if ($inserted) {
            // Set transient for quick lookup
            set_transient('ap_download_' . $token, [
                'gallery_id' => $galleryId,
                'project_id' => $project->id,
                'email' => $email,
                'created_at' => time(),
                'expires_at' => strtotime($expiresAt),
            ], 7 * 24 * 3600);

            // Notify client via email with download link
            $downloadUrl = add_query_arg('ap_download', $token, home_url('/'));
            EmailService::sendTemplate('final-gallery-ready', $email, [
                'client_name' => $email,
                'project_title' => $project->title ?? 'Your project',
                'gallery_url' => $downloadUrl,
            ]);

            Logger::log('info', 'payment', 'Download token created', ['project_id' => $project->id, 'token' => $token]);
        }
    }

    public static function getPaymentSummary(int $projectId): ?array
    {
        global $wpdb;
        $project = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ap_projects WHERE id = %d", $projectId));

        if (!$project) {
            return null;
        }

        return [
            'package_price'   => $project->package_price,
            'payment_status'  => $project->payment_status,
            'amount_received' => $project->payment_amount_received,
            'currency'        => $project->payment_currency,
            'provider'        => $project->payment_provider,
            'payment_intent'  => $project->payment_intent_id,
            'last_update'     => $project->payment_last_update,
            'booking_date'    => $project->booking_date,
        ];
    }

    public static function getPaymentTimeline(int $projectId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT event_type, payload, created_at FROM {$wpdb->prefix}ap_payment_events WHERE project_id = %d ORDER BY created_at ASC",
            $projectId
        ));

        return array_map(function($row) {
            return [
                'event'     => $row->event_type,
                'timestamp' => $row->created_at,
                'payload'   => json_decode($row->payload, true),
            ];
        }, $rows);
    }

    public static function recreatePaymentIntent(int $projectId)
    {
        // Stub implementation for Admin UI "Retry" button
        // In a real implementation, this would fetch the project, instantiate the Stripe client,
        // and create a new PaymentIntent.

        return (object) [
            'id' => 'pi_retry_' . bin2hex(random_bytes(8)),
            'next_action' => (object) [
                'redirect_to_url' => (object) ['url' => home_url('/checkout?project_id=' . $projectId)]
            ]
        ];
    }
}
