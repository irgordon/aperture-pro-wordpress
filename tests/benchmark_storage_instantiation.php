<?php

/**
 * Benchmark: Storage instantiation vs. reuse
 *
 * GOAL:
 *  - Demonstrate the cost of repeated StorageFactory::create() calls
 *    (which include config decryption) vs. reusing a single instance.
 *
 * USAGE:
 *  - Run via CLI in a WordPress-aware context (e.g., wp-cli eval-file).
 */

use AperturePro\Storage\StorageFactory;

require_once dirname(__DIR__) . '/wp-load.php';

function bench_storage_instantiation(int $iterations = 1000): void
{
    echo "Benchmarking StorageFactory::create() vs reuse ({$iterations} iterations)\n";

    // Baseline: repeated instantiation
    $start = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $s = StorageFactory::create();
        // no-op
    }
    $repeated = microtime(true) - $start;

    // Optimized: single instantiation, reused
    $start = microtime(true);
    $s = StorageFactory::create();
    for ($i = 0; $i < $iterations; $i++) {
        // no-op with $s
    }
    $reused = microtime(true) - $start;

    $ratio = $repeated > 0 ? $repeated / max($reused, 0.000001) : 0;

    echo "Repeated instantiation: {$repeated} seconds\n";
    echo "Reused instance:       {$reused} seconds\n";
    echo "Speedup:               ~" . number_format($ratio, 1) . "x\n";
}

bench_storage_instantiation(1000);
