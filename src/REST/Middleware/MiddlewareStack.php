<?php
declare(strict_types=1);

namespace AperturePro\REST\Middleware;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

final class MiddlewareStack
{
    /** @var MiddlewareInterface[] */
    private array $stack;

    /**
     * @param MiddlewareInterface[] $stack
     */
    public function __construct(array $stack)
    {
        $this->stack = $stack;
    }

    /**
     * @return WP_Error|WP_REST_Response|null
     */
    public function run(WP_REST_Request $request)
    {
        foreach ($this->stack as $mw) {
            $result = $mw->handle($request);

            if ($result instanceof WP_Error || $result instanceof WP_REST_Response) {
                return $result;
            }
        }

        return null;
    }
}
