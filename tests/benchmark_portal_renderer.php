<?php
/**
 * Benchmark for JSON decode loop optimization.
 * Run this with PHP CLI to measure impact.
 */

$rows = [];
// Simulate 10,000 images with empty comments stored as "[]"
for ($i = 0; $i < 10000; $i++) {
    $rows[] = (object) ['client_comments' => '[]'];
}
// Simulate 1,000 images with actual comments
for ($i = 0; $i < 1000; $i++) {
    $rows[] = (object) ['client_comments' => '[{"comment":"Great shot!","created_at":"2023-01-01 12:00:00"}]'];
}

echo "Benchmarking " . count($rows) . " iterations...\n";

// Baseline
$start = microtime(true);
$count = 0;
foreach ($rows as $r) {
    $comments = [];
    if (!empty($r->client_comments)) {
        $decoded = json_decode($r->client_comments, true);
        if (is_array($decoded)) {
            $comments = $decoded;
        }
    }
    $count += count($comments);
}
$duration = microtime(true) - $start;
echo "Baseline: " . number_format($duration, 4) . "s\n";

// Optimized
$start = microtime(true);
$count = 0;
foreach ($rows as $r) {
    $comments = [];
    if (!empty($r->client_comments)) {
        // Optimization: Skip decode for empty JSON array
        if ($r->client_comments === '[]') {
            // Already []
        } else {
            $decoded = json_decode($r->client_comments, true);
            if (is_array($decoded)) {
                $comments = $decoded;
            }
        }
    }
    $count += count($comments);
}
$duration = microtime(true) - $start;
echo "Optimized: " . number_format($duration, 4) . "s\n";
