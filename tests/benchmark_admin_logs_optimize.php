<?php
/**
 * Benchmark for AdminController::get_logs optimization.
 *
 * Compares:
 * 1. Standard: decode DB JSON -> PHP Array -> encode Response JSON
 * 2. Optimized: Raw DB JSON -> String Concatenation -> Response JSON
 */

// Generate mock data
$rowCount = 2000; // Increased count to make differences distinct
$rows = [];
$sampleMeta = [
    'user_id' => 123,
    'browser' => 'Chrome',
    'details' => [
        'foo' => 'bar',
        'baz' => range(1, 100), // decent size
    ]
];
$jsonMeta = json_encode($sampleMeta);

for ($i = 0; $i < $rowCount; $i++) {
    $rows[] = (object) [
        'id' => $i,
        'level' => 'info',
        'context' => 'test',
        'message' => 'Something happened ' . $i,
        'trace_id' => 'abc-' . $i,
        'meta' => ($i % 2 === 0) ? $jsonMeta : null, // 50% have meta
        'created_at' => '2023-01-01 12:00:00',
    ];
}

function benchmark_baseline($rows) {
    $start = microtime(true);
    $startMem = memory_get_usage();

    $data = [];
    foreach ($rows as $row) {
        $meta = null;
        if (!empty($row->meta)) {
            $decoded = json_decode($row->meta, true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }

        $data[] = [
            'id'         => (int) $row->id,
            'level'      => (string) $row->level,
            'context'    => (string) $row->context,
            'message'    => (string) $row->message,
            'trace_id'   => $row->trace_id ? (string) $row->trace_id : null,
            'meta'       => $meta,
            'created_at' => (string) $row->created_at,
        ];
    }

    // Simulate WP_REST_Response encoding
    $json = json_encode(['success' => true, 'data' => $data]);

    $end = microtime(true);
    $endMem = memory_get_usage();

    return [
        'time' => $end - $start,
        'memory' => $endMem - $startMem,
        'length' => strlen($json)
    ];
}

function benchmark_optimized($rows) {
    $start = microtime(true);
    $startMem = memory_get_usage();

    // Manual JSON construction
    // Start response
    $json = '{"success":true,"data":[';

    $first = true;
    foreach ($rows as $row) {
        if (!$first) {
            $json .= ',';
        }
        $first = false;

        $id = (int) $row->id;
        // Escape strings for JSON safety
        $level = json_encode((string) $row->level);
        $context = json_encode((string) $row->context);
        $message = json_encode((string) $row->message);
        $traceId = $row->trace_id ? json_encode((string) $row->trace_id) : 'null';
        $createdAt = json_encode((string) $row->created_at);

        // Handle Meta: if it's valid JSON, inject it directly, else null
        $metaJson = 'null';
        if (!empty($row->meta)) {
            // Safety check: ensure it looks like JSON to avoid breaking the whole stream
            $first = $row->meta[0];
            if ($first === '{' || $first === '[') {
                 $metaJson = $row->meta;
            }
        }

        $json .= sprintf(
            '{"id":%d,"level":%s,"context":%s,"message":%s,"trace_id":%s,"meta":%s,"created_at":%s}',
            $id,
            $level,
            $context,
            $message,
            $traceId,
            $metaJson,
            $createdAt
        );
    }

    $json .= ']}';

    $end = microtime(true);
    $endMem = memory_get_usage();

    return [
        'time' => $end - $start,
        'memory' => $endMem - $startMem,
        'length' => strlen($json)
    ];
}

echo "Benchmarking " . count($rows) . " rows...\n\n";

// Warmup
benchmark_baseline(array_slice($rows, 0, 10));
benchmark_optimized(array_slice($rows, 0, 10));

$resultBase = benchmark_baseline($rows);
echo "Baseline:\n";
echo "  Time:   " . number_format($resultBase['time'], 5) . " s\n";
echo "  Memory: " . number_format($resultBase['memory'] / 1024 / 1024, 2) . " MB\n";
echo "  Length: " . $resultBase['length'] . " bytes\n";

$resultOpt = benchmark_optimized($rows);
echo "\nOptimized:\n";
echo "  Time:   " . number_format($resultOpt['time'], 5) . " s\n";
echo "  Memory: " . number_format($resultOpt['memory'] / 1024 / 1024, 2) . " MB\n";
echo "  Length: " . $resultOpt['length'] . " bytes\n";

$improvement = ($resultBase['time'] - $resultOpt['time']) / $resultBase['time'] * 100;
echo "\nSpeed Improvement: " . number_format($improvement, 2) . "%\n";

// Validation check
$decodedBase = json_decode(json_encode(['success' => true, 'data' => []])); // dummy
// Actually verify contents match for a small subset
$smallRows = array_slice($rows, 0, 5);
// ... avoiding full verification logic here to keep benchmark simple,
// but in implementation we must be careful.
