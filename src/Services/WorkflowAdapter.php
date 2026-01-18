<?php
declare(strict_types=1);

namespace AperturePro\Services;

use AperturePro\Workflow\Workflow;

class WorkflowAdapter
{
    public function onPaymentReceived(int $projectId): void
    {
        if (class_exists(Workflow::class)) {
            Workflow::onPaymentReceived($projectId);
        }
    }
}
