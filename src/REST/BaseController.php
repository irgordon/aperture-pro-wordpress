<?php

namespace AperturePro\REST;

use WP_REST_Response;
use AperturePro\Helpers\Logger;
use AperturePro\Helpers\ErrorHandler;
use AperturePro\Email\EmailService;

/**
 * BaseController
 *
 * Central REST utilities: consistent success/error responses, error boundary,
 * and hooks into logging, admin notification queue, and Health Card transient.
 */
abstract class BaseController
{
    protected string $namespace = 'aperture/v1';

    abstract public function register_routes(): void;

    /**
     * Standard success response wrapper.
     */
    protected function respond_success(array $data = [], int $status = 200): WP_REST_Response
    {
        return new WP_REST_Response(
            [
                'success' => true,
                'data'    => $data,
            ],
            $status
        );
    }

    /**
     * Standard error response wrapper. Also logs the error and updates Health Card
     * transient for admin visibility. For critical errors, enqueue admin notification.
     */
    protected function respond_error(
        string $code,
        string $message,
        int $status = 400,
        array $meta = []
    ): WP_REST_Response {
        // Log the error
        Logger::log(
            'error',
            'rest',
            $message,
            array_merge(['code' => $code], $meta)
        );

        // Update Health Card transient to surface recent REST errors
        $this->updateHealthCard('rest_error', [
            'code' => $code,
            'message' => $message,
            'meta' => $meta,
            'time' => current_time('mysql'),
        ]);

        // If meta requests admin notification or this is a critical status, enqueue admin email
        $notify = $meta['notify_admin'] ?? ($status >= 500);
        if ($notify) {
            EmailService::enqueueAdminNotification(
                'error',
                'rest_error',
                sprintf('REST error %s: %s', $code, $message),
                $meta
            );
        }

        return new WP_REST_Response(
            [
                'success' => false,
                'error'   => $code,
                'message' => $message,
            ],
            $status
        );
    }

    /**
     * Wrap a callback with a global error boundary that logs, notifies, and returns a generic error.
     *
     * @param callable $callback
     * @param array $context optional context to include in logs
     * @return WP_REST_Response
     */
    protected function with_error_boundary(callable $callback, array $context = []): WP_REST_Response
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            $traceId = ErrorHandler::traceId();

            Logger::log(
                'error',
                'exception',
                $e->getMessage(),
                [
                    'trace_id' => $traceId,
                    'context'  => $context,
                    'trace'    => $e->getTraceAsString(),
                    'notify_admin' => true,
                ]
            );

            // Enqueue admin notification (queued to avoid email storms)
            EmailService::enqueueAdminNotification(
                'error',
                'exception',
                'Unhandled exception in REST endpoint',
                [
                    'trace_id' => $traceId,
                    'context' => $context,
                    'message' => $e->getMessage(),
                ]
            );

            // Update Health Card transient
            $this->updateHealthCard('exception', [
                'trace_id' => $traceId,
                'message' => $e->getMessage(),
                'context' => $context,
                'time' => current_time('mysql'),
            ]);

            return $this->respond_error(
                'unexpected_error',
                'An unexpected error occurred.',
                500,
                ['trace_id' => $traceId]
            );
        }
    }

    /**
     * Update a Health Card transient entry. Health Card reads these transients to surface issues.
     *
     * @param string $key short key for the health item
     * @param array $payload arbitrary payload to surface
     */
    protected function updateHealthCard(string $key, array $payload): void
    {
        $transientKey = 'ap_health_items';
        $items = get_transient($transientKey);
        if (!is_array($items)) {
            $items = [];
        }

        $items[$key] = array_merge($payload, ['updated_at' => current_time('mysql')]);

        // Keep only recent items to avoid unbounded growth
        if (count($items) > 50) {
            // remove oldest
            array_shift($items);
        }

        // TTL short so Health Card shows recent state
        set_transient($transientKey, $items, 60 * 60); // 1 hour
    }
}
