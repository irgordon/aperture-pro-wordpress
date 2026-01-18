<?php

namespace AperturePro\Services;

use AperturePro\Email\EmailService;

class Email implements ServiceInterface
{
    public function register(): void
    {
        // Hook for admin email queue
        add_action(EmailService::CRON_HOOK, [EmailService::class, 'processAdminQueue']);

        // Hook for transactional email queue
        add_action(EmailService::TRANSACTIONAL_CRON_HOOK, [EmailService::class, 'processTransactionalQueue']);

        // Hook for payment received email
        add_action('aperture_pro_payment_received', [EmailService::class, 'sendPaymentReceivedEmail']);
    }
}
