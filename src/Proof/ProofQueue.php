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
    const MAX_QUEUE_SIZE = 250;

    /**
     * Enqueue a proof for generation.
     *
     * @param string $originalPath Path to the original image.
     * @param string $proofPath    Target path for the proof.
     */
    public static function enqueue(string $originalPath, string $proofPath): void
    {
        self::enqueueBatch([
            [
                'original_path' => $originalPath,
                'proof_path'    => $proofPath,
            ]
        ]);
    }

    /**
     * Enqueue multiple proofs at once.
     *
     * OPTIMIZATION:
     * - Reads queue once (vs N times).
     * - Writes queue once (vs N times).
     * - Uses O(1) lookup to prevent duplicates (Idempotency).
     * - Enforces MAX_QUEUE_SIZE cap.
     *
     * @param array $items Array of ['original_path' => ..., 'proof_path' => ...]
     */
    public static function enqueueBatch(array $items): void
    {
        if (empty($items)) {
            return;
        }

        $queue = get_option(self::QUEUE_OPTION, []);
        if (!is_array($queue)) {
            $queue = [];
        }

        // Idempotency Guard: Build O(1) lookup for existing proofs
        $existingPaths = array_column($queue, 'proof_path');
        $existingMap   = array_flip($existingPaths);

        $changed = false;
        $count   = count($queue);

        foreach ($items as $item) {
            // Batch Size Cap
            if ($count >= self::MAX_QUEUE_SIZE) {
                break;
            }

            $proofPath    = $item['proof_path'] ?? null;
            $originalPath = $item['original_path'] ?? null;

            if (!$proofPath || !$originalPath) {
                continue;
            }

            // Check existence in current queue
            if (isset($existingMap[$proofPath])) {
                continue;
            }

            $queue[] = [
                'original_path' => $originalPath,
                'proof_path'    => $proofPath,
                'created_at'    => current_time('mysql'),
                'attempts'      => 0,
            ];

            // Add to map so we don't add duplicates within this same batch
            $existingMap[$proofPath] = true;
            $changed = true;
            $count++;
        }

        if ($changed) {
            update_option(self::QUEUE_OPTION, $queue, false);

            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_single_event(time(), self::CRON_HOOK);
            }
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

        try {
            $storage = StorageFactory::create();
        } catch (\Throwable $e) {
            Logger::log('error', 'proof_queue', 'Failed to init storage', ['error' => $e->getMessage()]);
            // If storage fails, put items back without penalty and abort
            $remaining = array_merge($batch, $remaining);
            update_option(self::QUEUE_OPTION, $remaining, false);
            delete_transient(self::QUEUE_LOCK);
            return;
        }

        try {
            $results = ProofService::generateBatch($batch, $storage);

            foreach ($batch as $item) {
                $proofPath = $item['proof_path'];
                $success   = $results[$proofPath] ?? false;

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
            }
        } catch (\Throwable $e) {
            Logger::log('error', 'proof_queue', 'Batch processing exception', ['error' => $e->getMessage()]);
            // If batch fails unexpectedly, retry eligible items
            foreach ($batch as $item) {
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

    /**
     * Get queue stats for backpressure signaling.
     *
     * @return array ['queued' => int, 'processing' => bool]
     */
    public static function getStats(): array
    {
        $queue = get_option(self::QUEUE_OPTION, []);
        $count = is_array($queue) ? count($queue) : 0;
        $processing = (bool) get_transient(self::QUEUE_LOCK);

        return [
            'queued'     => $count,
            'processing' => $processing,
        ];
    }
}
