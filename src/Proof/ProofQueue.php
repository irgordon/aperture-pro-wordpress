<?php

namespace AperturePro\Proof;

use AperturePro\Storage\StorageFactory;
use AperturePro\Helpers\Logger;

/**
 * ProofQueue
 *
 * Handles offloaded background generation of proof images.
 * Optimized to use a custom database table (`ap_proof_queue`) for scalability
 * and robustness, replacing the legacy option-based queue.
 */
class ProofQueue
{
    /** Legacy option key (fallback) */
    const QUEUE_OPTION = 'ap_proof_generation_queue';

    const QUEUE_LOCK   = 'ap_proof_queue_lock';
    const CRON_HOOK    = 'aperture_pro_generate_proofs';
    const MAX_PER_RUN  = 10; // Increased throughput
    const TABLE_NAME   = 'ap_proof_queue';

    /** @var bool|null Cache for table existence check */
    protected static ?bool $tableExistsCache = null;

    /**
     * Check if the dedicated queue table exists.
     * Caches result for performance.
     *
     * @return bool
     */
    protected static function tableExists(): bool
    {
        if (self::$tableExistsCache !== null) {
            return self::$tableExistsCache;
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        $exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $wpdb->esc_like($table)
        )) === $table;

        self::$tableExistsCache = (bool) $exists;
        return self::$tableExistsCache;
    }

    /**
     * Add an item to the queue.
     *
     * USAGE:
     * - Uses INSERT IGNORE to handle duplicates efficiently.
     * - Falls back to legacy option if table is missing.
     *
     * @param int $projectId
     * @param int $imageId
     * @return bool
     */
    public static function add(int $projectId, int $imageId): bool
    {
        // 1. Try DB Table (Optimized)
        if (self::tableExists()) {
            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_NAME;

            // INSERT IGNORE handles dedup via UNIQUE KEY (project_id, image_id)
            $result = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$table} (project_id, image_id, created_at) VALUES (%d, %d, %s)",
                $projectId,
                $imageId,
                current_time('mysql')
            ));

            if ($result !== false) {
                self::scheduleCronIfNeeded();
                return true;
            }
            // If DB insert fails, we might want to log or fallback.
            Logger::log('error', 'proof_queue', 'Failed to insert into DB queue', ['error' => $wpdb->last_error]);
        }

        // 2. Fallback to Legacy Option (Slow)
        return self::addToLegacyQueue($projectId, $imageId);
    }

    /**
     * Add multiple items to the queue.
     *
     * @param array $items Array of ['project_id' => int, 'image_id' => int]
     * @return bool
     */
    public static function addBatch(array $items): bool
    {
        if (empty($items)) {
            return false;
        }

        // 1. Try DB Table (Optimized)
        if (self::tableExists()) {
            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_NAME;

            $values = [];
            $placeholders = [];
            $now = current_time('mysql');

            foreach ($items as $item) {
                if (isset($item['project_id'], $item['image_id'])) {
                    $placeholders[] = "(%d, %d, %s)";
                    $values[] = $item['project_id'];
                    $values[] = $item['image_id'];
                    $values[] = $now;
                }
            }

            if (empty($placeholders)) {
                return false;
            }

            $query = "INSERT IGNORE INTO {$table} (project_id, image_id, created_at) VALUES " . implode(',', $placeholders);
            $result = $wpdb->query($wpdb->prepare($query, ...$values));

            if ($result !== false) {
                self::scheduleCronIfNeeded();
                return true;
            }
            Logger::log('error', 'proof_queue', 'Failed to batch insert into DB queue', ['error' => $wpdb->last_error]);
        }

        // 2. Fallback to Legacy Option
        return self::addToLegacyQueueBatchIds($items);
    }

    /**
     * Helper to get legacy queue as a keyed map.
     * Normalized to [project_id:image_id => item].
     *
     * @param bool $migrated Reference to track if migration occurred.
     * @return array
     */
    protected static function getLegacyQueueAsMap(&$migrated = false): array
    {
        $option = get_option(self::QUEUE_OPTION, []);
        $map = [];

        // If already keyed, return as-is
        $isKeyed = false;
        if (!empty($option) && is_array($option)) {
            foreach (array_keys($option) as $k) {
                if (!is_int($k)) {
                    $isKeyed = true;
                    break;
                }
            }
        }
        if ($isKeyed) {
            $migrated = false;
            return $option;
        }

        // Convert numeric list to map
        foreach ($option as $item) {
            $p = isset($item['project_id']) ? (int) $item['project_id'] : 0;
            $i = isset($item['image_id']) ? (int) $item['image_id'] : 0;
            if ($p > 0 && $i > 0) {
                $map["{$p}:{$i}"] = [
                    'project_id' => $p,
                    'image_id'   => $i,
                    'created_at' => $item['created_at'] ?? current_time('mysql', true),
                    'attempts'   => $item['attempts'] ?? 0,
                ];
            }
        }
        $migrated = true;
        return $map;
    }

    /**
     * Legacy enqueue method for backward compatibility.
     * Should be updated to use add() by callers where possible.
     *
     * @deprecated Use add() with project/image ID instead.
     */
    public static function enqueue(string $originalPath, string $proofPath): void
    {
        // We cannot easily map paths to IDs without a query.
        // For now, this just uses the legacy array format in the option
        // because the new table requires IDs.
        // Ideally, callers are updated to pass IDs.
        self::enqueueBatch([
            [
                'original_path' => $originalPath,
                'proof_path'    => $proofPath,
            ]
        ]);
    }

    /**
     * Legacy batch enqueue.
     *
     * @deprecated Callers should use add() in a loop or we implement addBatch().
     */
    public static function enqueueBatch(array $items): void
    {
        // If items have IDs, redirect to optimized add()
        // If items are just paths (legacy), go to legacy option.
        $idItems = [];
        $legacyItems = [];

        foreach ($items as $item) {
            // If caller provided IDs (future-proofing refactor)
            if (isset($item['project_id'], $item['image_id'])) {
                $idItems[] = [
                    'project_id' => (int)$item['project_id'],
                    'image_id'   => (int)$item['image_id']
                ];
            } else {
                $legacyItems[] = $item;
            }
        }

        if (!empty($idItems)) {
            self::addBatch($idItems);
        }

        if (!empty($legacyItems)) {
            self::addToLegacyQueueBatch($legacyItems);
        }
    }

    /**
     * Fetch a batch of items to process.
     *
     * @param int $limit
     * @return array Array of objects {id, project_id, image_id, ...}
     */
    public static function fetchBatch(int $limit = 10): array
    {
        if (self::tableExists()) {
            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_NAME;

            // FIFO: Oldest first, excluding max retries
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE attempts < 3 ORDER BY created_at ASC LIMIT %d",
                $limit
            ));
        }

        // Fallback: Read from option and convert to object-like structure
        $queue = get_option(self::QUEUE_OPTION, []);
        if (!is_array($queue)) return [];

        $slice = array_slice($queue, 0, $limit);
        $results = [];
        foreach ($slice as $k => $item) {
            // Legacy items use paths, not IDs.
            // We return them as-is, but wrapped as objects for consistency if possible?
            // Actually, processQueue needs to handle both types.
            $results[] = (object) $item;
        }

        return $results;
    }

    /**
     * Remove processed items from the queue.
     *
     * @param array $ids List of queue IDs to remove.
     */
    public static function removeBatch(array $ids): void
    {
        if (empty($ids) || !self::tableExists()) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        // Sanitize ints
        $ids = array_map('intval', $ids);
        $in = implode(',', $ids);

        $wpdb->query("DELETE FROM {$table} WHERE id IN ({$in})");
    }

    /**
     * Process the proof generation queue.
     * Handles both DB-based items and Legacy Option items.
     */
    public static function processQueue(): void
    {
        // Concurrency Lock
        if (get_transient(self::QUEUE_LOCK)) {
            return;
        }
        set_transient(self::QUEUE_LOCK, 1, 60); // 1 min lock

        try {
            // 1. Process DB Queue
            if (self::tableExists()) {
                self::processDbQueue();
            }

            // 2. Process Legacy Queue (Drain it)
            self::processLegacyQueue();

        } catch (\Throwable $e) {
            Logger::log('error', 'proof_queue', 'Exception in processQueue', ['error' => $e->getMessage()]);
        } finally {
            delete_transient(self::QUEUE_LOCK);
        }
    }

    /**
     * Process batch from DB table.
     */
    protected static function processDbQueue(): void
    {
        $batch = self::fetchBatch(self::MAX_PER_RUN);
        if (empty($batch)) {
            return;
        }

        try {
            $storage = StorageFactory::create();
        } catch (\Throwable $e) {
            // Abort if storage is broken
            return;
        }

        // We need to resolve IDs to Paths for ProofService
        // Batch query to ap_images + ap_galleries?
        // Actually, ProofService::getProofPath takes a path.
        // We need 'original_path' from ap_images.

        $imageIds = array_map(fn($item) => $item->image_id, $batch);
        $imageMap = self::getOriginalPaths($imageIds);

        $toGenerate = [];
        $queueIdsToRemove = [];
        $failedQueueIds = [];

        foreach ($batch as $item) {
            $imageId = $item->image_id;

            if (!isset($imageMap[$imageId])) {
                // Orphaned queue item? Image deleted? Remove it.
                $queueIdsToRemove[] = $item->id;
                continue;
            }

            $originalPath = $imageMap[$imageId];
            $proofPath    = ProofService::getProofPathForOriginal($originalPath); // Helper needed

            $toGenerate[] = [
                'queue_id'      => $item->id,
                'image_id'      => $imageId,
                'original_path' => $originalPath,
                'proof_path'    => $proofPath,
            ];
        }

        if (empty($toGenerate)) {
            // All orphans?
            if (!empty($queueIdsToRemove)) {
                self::removeBatch($queueIdsToRemove);
            }
            return;
        }

        // Call Generation Service
        // Map to format expected by generateBatch
        $genItems = [];
        foreach ($toGenerate as $t) {
            $genItems[] = [
                'original_path' => $t['original_path'],
                'proof_path'    => $t['proof_path']
            ];
        }

        $results = ProofService::generateBatch($genItems, $storage);

        // Correlate results back to queue IDs
        $successfulImageIds = [];

        $generatedProofs = [];

        foreach ($toGenerate as $t) {
            $proofPath = $t['proof_path'];
            $qid       = $t['queue_id'];

            if (!empty($results[$proofPath])) {
                $queueIdsToRemove[] = $qid;
                $successfulImageIds[] = $t['image_id'];
                $generatedProofs[] = $proofPath;
            } else {
                $failedQueueIds[] = $qid;
            }
        }

        if (!empty($generatedProofs)) {
            Logger::log('info', 'proof_queue', 'Generated proofs batch', [
                'count'  => count($generatedProofs),
                'proofs' => $generatedProofs,
            ]);
        }

        // Cleanup success
        self::removeBatch($queueIdsToRemove);
        self::markProofsAsExisting($successfulImageIds);

        // Handle failure (increment attempts)
        if (!empty($failedQueueIds)) {
            self::incrementAttempts($failedQueueIds);

            // Cleanup items that have exceeded max retries to keep table clean
            self::cleanupMaxRetries($failedQueueIds);
        }

        // Reschedule if we did work (might be more)
        self::scheduleCronIfNeeded();
    }

    /**
     * Process items from legacy option queue.
     */
    protected static function processLegacyQueue(): void
    {
        $queue = get_option(self::QUEUE_OPTION, []);
        if (!is_array($queue) || empty($queue)) {
            return;
        }

        $batch = array_splice($queue, 0, self::MAX_PER_RUN);
        $remaining = $queue; // Start with what's left

        try {
            $storage = StorageFactory::create();
            $results = ProofService::generateBatch($batch, $storage);

            foreach ($batch as $key => $item) {
                $proofPath = $item['proof_path'] ?? '';
                if (empty($results[$proofPath])) {
                    // Failed? Retry logic (legacy)
                    $item['attempts'] = ($item['attempts'] ?? 0) + 1;
                    if ($item['attempts'] < 3) {
                        if (is_string($key)) {
                            $remaining[$key] = $item;
                        } else {
                            $p = $item['project_id'] ?? 0;
                            $i = $item['image_id'] ?? 0;
                            if ($p && $i) {
                                $remaining["{$p}:{$i}"] = $item;
                            } else {
                                $remaining[] = $item;
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Put back batch on error
            foreach ($batch as $key => $item) {
                $item['attempts'] = ($item['attempts'] ?? 0) + 1;
                if ($item['attempts'] < 3) {
                    if (is_string($key)) {
                        $remaining[$key] = $item;
                    } else {
                        $p = $item['project_id'] ?? 0;
                        $i = $item['image_id'] ?? 0;
                        if ($p && $i) {
                            $remaining["{$p}:{$i}"] = $item;
                        } else {
                            $remaining[] = $item;
                        }
                    }
                }
            }
        }

        update_option(self::QUEUE_OPTION, $remaining, false);

        if (!empty($remaining)) {
            self::scheduleCronIfNeeded();
        }
    }

    /**
     * Helper to get original paths from image IDs.
     *
     * @param array $imageIds
     * @return array [imageId => originalPath]
     */
    protected static function getOriginalPaths(array $imageIds): array
    {
        if (empty($imageIds)) return [];

        global $wpdb;
        $table = $wpdb->prefix . 'ap_images';
        $idsIn = implode(',', array_map('intval', $imageIds));

        // Assuming storage_key_original holds the path
        $rows = $wpdb->get_results("SELECT id, storage_key_original FROM {$table} WHERE id IN ({$idsIn})");

        $map = [];
        foreach ($rows as $row) {
            if (!empty($row->storage_key_original)) {
                $map[$row->id] = $row->storage_key_original;
            }
        }
        return $map;
    }

    /**
     * Mark proofs as existing in the database (Performance Optimization).
     *
     * @param array $imageIds
     */
    public static function markProofsAsExisting(array $imageIds): void
    {
        if (empty($imageIds)) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ap_images';
        $idsIn = implode(',', array_map('intval', $imageIds));

        // Use update instead of complex logic
        $wpdb->query("UPDATE {$table} SET has_proof = 1 WHERE id IN ({$idsIn})");
    }

    /**
     * Increment attempt counter for failed items.
     */
    protected static function incrementAttempts(array $ids): void
    {
        if (empty($ids)) return;
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $idsIn = implode(',', array_map('intval', $ids));

        $wpdb->query("UPDATE {$table} SET attempts = attempts + 1 WHERE id IN ({$idsIn})");
    }

    /**
     * Remove items that have exceeded max retries (3) to prevent infinite loops/bloat.
     */
    protected static function cleanupMaxRetries(array $ids): void
    {
        if (empty($ids)) return;
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $idsIn = implode(',', array_map('intval', $ids));

        $wpdb->query("DELETE FROM {$table} WHERE attempts >= 3 AND id IN ({$idsIn})");
    }

    /**
     * Fallback: Add to legacy option queue.
     */
    protected static function addToLegacyQueue(int $projectId, int $imageId): bool
    {
        $migrated = false;
        $queue = self::getLegacyQueueAsMap($migrated);
        $key = "{$projectId}:{$imageId}";

        // O(1) Lookup
        if (isset($queue[$key])) {
            // Ensure we persist the migration even if no new item is added
            if ($migrated) {
                update_option(self::QUEUE_OPTION, $queue, false);
            }
            return true;
        }

        $queue[$key] = [
            'project_id' => $projectId,
            'image_id'   => $imageId,
            'created_at' => current_time('mysql'),
            'attempts'   => 0
        ];

        update_option(self::QUEUE_OPTION, $queue, false);
        self::scheduleCronIfNeeded();
        return true;
    }

    /**
     * Batch add to legacy queue.
     */
    protected static function addToLegacyQueueBatchIds(array $items): bool
    {
        $migrated = false;
        $queue = self::getLegacyQueueAsMap($migrated);
        $changed = false;
        $now = current_time('mysql');

        foreach ($items as $item) {
            $projectId = $item['project_id'] ?? 0;
            $imageId = $item['image_id'] ?? 0;

            if (!$projectId || !$imageId) continue;

            $key = "{$projectId}:{$imageId}";
            if (!isset($queue[$key])) {
                $queue[$key] = [
                    'project_id' => $projectId,
                    'image_id'   => $imageId,
                    'created_at' => $now,
                    'attempts'   => 0
                ];
                $changed = true;
            }
        }

        if ($changed || $migrated) {
            update_option(self::QUEUE_OPTION, $queue, false);
            self::scheduleCronIfNeeded();
        }

        return true;
    }

    protected static function addToLegacyQueueBatch(array $items): void
    {
        $queue = get_option(self::QUEUE_OPTION, []);
        $existingMap = [];
        foreach ($queue as $q) {
            if (isset($q['proof_path'])) $existingMap[$q['proof_path']] = true;
        }

        $changed = false;
        foreach ($items as $item) {
            $proofPath = $item['proof_path'] ?? null;
            if ($proofPath && !isset($existingMap[$proofPath])) {
                $item['created_at'] = current_time('mysql');
                $item['attempts'] = 0;
                $queue[] = $item;
                $existingMap[$proofPath] = true;
                $changed = true;
            }
        }

        if ($changed) {
            update_option(self::QUEUE_OPTION, $queue, false);
            self::scheduleCronIfNeeded();
        }
    }

    protected static function scheduleCronIfNeeded(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time(), self::CRON_HOOK);
        }
    }

    /**
     * Get queue stats (for health check).
     */
    public static function getStats(): array
    {
        $queued = 0;

        if (self::tableExists()) {
            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_NAME;
            $queued = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        } else {
            $q = get_option(self::QUEUE_OPTION, []);
            $queued = is_array($q) ? count($q) : 0;
        }

        return [
            'queued'     => $queued,
            'processing' => (bool) get_transient(self::QUEUE_LOCK),
        ];
    }

    /**
     * Attempt to migrate legacy items to DB table.
     * Can be called by an upgrader or cron.
     */
    public static function migrateLegacyQueue(): int
    {
        if (!self::tableExists()) {
            return 0;
        }

        $queue = get_option(self::QUEUE_OPTION, []);
        if (empty($queue)) {
            return 0;
        }

        $moved = 0;
        $remaining = [];

        foreach ($queue as $item) {
            // We can only migrate if we have IDs.
            // Older legacy items might only have paths. We cannot easily recover IDs without lookup.
            if (isset($item['project_id'], $item['image_id'])) {
                self::add((int)$item['project_id'], (int)$item['image_id']);
                $moved++;
            } else {
                $remaining[] = $item;
            }
        }

        // Save back only what we couldn't migrate
        if ($moved > 0) {
            update_option(self::QUEUE_OPTION, $remaining, false);
        }

        return $moved;
    }
}
