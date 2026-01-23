<?php
/**
 * Benchmark for ProofQueue Logging (N+1 vs Aggregated)
 * Usage: php tests/benchmark_proof_processing_log.php
 */

require_once __DIR__ . '/../src/Helpers/Logger.php';

// Mock Logger class if not already loaded (it might be if I included the file, but I want to override it or mock the DB call)
// Since Logger is a static class, I can't easily mock it without namespaces tricks or just simulating the call.
// However, the test environment allows me to redefine functions if they are not loaded, or I can just define a dummy logger function locally.
// But the code calls `Logger::log`.

// Let's create a MockLogger class in the global namespace or just simulate the loop logic directly in the benchmark script
// since I am benchmarking the *loop structure*, not the actual Logger class implementation.

class MockLogger {
    public static function log($level, $context, $message, $meta = []) {
        // Simulate DB Latency
        usleep(2000); // 2ms
    }
}

$itemCount = 50;
$toGenerate = [];
$results = [];

for ($i = 0; $i < $itemCount; $i++) {
    $proofPath = "proofs/img_$i.jpg";
    $toGenerate[] = [
        'queue_id' => $i,
        'image_id' => $i * 10,
        'proof_path' => $proofPath
    ];
    $results[$proofPath] = true; // All success
}

echo "Starting Benchmark ($itemCount items, 2ms simulated DB latency per log)...\n";

// --- Baseline: N+1 Logging ---
$start = microtime(true);

$queueIdsToRemove = [];
$successfulImageIds = [];
$failedQueueIds = [];

foreach ($toGenerate as $t) {
    $proofPath = $t['proof_path'];
    $qid       = $t['queue_id'];

    if (!empty($results[$proofPath])) {
        $queueIdsToRemove[] = $qid;
        $successfulImageIds[] = $t['image_id'];
        // Simulate Logger::log
        MockLogger::log('info', 'proof_queue', 'Generated proof', ['proof' => $proofPath]);
    } else {
        $failedQueueIds[] = $qid;
    }
}

$end = microtime(true);
$duration = $end - $start;
echo sprintf("Baseline (N+1 Logging): %.4f seconds\n", $duration);
$baselineDuration = $duration;


// --- Optimized: Aggregated Logging ---
$start = microtime(true);

$queueIdsToRemove = [];
$successfulImageIds = [];
$failedQueueIds = [];
$processedProofs = [];

foreach ($toGenerate as $t) {
    $proofPath = $t['proof_path'];
    $qid       = $t['queue_id'];

    if (!empty($results[$proofPath])) {
        $queueIdsToRemove[] = $qid;
        $successfulImageIds[] = $t['image_id'];
        $processedProofs[] = $proofPath;
    } else {
        $failedQueueIds[] = $qid;
    }
}

if (!empty($processedProofs)) {
    MockLogger::log('info', 'proof_queue', 'Generated proofs batch', [
        'count' => count($processedProofs),
        'proofs' => $processedProofs // In real life, maybe limit this list or just log count
    ]);
}

$end = microtime(true);
$durationOpt = $end - $start;

echo sprintf("Optimized (Aggregated Logging): %.4f seconds\n", $durationOpt);
echo sprintf("Improvement: %.2fx faster\n", $baselineDuration / $durationOpt);
