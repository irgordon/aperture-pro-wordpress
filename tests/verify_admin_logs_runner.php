<?php
/**
 * Runner for verify_admin_logs_endpoint.php
 */

echo "Running AdminController::get_logs verification...\n";

$cmd = 'php ' . __DIR__ . '/verify_admin_logs_endpoint.php';
$output = shell_exec($cmd);

if (!$output) {
    echo "[FAIL] No output received.\n";
    exit(1);
}

// Decode JSON
$json = json_decode($output, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo "[FAIL] Invalid JSON output: " . json_last_error_msg() . "\n";
    echo "Output was:\n" . $output . "\n";
    exit(1);
}

// Check structure
if (!isset($json['success']) || $json['success'] !== true) {
    echo "[FAIL] Success flag missing or false.\n";
    print_r($json);
    exit(1);
}

if (!isset($json['data']) || !is_array($json['data'])) {
    echo "[FAIL] Data field missing or not array.\n";
    exit(1);
}

$rows = $json['data'];
if (count($rows) !== 5) {
    echo "[FAIL] Expected 5 rows, got " . count($rows) . ".\n";
    exit(1);
}

// Check row content
$row2 = $rows[1]; // Index 1 is id 2 (even, has meta)
if ($row2['id'] !== 2) {
    echo "[FAIL] Row ID mismatch.\n";
    exit(1);
}

if (!isset($row2['meta']) || !is_array($row2['meta'])) {
    echo "[FAIL] Row 2 meta should be array (was injected as JSON).\n";
    print_r($row2);
    exit(1);
}

if (($row2['meta']['foo'] ?? '') !== 'bar') {
    echo "[FAIL] Meta content incorrect.\n";
    exit(1);
}

$row1 = $rows[0]; // Index 0 is id 1 (odd, null meta)
if ($row1['meta'] !== null) {
    echo "[FAIL] Row 1 meta should be null.\n";
    exit(1);
}

echo "[PASS] Admin logs JSON output verification successful.\n";
