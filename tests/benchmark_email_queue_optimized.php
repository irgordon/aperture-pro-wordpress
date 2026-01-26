<?php

namespace AperturePro\Helpers {
    class Logger {
        public static function log($level, $context, $message, $data = []) {
            // No-op for benchmark
        }
    }
}

namespace {
    // Mock constants
    define('ARRAY_A', 'ARRAY_A');
    define('OBJECT', 'OBJECT');

    // Mock wpdb
    class wpdb {
        public $prefix = 'wp_';
        public $rows = []; // Simulate DB table rows: id => row
        public $last_id = 0;

        public function prepare($query, ...$args) {
             // For benchmark purposes, we don't strictly need safe interpolation
             return $query;
        }

        public function get_var($query) {
            if (strpos($query, "SHOW TABLES LIKE") !== false) {
                 return 'wp_ap_email_queue';
            }
            if (strpos($query, "COUNT(*)") !== false) {
                // Return count of pending
                 $count = 0;
                 foreach ($this->rows as $r) {
                     if ($r['status'] === 'pending') $count++;
                 }
                 return $count;
            }
            return null;
        }

        public function insert($table, $data) {
            $this->last_id++;
            $data['id'] = $this->last_id;
            $this->rows[$this->last_id] = $data;

            // Simulate single row insert latency (approx 0.5ms for network + insert)
            // Much cheaper than serializing 500 items
            usleep(500);
            return 1;
        }

        public function update($table, $data, $where) {
            // Find row by id
            $id = $where['id'];
            if (isset($this->rows[$id])) {
                foreach ($data as $k => $v) {
                    $this->rows[$id][$k] = $v;
                }
            }
            // Simulate update latency
            usleep(500);
            return 1;
        }

        public function query($query) {
             // Simulate latency
             usleep(500);

             // Handle batch updates
             // UPDATE wp_ap_email_queue SET status = 'sent', updated_at = '...' WHERE id IN (1,2,3)
             if (preg_match('/UPDATE\s+(\S+)\s+SET\s+status\s*=\s*\'([^\']+)\'.*WHERE\s+id\s+IN\s*\(([^)]+)\)/i', $query, $matches)) {
                 $newStatus = $matches[2];
                 $idsStr = $matches[3];
                 $ids = explode(',', $idsStr);

                 foreach ($ids as $id) {
                     $id = trim($id);
                     if (isset($this->rows[$id])) {
                         $this->rows[$id]['status'] = $newStatus;
                     }
                 }
                 return count($ids);
             }
             return 0;
        }

        public function get_results($query, $output = OBJECT) {
            // Naive simulation of SELECT * FROM ... WHERE status = 'pending' ... LIMIT X
            // We just grab the first X pending items

            $limit = 5; // Default if not parsed
            if (preg_match('/LIMIT (\d+)/', $query, $matches)) {
                $limit = (int)$matches[1];
            }

            $results = [];
            foreach ($this->rows as $row) {
                if ($row['status'] === 'pending') {
                    $results[] = $row;
                    if (count($results) >= $limit) break;
                }
            }

            return $results;
        }
    }

    global $wpdb;
    $wpdb = new wpdb();

    // Mock functions
    if (!function_exists('get_option')) {
        function get_option($option, $default = false) {
            return $default;
        }
    }

    if (!function_exists('update_option')) {
        function update_option($option, $value, $autoload = null) {
            return true;
        }
    }

    if (!function_exists('get_transient')) {
        $mock_transients = [];
        function get_transient($transient) {
            global $mock_transients;
            return $mock_transients[$transient] ?? false;
        }
    }

    if (!function_exists('set_transient')) {
        function set_transient($transient, $value, $expiration = 0) {
            global $mock_transients;
            $mock_transients[$transient] = $value;
            return true;
        }
    }

    if (!function_exists('delete_transient')) {
        function delete_transient($transient) {
            global $mock_transients;
            unset($mock_transients[$transient]);
            return true;
        }
    }

    if (!function_exists('current_time')) {
        function current_time($type, $gmt = 0) {
            return date('Y-m-d H:i:s');
        }
    }

    if (!function_exists('wp_next_scheduled')) {
        function wp_next_scheduled($hook, $args = []) {
            return false;
        }
    }

    if (!function_exists('wp_schedule_single_event')) {
        function wp_schedule_single_event($timestamp, $hook, $args = []) {
            return true;
        }
    }

    if (!function_exists('wp_mail')) {
        function wp_mail($to, $subject, $message, $headers = '', $attachments = []) {
            return true;
        }
    }

    if (!function_exists('add_action')) {
        function add_action($tag, $function_to_add, $priority = 10, $accepted_args = 1) {
            return true;
        }
    }

    if (!function_exists('remove_action')) {
        function remove_action($tag, $function_to_remove, $priority = 10) {
            return true;
        }
    }

    // Load EmailService
    require_once __DIR__ . '/../src/Email/EmailService.php';

    use AperturePro\Email\EmailService;

    // Benchmark Configuration
    $num_emails = 500;
    echo "Benchmarking Optimized Email Queue with $num_emails emails...\n";

    // Phase 1: Enqueuing
    echo "1. Enqueuing emails...\n";
    $start_enqueue = microtime(true);

    for ($i = 0; $i < $num_emails; $i++) {
        EmailService::enqueueTransactionalEmail(
            "user{$i}@example.com",
            "Subject $i",
            "Body content for email $i"
        );

        // Log progress every 100 emails
        if (($i + 1) % 100 == 0) {
            $elapsed = microtime(true) - $start_enqueue;
            echo "   Enqueued " . ($i + 1) . " emails in " . number_format($elapsed, 4) . "s\n";
        }
    }

    $end_enqueue = microtime(true);
    $enqueue_time = $end_enqueue - $start_enqueue;
    echo "   Total Enqueue Time: " . number_format($enqueue_time, 4) . " seconds\n";
    echo "   Avg Enqueue Time: " . number_format(($enqueue_time / $num_emails) * 1000, 4) . " ms/email\n\n";

    // Phase 2: Processing (Simulated)
    echo "2. Processing emails...\n";
    $start_process = microtime(true);

    $max_loops = ceil($num_emails / EmailService::TRANSACTIONAL_MAX_PER_RUN) + 10;
    $loops = 0;

    // In optimized version, processTransactionalQueue should just work against the mock DB
    while ($loops < $max_loops) {
        // Check if pending items exist
        $count = $wpdb->get_var("SELECT COUNT(*) FROM table WHERE status = 'pending'");
        if ($count == 0) {
            break;
        }

        EmailService::processTransactionalQueue();
        $loops++;

        if ($loops % 50 == 0) {
            echo "   Processed batch $loops...\n";
        }
    }

    $end_process = microtime(true);
    $process_time = $end_process - $start_process;

    echo "   Total Process Time: " . number_format($process_time, 4) . " seconds\n";
    echo "   Total Loops: $loops\n";

    // Verify
    $remaining = $wpdb->get_var("SELECT COUNT(*) FROM table WHERE status = 'pending'");
    echo "   Remaining in queue: $remaining\n";
}
