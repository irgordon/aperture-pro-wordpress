<?php

namespace AperturePro\Services;

use AperturePro\Proof\ProofQueue;

class Proof implements ServiceInterface
{
    public function register(): void
    {
        // Hook for background proof generation
        add_action(ProofQueue::CRON_HOOK, [ProofQueue::class, 'processQueue']);
    }
}
