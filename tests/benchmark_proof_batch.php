<?php

/**
 * Benchmark: Sequential vs Parallel Downloads
 *
 * This script demonstrates the performance difference between
 * sequential HTTP requests (blocking) and parallel requests (non-blocking IO).
 *
 * Usage: php tests/benchmark_proof_batch.php
 */

// Use httpbin to simulate network latency (1 second per request)
$delay = 1;
$count = 5;
$url = "https://httpbin.org/delay/$delay";
$urls = array_fill(0, $count, $url);

echo "Benchmarking $count requests with {$delay}s latency each...\n\n";

// --- 1. Sequential Download ---
echo "1. Running Sequential Downloads...\n";
$start = microtime(true);
foreach ($urls as $u) {
    $ch = curl_init($u);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $res = curl_exec($ch);
    curl_close($ch);
}
$seqTime = microtime(true) - $start;
echo "   Time: " . number_format($seqTime, 4) . "s\n";
echo "   Expected: ~" . ($count * $delay) . "s\n\n";

// --- 2. Parallel Download (curl_multi) ---
echo "2. Running Parallel Downloads...\n";
$start = microtime(true);

$mh = curl_multi_init();
$handles = [];

foreach ($urls as $i => $u) {
    $ch = curl_init($u);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_multi_add_handle($mh, $ch);
    $handles[$i] = $ch;
}

$active = null;
do {
    $mrc = curl_multi_exec($mh, $active);
} while ($mrc == CURLM_CALL_MULTI_PERFORM);

while ($active && $mrc == CURLM_OK) {
    if (curl_multi_select($mh) == -1) {
        usleep(100);
    }
    do {
        $mrc = curl_multi_exec($mh, $active);
    } while ($mrc == CURLM_CALL_MULTI_PERFORM);
}

foreach ($handles as $ch) {
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}
curl_multi_close($mh);

$parTime = microtime(true) - $start;
echo "   Time: " . number_format($parTime, 4) . "s\n";
echo "   Expected: ~" . ($delay) . "s (plus overhead)\n\n";

// --- Results ---
$speedup = $parTime > 0 ? $seqTime / $parTime : 0;
echo "--------------------------------------------------\n";
echo "Speedup Factor: " . number_format($speedup, 2) . "x\n";
echo "--------------------------------------------------\n";
