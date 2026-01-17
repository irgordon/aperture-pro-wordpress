<?php

namespace AperturePro\Services;

use AperturePro\ClientPortal\PortalController;

class ClientPortal implements ServiceInterface
{
    public function register(): void
    {
        if (class_exists(PortalController::class)) {
            PortalController::init();
        }
    }
}
