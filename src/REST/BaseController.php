<?php

namespace AperturePro\REST;

use WP_REST_Response;
use AperturePro\Helpers\Logger;
use AperturePro\Helpers\ErrorHandler;

abstract class BaseController
{
    protected string $namespace = 'aperture/v1';

    abstract public function register_routes(): void;

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

    protected function respond_error(
        string $code,
        string $message,
        int $status = 400,
        array $meta = []
    ): WP_REST_Response {
        Logger::log(
            'error',
            'rest',
            $message,
            [
                'code' => $code,
                'meta' => $meta,
            ]
        );

        return new WP_REST_Response(
            [
                'success' => false,
                'error'   => $code,
                'message' => $message,
            ],
            $status
        );
    }

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
                ]
            );

            return $this->respond_error(
                'unexpected_error',
                'An unexpected error occurred.',
                500,
                ['trace_id' => $traceId]
            );
        }
    }
}
