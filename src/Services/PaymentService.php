<?php

namespace AperturePro\Services;

use AperturePro\Helpers\Logger;
use AperturePro\Email\EmailService;
use AperturePro\Payments\PaymentProviderFactory;
use AperturePro\Payments\DTO\PaymentUpdate;

/**
 * PaymentService
 *
 * Provider-agnostic payment processing service.
 */
class PaymentService
{
    /**
     * Create a payment intent for a project.
     *
     * @param int $projectId
     * @param string $providerName
     * @return \AperturePro\Payments\DTO\PaymentIntentResult
     */
    public static function create_intent_for_project(int $projectId, string $providerName)
    {
        global $wpdb;
        $project = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ap_projects WHERE id = %d", $projectId));

        if (!$project) {
            throw new \Exception("Project not found: $projectId");
        }

        $provider = PaymentProviderFactory::make($providerName);

        $intent = $provider->create_payment_intent([
            'amount'   => $project->package_price,
            'currency' => 'usd', // Should likely be configurable or per-project
            'metadata' => ['project_id' => $projectId],
            // Add email if needed by provider
        ]);

        // Populate checkout URL if the provider generates it separately (e.g. PayPal)
        if (empty($intent->checkout_url)) {
            $intent->checkout_url = $provider->get_checkout_url($intent);
        }

        $wpdb->update(
            $wpdb->prefix . 'ap_projects',
            [
                'payment_intent_id' => $intent->id,
                'payment_provider'  => $providerName,
                'updated_at'        => current_time('mysql'),
            ],
            ['id' => $projectId]
        );

        return $intent;
    }

    /**
     * Handle incoming webhook request.
     *
     * @param string $providerName
     * @param string $payload
     * @param array $headers
     * @return array
     */
    public static function handleWebhook(string $providerName, string $payload, array $headers): array
    {
        try {
            $provider = PaymentProviderFactory::make($providerName);
            $event = $provider->verify_webhook($payload, $headers);
            $update = $provider->handle_webhook_event($event);

            self::apply_update($update);

            return ['success' => true, 'message' => 'Processed'];
        } catch (\Throwable $e) {
            Logger::log('error', 'payment', 'Exception processing payment webhook: ' . $e->getMessage(), ['provider' => $providerName]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public static function apply_update(PaymentUpdate $update)
    {
        global $wpdb;

        if (!$update->project_id) {
             // Try to find project by intent ID if not provided
             $project = self::findProjectByPaymentIntent($update->intent_id);
             if ($project) {
                 $update->project_id = $project->id;
             } else {
                 Logger::log('warning', 'payment', 'Webhook missing project_id or unmatched intent', ['intent' => $update->intent_id]);
                 self::logEvent(null, $update->status, $update->raw_event);
                 return;
             }
        }

        $projectId = $update->project_id;

        // Log event
        self::logEvent($projectId, $update->status, $update->raw_event);

        $updates = [
            'payment_last_update' => current_time('mysql'),
            'updated_at'          => current_time('mysql')
        ];

        if ($update->status === 'paid') {
             $updates['payment_status'] = 'paid';
             $updates['payment_amount_received'] = $update->amount;
             $updates['payment_currency'] = $update->currency;
        } elseif ($update->status === 'failed') {
             $updates['payment_status'] = 'failed';
        } elseif ($update->status === 'refunded') {
             $updates['payment_status'] = 'refunded';
        }

        $wpdb->update(
            $wpdb->prefix . 'ap_projects',
            $updates,
            ['id' => $projectId]
        );

        if ($update->status === 'paid') {
            // Trigger workflow
            if (class_exists(\AperturePro\Workflow\Workflow::class)) {
                \AperturePro\Workflow\Workflow::onPaymentReceived($projectId);
            }

            // Legacy/Auxiliary: Generate download token
            $project = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ap_projects WHERE id = %d", $projectId));
            self::generateDownloadToken($project, $update->raw_event);
        }
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
                'project_id' => $projectId ?: 0,
                'event_type' => $eventType,
                'payload'    => wp_json_encode($payload),
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s']
        );
    }

    protected static function generateDownloadToken($project, $rawEvent)
    {
        global $wpdb;

        // Try to get email from rawEvent if it's an object/array resembling Stripe
        // or just use project client email if available?
        // We will prioritize rawEvent email if present, else fall back?
        // Actually, let's just check the structure.
        $email = null;
        if (is_object($rawEvent)) {
             $email = $rawEvent->metadata->client_email ?? ($rawEvent->billing_details->email ?? null);
        } elseif (is_array($rawEvent)) {
             $email = $rawEvent['metadata']['client_email'] ?? ($rawEvent['billing_details']['email'] ?? null);
        }

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
            set_transient('ap_download_' . $token, [
                'gallery_id' => $galleryId,
                'project_id' => $project->id,
                'email' => $email,
                'created_at' => time(),
                'expires_at' => strtotime($expiresAt),
            ], 7 * 24 * 3600);

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
        global $wpdb;
        $project = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ap_projects WHERE id = %d", $projectId));
        // Use existing provider or default to stripe
        $provider = $project->payment_provider ?: 'stripe';
        return self::create_intent_for_project($projectId, $provider);
    }
}
