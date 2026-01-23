<?php

// Mock WordPress functions
if (!function_exists('get_option')) {
    function get_option($key, $default = []) {
        global $mock_queue;
        return $mock_queue ?? $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($key, $value, $autoload = null) {
        global $mock_queue;
        $mock_queue = $value;
        return true;
    }
}
if (!function_exists('current_time')) {
    function current_time($type) {
        return date('Y-m-d H:i:s');
    }
}
if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook) { return false; }
}
if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event($timestamp, $hook) {}
}

// Minimal ProofQueue mock
class ProofQueue {
    const QUEUE_OPTION = 'ap_proof_generation_queue';
    const CRON_HOOK = 'aperture_pro_generate_proofs';

    protected static function scheduleCronIfNeeded() {}

    public static function addToLegacyQueue(int $projectId, int $imageId): bool
    {
        $queue = get_option(self::QUEUE_OPTION, []);
        // Dedup check O(N) - painful but necessary for legacy
        foreach ($queue as $item) {
            if (($item['project_id'] ?? 0) === $projectId && ($item['image_id'] ?? 0) === $imageId) {
                return true;
            }
        }

        $queue[] = [
            'project_id' => $projectId,
            'image_id'   => $imageId,
            'created_at' => current_time('mysql'),
            'attempts'   => 0
        ];

        update_option(self::QUEUE_OPTION, $queue, false);
        return true;
    }

    // Optimized version candidate (using keys)
    public static function addToLegacyQueueOptimized(int $projectId, int $imageId): bool
    {
        $queue = get_option(self::QUEUE_OPTION, []);
        $key = "{$projectId}_{$imageId}";

        if (isset($queue[$key])) {
            return true;
        }

        // Also check numeric keys to be safe? Or assume we migrated?
        // If we assume we are transitioning, we might check linear if key missing.
        // But for benchmark, let's assume we are fully keyed.

        $queue[$key] = [
            'project_id' => $projectId,
            'image_id'   => $imageId,
            'created_at' => current_time('mysql'),
            'attempts'   => 0
        ];

        update_option(self::QUEUE_OPTION, $queue, false);
        return true;
    }
}

// Setup large queue
global $mock_queue;
$mock_queue = [];
$queueSize = 5000;
for ($i = 0; $i < $queueSize; $i++) {
    // Numeric keys for baseline
    $mock_queue[] = [
        'project_id' => $i,
        'image_id' => $i,
    ];
}

echo "Benchmarking with queue size: $queueSize\n";

// Benchmark Original
$start = microtime(true);
for ($i = 0; $i < 1000; $i++) {
    // Search for existing items (worst case O(N) if at end, average O(N/2))
    // Search for non-existing items (O(N))
    $target = $queueSize + $i;
    ProofQueue::addToLegacyQueue($target, $target);
}
$duration = microtime(true) - $start;
echo "Original (linear scan, adding new items): " . number_format($duration, 4) . "s\n";


// Reset for Optimized
$mock_queue = [];
for ($i = 0; $i < $queueSize; $i++) {
    // Keyed for optimized
    $key = "{$i}_{$i}";
    $mock_queue[$key] = [
        'project_id' => $i,
        'image_id' => $i,
    ];
}

$start = microtime(true);
for ($i = 0; $i < 1000; $i++) {
    $target = $queueSize + $i;
    ProofQueue::addToLegacyQueueOptimized($target, $target);
}
$duration = microtime(true) - $start;
echo "Optimized (keyed lookup): " . number_format($duration, 4) . "s\n";
