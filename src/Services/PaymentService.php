<?php
declare(strict_types=1);

namespace AperturePro\Services;

use AperturePro\Payments\PaymentProviderFactory;
use AperturePro\Payments\DTO\PaymentIntentResult;
use AperturePro\Payments\DTO\PaymentUpdate;
use AperturePro\Payments\DTO\RefundResult;
use AperturePro\Repositories\ProjectRepository;
use AperturePro\Services\WorkflowAdapter;
use AperturePro\Email\EmailService;
use AperturePro\Helpers\Logger;

class PaymentService
{
    protected ProjectRepository $projects;
    protected WorkflowAdapter $workflow;

    public function __construct(ProjectRepository $projects, WorkflowAdapter $workflow)
    {
        $this->projects = $projects;
        $this->workflow = $workflow;
    }

    /**
     * Create a payment intent for a project using its configured provider.
     */
    public function create_intent_for_project(int $project_id): PaymentIntentResult
    {
        $project = $this->projects->find($project_id);
        if (!$project) {
             throw new \Exception("Project not found: $project_id");
        }

        $providerName = $project->payment_provider ?: 'stripe';

        $provider = PaymentProviderFactory::make($providerName);

        $amount = (int)(($project->package_price ?? 0) * 100);

        $intent = $provider->create_payment_intent([
            'amount'   => $amount,
            'currency' => 'USD',
            'metadata' => [
                'project_id' => $project_id,
            ],
        ]);

        $this->projects->update($project_id, [
            'payment_intent_id' => $intent->id,
            'payment_provider'  => $provider->get_name(),
            'updated_at'        => current_time('mysql'),
        ]);

        // Populate checkout URL if supported by provider (e.g. PayPal)
        // Check if property exists or just assign (dynamic property)
        if (!isset($intent->checkout_url) || empty($intent->checkout_url)) {
             $url = $provider->get_checkout_url($intent);
             if ($url) {
                 $intent->checkout_url = $url;
             }
        }

        return $intent;
    }

    /**
     * Apply a normalized PaymentUpdate from any provider.
     */
    public function apply_update(PaymentUpdate $update): void
    {
        if ($update->project_id <= 0) {
            $this->log_event(null, 'unmatched_payment_event', $update->raw_event);
            return;
        }

        $project = $this->projects->find($update->project_id);
        if (!$project) {
            $this->log_event(null, 'unmatched_project', $update->raw_event);
            return;
        }

        // Idempotency: skip if already paid
        if (($project->payment_status ?? '') === 'paid' && $update->status === 'paid') {
            return;
        }

        $this->projects->update($update->project_id, [
            'payment_status'      => $update->status,
            'payment_amount'      => $update->amount,
            'payment_currency'    => $update->currency,
            'payment_last_update' => current_time('mysql'),
            'updated_at'          => current_time('mysql'),
        ]);

        $this->log_event($update->project_id, $update->status, $update->raw_event);

        if ($update->status === 'paid') {
            $this->workflow->onPaymentReceived($update->project_id);

            // Generate download token (Legacy/Auxiliary feature)
            $this->generateDownloadToken($project, $update->raw_event);
        }
    }

    /**
     * Issue a refund via the provider.
     */
    public function refund(int $project_id, ?int $amount = null): RefundResult
    {
        $project = $this->projects->find($project_id);

        $provider = PaymentProviderFactory::make($project->payment_provider);

        $result = $provider->refund($project->payment_intent_id, $amount);

        $this->log_event($project_id, 'refund', $result->raw);

        $this->projects->update($project_id, [
            'payment_status'      => 'refunded',
            'payment_last_update' => current_time('mysql'),
        ]);

        return $result;
    }

    /**
     * Log payment events to ap_payment_events.
     */
    protected function log_event(?int $project_id, string $type, array $payload): void
    {
        global $wpdb;

        $wpdb->insert("{$wpdb->prefix}ap_payment_events", [
            'project_id' => $project_id ?: 0,
            'event_type' => $type,
            'payload'    => wp_json_encode($payload),
            'created_at' => current_time('mysql'),
        ]);
    }

    // --- Ported Methods ---

    public function getPaymentSummary(int $projectId): ?array
    {
        $project = $this->projects->find($projectId);

        if (!$project) {
            return null;
        }

        return [
            'package_price'   => $project->package_price,
            'payment_status'  => $project->payment_status,
            'amount_received' => $project->payment_amount,
            'currency'        => $project->payment_currency,
            'provider'        => $project->payment_provider,
            'payment_intent'  => $project->payment_intent_id,
            'last_update'     => $project->payment_last_update,
            'booking_date'    => $project->booking_date,
        ];
    }

    public function getPaymentTimeline(int $projectId): array
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

    public function recreatePaymentIntent(int $projectId)
    {
         return $this->create_intent_for_project($projectId);
    }

    protected function generateDownloadToken($project, $rawEvent)
    {
        global $wpdb;

        // Try to find email
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
}
