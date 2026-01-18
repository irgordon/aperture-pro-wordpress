<?php

namespace AperturePro\Proof;

use AperturePro\Storage\StorageFactory;
use AperturePro\Helpers\Logger;

/**
 * ProofQueue
 *
 * Handles offloaded background generation of proof images.
 * When a proof is missing during a request, it is queued here instead of
 * being generated synchronously, which would block the request.
 */
class ProofQueue
{
    const QUEUE_OPTION = 'ap_proof_generation_queue';
    const QUEUE_LOCK   = 'ap_proof_queue_lock';
    const CRON_HOOK    = 'aperture_pro_generate_proofs';
    const MAX_PER_RUN  = 5;

    /**
     * Enqueue a proof for generation.
     *
     * @param string $originalPath Path to the original image.
     * @param string $proofPath    Target path for the proof.
     */
    public static function enqueue(string $originalPath, string $proofPath): void
    {
        $queue = get_option(self::QUEUE_OPTION, []);
        if (!is_array($queue)) {
            $queue = [];
        }

        // Avoid duplicates in the queue
        foreach ($queue as $item) {
            if ($item['proof_path'] === $proofPath) {
                return;
            }
        }

        $queue[] = [
            'original_path' => $originalPath,
            'proof_path'    => $proofPath,
            'created_at'    => current_time('mysql'),
            'attempts'      => 0,
        ];

        update_option(self::QUEUE_OPTION, $queue, false);

        // Schedule immediate processing if not already scheduled
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time(), self::CRON_HOOK);
        }
    }

    /**
     * Process the proof generation queue.
     * Called by WP Cron.
     */
    public static function processQueue(): void
    {
        // Simple lock to avoid concurrent runs
        if (get_transient(self::QUEUE_LOCK)) {
            return;
        }
        set_transient(self::QUEUE_LOCK, 1, 60); // 1 min lock

        $queue = get_option(self::QUEUE_OPTION, []);
        if (!is_array($queue) || empty($queue)) {
            delete_transient(self::QUEUE_LOCK);
            return;
        }

        // Process in batches
        $batch = array_splice($queue, 0, self::MAX_PER_RUN);
        $remaining = $queue;

        $storage = null;

        foreach ($batch as $item) {
            // Lazy load storage only if we have items
            if (!$storage) {
                try {
                    $storage = StorageFactory::create();
                } catch (\Throwable $e) {
                    Logger::log('error', 'proof_queue', 'Failed to init storage', ['error' => $e->getMessage()]);
                    // If storage fails, put items back and abort
                    $remaining = array_merge($batch, $remaining);
                    break;
                }
            }

            $originalPath = $item['original_path'];
            $proofPath    = $item['proof_path'];

            try {
                // generateProofVariant is now public (will be updated)
                $success = ProofService::generateProofVariant($originalPath, $proofPath, $storage);

                if ($success) {
                    Logger::log('info', 'proof_queue', 'Generated proof in background', ['proof' => $proofPath]);
                } else {
                    // Failed? Retry up to 3 times
                    $item['attempts']++;
                    if ($item['attempts'] < 3) {
                        $remaining[] = $item;
                    } else {
                        Logger::log('error', 'proof_queue', 'Failed to generate proof after retries', ['proof' => $proofPath]);
                    }
                }
            } catch (\Throwable $e) {
                Logger::log('error', 'proof_queue', 'Exception generating proof', ['proof' => $proofPath, 'error' => $e->getMessage()]);
                $item['attempts']++;
                if ($item['attempts'] < 3) {
                    $remaining[] = $item;
                }
            }
        }

        update_option(self::QUEUE_OPTION, $remaining, false);

        // If items remain, schedule next run immediately
        if (!empty($remaining)) {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_single_event(time() + 10, self::CRON_HOOK);
            }
        }

        delete_transient(self::QUEUE_LOCK);
    }
}
