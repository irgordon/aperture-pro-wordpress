<?php

namespace AperturePro\Helpers {
    class Logger {
        public static function log($level, $context, $message, $data = []) {
            // No-op for benchmark
        }
    }
}

namespace {
    // Mock WordPress functions
    if (!function_exists('get_option')) {
        $mock_options = [];
        function get_option($option, $default = false) {
            global $mock_options;
            $val = $mock_options[$option] ?? $default;
            // Simulate WP behavior: data is stored serialized in DB
            // So getting it involves unserializing (if we simulate the full round trip)
            // But usually WP caches the unserialized value.
            // However, for the purpose of demonstrating the "option bloat" issue,
            // we should simulate that every update involves serializing the WHOLE array.
            return $val;
        }
    }

    if (!function_exists('update_option')) {
        function update_option($option, $value, $autoload = null) {
            global $mock_options;
            $mock_options[$option] = $value;

            // Simulate the cost of serialization which happens on every write to DB
            $serialized = serialize($value);
            // Simulate DB write latency (tiny but present, proportional to size)
            // 0.1ms base + 0.001ms per KB
            $size = strlen($serialized);
            usleep(100 + ($size / 1024));

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
    echo "Benchmarking Email Queue with $num_emails emails...\n";

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

    while ($loops < $max_loops) {
        global $mock_options;
        $queue = $mock_options[EmailService::TRANSACTIONAL_QUEUE_OPTION] ?? [];

        if (empty($queue)) {
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

    // Verify queue is empty
    global $mock_options;
    $final_queue = $mock_options[EmailService::TRANSACTIONAL_QUEUE_OPTION] ?? [];
    echo "   Remaining in queue: " . count($final_queue) . "\n";
}
