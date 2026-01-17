<?php

namespace AperturePro\Services;

use AperturePro\REST\AdminController;
use AperturePro\REST\AuthController;
use AperturePro\REST\ClientProofController;
use AperturePro\REST\DownloadController;
use AperturePro\REST\PaymentController;
use AperturePro\REST\UploadController;

class REST implements ServiceInterface
{
    /**
     * List of controller classes to register.
     */
    protected array $controllers = [
        AdminController::class,
        AuthController::class,
        ClientProofController::class,
        DownloadController::class,
        PaymentController::class,
        UploadController::class,
    ];

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        foreach ($this->controllers as $class) {
            if (class_exists($class)) {
                $controller = new $class();
                if (method_exists($controller, 'register_routes')) {
                    $controller->register_routes();
                }
            }
        }
    }
}
