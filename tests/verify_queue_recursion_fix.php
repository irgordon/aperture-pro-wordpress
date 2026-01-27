<?php
/**
 * Verification Script for Proof Queue Recursion Fix
 *
 * This script verifies that:
 * 1. If tableExists() returns true, attemptTableRecovery() returns false.
 * 2. This prevents infinite recursion when add() fails for reasons other than missing table.
 */

namespace AperturePro\Installer;

class Schema {
    public static function activate(): void {
        // Should not be called in this test scenario
        echo "Schema::activate called (UNEXPECTED)\n";
    }
}

namespace AperturePro\Proof;

require_once __DIR__ . '/../src/Proof/ProofQueue.php';

// Mocks
$mock_transients = [];
function get_transient($transient) {
    global $mock_transients;
    return $mock_transients[$transient] ?? false;
}
function set_transient($transient, $value, $expiration = 0) {
    global $mock_transients;
    $mock_transients[$transient] = $value;
    return true;
}
function delete_transient($transient) {}
function current_time($type) { return '2021-01-01 00:00:00'; }

class MockWPDB {
    public $prefix = 'wp_';
    public function prepare($query, ...$args) { return $query; }
    public function esc_like($text) { return $text; }
    public function get_var($query) {
        // Always return table name => tableExists() returns true
        return 'wp_ap_proof_queue';
    }
}
$GLOBALS['wpdb'] = new MockWPDB();

// Expose protected method for testing
class TestProofQueue extends ProofQueue {
    public static function testAttemptTableRecovery() {
        return self::attemptTableRecovery();
    }
}

echo "--- Starting Recursion Fix Verification ---\n";

// Ensure cache is clear so we hit table check
global $mock_transients;
$mock_transients['ap_proof_queue_table_exists'] = false; // Initial transient false
// Wait, ProofQueue checks transient first.
// If get_transient returns false, it checks DB.
// DB Mock returns 'wp_ap_proof_queue'.
// So tableExists() should return TRUE.

// Test: attemptTableRecovery()
$result = TestProofQueue::testAttemptTableRecovery();

if ($result === false) {
    echo "[PASS] attemptTableRecovery returned FALSE when table exists.\n";
    echo "       This ensures fallback proceeds to legacy queue instead of retrying add().\n";
} else {
    echo "[FAIL] attemptTableRecovery returned TRUE when table exists.\n";
    echo "       This would cause infinite recursion in ProofQueue::add().\n";
    exit(1);
}
