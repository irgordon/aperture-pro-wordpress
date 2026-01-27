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

        // Performance: Check persistent cache first
        $cacheKey = 'ap_proof_queue_table_exists';
        $cached = get_transient($cacheKey);
        if ($cached !== false) {
            self::$tableExistsCache = (bool) $cached;
            return self::$tableExistsCache;
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        $exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $wpdb->esc_like($table)
        )) === $table;

        // Cache for 24 hours
        set_transient($cacheKey, (int) $exists, 24 * 3600);

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
    public static function enqueue(string $originalPath, string $proofPath, ?int $projectId = null, ?int $imageId = null): void
    {
        // 1. If IDs are provided, use optimized path immediately
        if ($projectId > 0 && $imageId > 0) {
            self::add($projectId, $imageId);
            return;
        }

        // 2. Try to resolve IDs from original path
        $resolved = self::resolveIdsFromPaths([$originalPath]);
        if (isset($resolved[$originalPath])) {
            $ids = $resolved[$originalPath];
            self::add($ids['project_id'], $ids['image_id']);
            return;
        }

        // 3. Fallback to legacy queue
        self::addToLegacyQueueBatch([
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
        $pathsToResolve = [];
        $pathToItemMap = []; // Keep track of original items by path for fallback

        foreach ($items as $item) {
            // If caller provided IDs (future-proofing refactor)
            if (isset($item['project_id'], $item['image_id'])) {
                $idItems[] = [
                    'project_id' => (int)$item['project_id'],
                    'image_id'   => (int)$item['image_id']
                ];
            } elseif (isset($item['original_path']) && !empty($item['original_path'])) {
                $path = $item['original_path'];
                $pathsToResolve[] = $path;
                if (!isset($pathToItemMap[$path])) {
                    $pathToItemMap[$path] = [];
                }
                $pathToItemMap[$path][] = $item;
            } else {
                // No IDs and no path? Just legacy fallback (unlikely valid but safe)
                $legacyItems[] = $item;
            }
        }

        // Try to resolve IDs for path-based items
        if (!empty($pathsToResolve)) {
            $resolvedMap = self::resolveIdsFromPaths($pathsToResolve);
            foreach ($pathToItemMap as $path => $mappedItems) {
                if (isset($resolvedMap[$path])) {
                    // Resolved successfully
                    foreach ($mappedItems as $mappedItem) {
                        $idItems[] = $resolvedMap[$path];
                    }
                } else {
                    // Could not resolve, fallback to legacy
                    foreach ($mappedItems as $mappedItem) {
                        $legacyItems[] = $mappedItem;
                    }
                }
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
     * Can return a mix of DB items and Legacy items if table exists but migration is partial.
     *
     * @param int $limit
     * @return array Array of objects {type: 'db'|'legacy', ...item_data}
     */
    public static function fetchBatch(int $limit = 10): array
    {
        $results = [];

        // 1. Fetch from DB Table (Primary)
        if (self::tableExists()) {
            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_NAME;

            // FIFO: Oldest first, excluding max retries
            $dbItems = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE attempts < 3 ORDER BY created_at ASC LIMIT %d",
                $limit
            ));

            foreach ($dbItems as $item) {
                $item->type = 'db';
                $results[] = $item;
            }
        }

        // 2. Backfill with Legacy Queue if we haven't hit the limit
        // This ensures we drain the legacy queue even if DB queue is empty/partial.
        if (count($results) < $limit) {
            $queue = get_option(self::QUEUE_OPTION, []);
            if (is_array($queue) && !empty($queue)) {
                $needed = $limit - count($results);
                $slice  = array_slice($queue, 0, $needed, true); // preserve keys

                foreach ($slice as $key => $item) {
                    $obj = (object) $item;
                    $obj->type = 'legacy';
                    $obj->legacy_key = $key; // needed for removal/update
                    $results[] = $obj;
                }
            }
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
            // 1. Attempt Migration if Table Exists
            if (self::tableExists()) {
                self::migrateLegacyQueue();
            }

            // 2. Fetch Mixed Batch
            // This pulls from DB table first, then fills remaining slot with legacy items.
            $batch = self::fetchBatch(self::MAX_PER_RUN);

            // 3. Process Unified Batch
            if (!empty($batch)) {
                self::processUnifiedBatch($batch);
            }

        } catch (\Throwable $e) {
            Logger::log('error', 'proof_queue', 'Exception in processQueue', ['error' => $e->getMessage()]);
        } finally {
            delete_transient(self::QUEUE_LOCK);
        }
    }

    /**
     * Process a unified batch containing potential mix of DB and Legacy items.
     *
     * @param array $batch Items from fetchBatch()
     */
    protected static function processUnifiedBatch(array $batch): void
    {
        if (empty($batch)) {
            return;
        }

        try {
            $storage = StorageFactory::create();
        } catch (\Throwable $e) {
            return;
        }

        // 1. Separate items by type
        $dbItems = [];
        $legacyItems = [];
        $imageIdsToResolve = [];

        foreach ($batch as $item) {
            if (isset($item->type) && $item->type === 'legacy') {
                $legacyItems[] = $item;
                // Check if legacy item has an ID that needs resolution
                if (isset($item->image_id) && $item->image_id > 0) {
                    $imageIdsToResolve[] = (int) $item->image_id;
                }
            } else {
                // Default to DB type
                $dbItems[] = $item;
                $imageIdsToResolve[] = (int) $item->image_id;
            }
        }

        // 2. Resolve Paths for DB/Legacy Items
        $imageMap = self::getOriginalPaths($imageIdsToResolve);

        // 3. Prepare Generation List
        // Structure: ['original_path' => ..., 'proof_path' => ..., '_meta' => item_object]
        $toGenerate = [];
        $dbIdsToRemove = []; // Orphans

        // Process DB Items
        foreach ($dbItems as $item) {
            $imageId = $item->image_id;
            if (!isset($imageMap[$imageId])) {
                // Orphaned
                $dbIdsToRemove[] = $item->id;
                continue;
            }
            $originalPath = $imageMap[$imageId];
            $proofPath    = ProofService::getProofPathForOriginal($originalPath);

            $toGenerate[] = [
                'original_path' => $originalPath,
                'proof_path'    => $proofPath,
                '_meta'         => $item
            ];
        }

        // Process Legacy Items (paths are usually embedded)
        // Track keys to remove if invalid
        $legacyKeysInvalid = [];

        foreach ($legacyItems as $item) {
            $originalPath = $item->original_path ?? null;
            $proofPath    = $item->proof_path ?? null;

            // If no path but we have an ID, try to resolve it from imageMap
            if (empty($originalPath) && isset($item->image_id) && isset($imageMap[$item->image_id])) {
                $originalPath = $imageMap[$item->image_id];
            }

            if ($originalPath) {
                if (!$proofPath) {
                    $proofPath = ProofService::getProofPathForOriginal($originalPath);
                }
                $toGenerate[] = [
                    'original_path' => $originalPath,
                    'proof_path'    => $proofPath,
                    '_meta'         => $item
                ];
            } else {
                // Invalid legacy item? Mark for removal
                if (isset($item->legacy_key)) {
                    $legacyKeysInvalid[] = $item->legacy_key;
                }
            }
        }

        if (empty($toGenerate)) {
            if (!empty($dbIdsToRemove)) {
                self::removeBatch($dbIdsToRemove);
            }
            if (!empty($legacyKeysInvalid)) {
                self::updateLegacyQueue($legacyKeysInvalid, []);
            }
            return;
        }

        // 4. Execute Batch Generation
        $genPayload = array_map(function ($t) {
            return [
                'original_path' => $t['original_path'],
                'proof_path'    => $t['proof_path']
            ];
        }, $toGenerate);

        $results = ProofService::generateBatch($genPayload, $storage);

        // 5. Process Results
        $dbIdsSuccess = [];
        $dbIdsFail    = [];
        $legacyKeysSuccess = [];
        $legacyKeysFail    = []; // To increment attempts
        $successfulImageIds = [];

        $generatedProofs = [];

        foreach ($toGenerate as $t) {
            $proofPath = $t['proof_path'];
            $meta      = $t['_meta'];
            $success   = !empty($results[$proofPath]);

            if ($success) {
                $generatedProofs[] = $proofPath;
                if ($meta->type === 'legacy') {
                    $legacyKeysSuccess[] = $meta->legacy_key;
                    // If legacy item has IDs, we can mark DB optimization too
                    if (isset($meta->image_id) && $meta->image_id > 0) {
                        $successfulImageIds[] = $meta->image_id;
                    }
                } else {
                    $dbIdsSuccess[] = $meta->id;
                    $successfulImageIds[] = $meta->image_id;
                }
            } else {
                if ($meta->type === 'legacy') {
                    $legacyKeysFail[] = $meta->legacy_key;
                } else {
                    $dbIdsFail[] = $meta->id;
                }
            }
        }

        // 6. Apply Updates

        // DB Success
        $dbIdsToRemove = array_merge($dbIdsToRemove, $dbIdsSuccess);
        self::removeBatch($dbIdsToRemove);
        self::markProofsAsExisting($successfulImageIds);

        // DB Failure
        if (!empty($dbIdsFail)) {
            self::incrementAttempts($dbIdsFail);
            self::cleanupMaxRetries($dbIdsFail);
        }

        // Legacy Updates
        $legacyKeysToRemove = array_merge($legacyKeysSuccess, $legacyKeysInvalid);
        if (!empty($legacyKeysToRemove) || !empty($legacyKeysFail)) {
            self::updateLegacyQueue($legacyKeysToRemove, $legacyKeysFail);
        }

        if (!empty($generatedProofs)) {
            Logger::log('info', 'proof_queue', 'Generated proofs batch', [
                'count'  => count($generatedProofs),
                'proofs' => $generatedProofs,
            ]);
        }

        // 7. Reschedule if batch was full (likely more items)
        if (count($batch) >= self::MAX_PER_RUN) {
            self::scheduleCronIfNeeded();
        }
    }

    /**
     * Update legacy queue option (remove success, increment failure).
     */
    protected static function updateLegacyQueue(array $removeKeys, array $failKeys): void
    {
        $queue = get_option(self::QUEUE_OPTION, []);
        if (empty($queue) || !is_array($queue)) {
            return;
        }

        $changed = false;
        $removeMap = array_flip($removeKeys);
        $failMap   = array_flip($failKeys);

        foreach ($queue as $key => &$item) {
            // Check removal
            if (isset($removeMap[$key])) {
                unset($queue[$key]);
                $changed = true;
                continue;
            }

            // Check failure increment
            if (isset($failMap[$key])) {
                $item['attempts'] = ($item['attempts'] ?? 0) + 1;
                $changed = true;
                // Check max retries
                if ($item['attempts'] >= 3) {
                    unset($queue[$key]);
                }
            }
        }

        if ($changed) {
            // Re-index if array became associative and we want list?
            // Actually our legacy format supports keys.
            update_option(self::QUEUE_OPTION, $queue, false);
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
     * Attempt to recover the queue table if it is missing.
     * This is called by fallback methods to see if we can self-heal.
     *
     * @return bool True if recovery was attempted and table now exists.
     */
    protected static function attemptTableRecovery(): bool
    {
        // 1. If we already know table exists, no need to recover.
        // If we are here, it means add() failed. If tableExists() is true,
        // the failure was likely due to something else (e.g. DB error),
        // so we should NOT retry to avoid infinite recursion.
        if (self::tableExists()) {
            return false;
        }

        // 2. Check for Schema class
        if (!class_exists('\AperturePro\Installer\Schema')) {
            return false;
        }

        // 3. Attempt Recovery (Create Tables)
        try {
            \AperturePro\Installer\Schema::activate();

            // 4. Invalidate Cache
            self::$tableExistsCache = null;
            delete_transient('ap_proof_queue_table_exists');

            // 5. Re-check
            return self::tableExists();
        } catch (\Throwable $e) {
            // Logger::log('error', 'proof_queue', 'Recovery failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Fallback: Add to legacy option queue.
     */
    protected static function addToLegacyQueue(int $projectId, int $imageId): bool
    {
        // Optimization: If table is missing, try to create it and retry.
        // This avoids dumping 1000s of items into wp_options if someone dropped the table.
        if (self::attemptTableRecovery()) {
            return self::add($projectId, $imageId);
        }

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
        // Optimization: Try to recover table before falling back
        if (self::attemptTableRecovery()) {
            return self::addBatch($items);
        }

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

        list($moved, $remaining) = self::doMigration($queue);

        if ($moved > 0) {
            update_option(self::QUEUE_OPTION, $remaining, false);
        }

        return $moved;
    }

    /**
     * Internal migration logic.
     *
     * @param array $queue
     * @return array [int $moved, array $remainingQueue]
     */
    protected static function doMigration(array $queue): array
    {
        $migratable = [];
        $remaining = [];

        $pathsToLookup = [];
        $itemMap = []; // Map original_path -> item(s)

        foreach ($queue as $key => $item) {
            // We can only migrate if we have IDs.
            if (isset($item['project_id'], $item['image_id'])) {
                $migratable[] = [
                    'project_id' => (int)$item['project_id'],
                    'image_id'   => (int)$item['image_id']
                ];
            } elseif (isset($item['original_path']) && !empty($item['original_path'])) {
                // Legacy path-based item. Try to resolve ID.
                $path = $item['original_path'];
                $pathsToLookup[] = $path;
                if (!isset($itemMap[$path])) {
                    $itemMap[$path] = [];
                }
                $itemMap[$path][] = $item;
            } else {
                $remaining[] = $item;
            }
        }

        // Attempt to resolve IDs for path-based items
        if (!empty($pathsToLookup)) {
            $resolvedMap = self::resolveIdsFromPaths($pathsToLookup);

            foreach ($resolvedMap as $path => $ids) {
                if (isset($itemMap[$path])) {
                    // We found a match! Add to migratable.
                    foreach ($itemMap[$path] as $legacyItem) {
                        $migratable[] = $ids;
                    }
                    unset($itemMap[$path]);
                }
            }
        }

        // Any items remaining in itemMap could not be resolved; keep them in legacy queue.
        foreach ($itemMap as $items) {
            foreach ($items as $item) {
                $remaining[] = $item;
            }
        }

        $moved = 0;
        if (!empty($migratable)) {
            if (self::addBatch($migratable)) {
                $moved = count($migratable);
            } else {
                // Batch insert failed. Return original queue (assume no migration happened)
                return [0, $queue];
            }
        }

        return [$moved, $remaining];
    }

    /**
     * Helper to resolve IDs for a list of original paths.
     *
     * @param array $paths List of original paths to resolve.
     * @return array Map of original_path => ['project_id' => int, 'image_id' => int]
     */
    protected static function resolveIdsFromPaths(array $paths): array
    {
        if (empty($paths)) {
            return [];
        }

        global $wpdb;
        $paths = array_unique($paths);
        $escapedPaths = [];
        foreach ($paths as $p) {
            $escapedPaths[] = $wpdb->prepare('%s', $p);
        }

        // Chunking to avoid massive query if queue is huge
        $chunks = array_chunk($escapedPaths, 500);
        $imagesTable = $wpdb->prefix . 'ap_images';
        $galleriesTable = $wpdb->prefix . 'ap_galleries';
        $resolved = [];

        foreach ($chunks as $chunk) {
            $inClause = implode(',', $chunk);
            $query = "
                SELECT i.id as image_id, i.storage_key_original, g.project_id
                FROM {$imagesTable} i
                JOIN {$galleriesTable} g ON i.gallery_id = g.id
                WHERE i.storage_key_original IN ({$inClause})
            ";
            $results = $wpdb->get_results($query);

            foreach ($results as $row) {
                if (!empty($row->storage_key_original)) {
                    $resolved[$row->storage_key_original] = [
                        'project_id' => (int)$row->project_id,
                        'image_id'   => (int)$row->image_id
                    ];
                }
            }
        }

        return $resolved;
    }
}
