<?php

namespace AperturePro\Services;

use AperturePro\Admin\AdminUI;
use AperturePro\Admin\HealthCard;
use AperturePro\Installer\SetupWizard;

class Admin implements ServiceInterface
{
    public function register(): void
    {
        add_action('init', [$this, 'onInit'], 5);
    }

    public function onInit(): void
    {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        if (class_exists(SetupWizard::class)) {
            SetupWizard::init();
        }

        if (class_exists(AdminUI::class)) {
            AdminUI::init();
        }

        if (class_exists(HealthCard::class)) {
            HealthCard::init();
        }
    }
}
