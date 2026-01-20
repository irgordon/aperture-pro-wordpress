<?php
/**
 * Benchmark for Email Queue Processing with SMTP KeepAlive Simulation
 * Usage: php tests/benchmark_email_queue_keepalive.php
 */

namespace AperturePro\Tests;

// Mock Logger
class MockLogger {
    public static function log($level, $context, $message, $meta = []) {
        // echo "[$level] $context: $message\n";
    }
}

// Mock WordPress functions
if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        return [];
    }
}
if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) {
        return true;
    }
}
if (!function_exists('get_transient')) {
    function get_transient($transient) {
        return false;
    }
}
if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration) {
        return true;
    }
}
if (!function_exists('delete_transient')) {
    function delete_transient($transient) {
        return true;
    }
}
if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook, $args = []) {
        return true;
    }
}
if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event($timestamp, $hook, $args = []) {
        return true;
    }
}
if (!function_exists('current_time')) {
    function current_time($type) {
        return date('Y-m-d H:i:s');
    }
}
if (!function_exists('add_action')) {
    function add_action($tag, $callback) {
        global $wp_actions;
        $wp_actions[$tag][] = $callback;
    }
}
if (!function_exists('remove_action')) {
    function remove_action($tag, $callback) {
        global $wp_actions;
        // Simplified removal
        if (isset($wp_actions[$tag])) {
             // array_filter... for now just dummy
        }
    }
}
if (!function_exists('do_action')) {
    function do_action($tag, ...$args) {
        global $wp_actions;
        if (isset($wp_actions[$tag])) {
            foreach ($wp_actions[$tag] as $callback) {
                call_user_func_array($callback, $args);
            }
        }
    }
}

// Mock PHPMailer
class MockPHPMailer {
    public $SMTPKeepAlive = false;
    private $connected = false;

    public function smtpConnect() {
        if ($this->connected && $this->SMTPKeepAlive) {
            return true;
        }
        usleep(100000); // 100ms connect latency
        $this->connected = true;
        return true;
    }

    public function smtpClose() {
        if ($this->connected) {
            usleep(10000); // 10ms close latency
            $this->connected = false;
        }
    }

    public function send() {
        if (!$this->connected) {
            $this->smtpConnect();
        }
        usleep(50000); // 50ms send latency
        if (!$this->SMTPKeepAlive) {
            $this->smtpClose();
        }
        return true;
    }
}

// Global mock mailer
$mock_phpmailer = new MockPHPMailer();

if (!function_exists('wp_mail')) {
    function wp_mail($to, $subject, $message, $headers = '', $attachments = []) {
        global $mock_phpmailer;
        // Simulate wp_mail utilizing PHPMailer
        // In real WP, phpmailer_init fires here if not initialized
        do_action('phpmailer_init', $mock_phpmailer);

        return $mock_phpmailer->send();
    }
}

// Load Class Under Test
// We need to define the class structure or include it.
// Since we can't easily include the real file due to dependencies (Logger),
// we will simulate the logic in two functions: original and optimized.

function process_queue_original($count) {
    global $mock_phpmailer;
    $mock_phpmailer->SMTPKeepAlive = false;

    for ($i = 0; $i < $count; $i++) {
        wp_mail('test@example.com', 'Subject', 'Body');
    }
}

function process_queue_optimized($count) {
    global $mock_phpmailer;

    // Optimized Logic
    $keepAliveInit = function($mailer) {
        $mailer->SMTPKeepAlive = true;
    };
    add_action('phpmailer_init', $keepAliveInit);

    for ($i = 0; $i < $count; $i++) {
        wp_mail('test@example.com', 'Subject', 'Body');
    }

    // Cleanup
    remove_action('phpmailer_init', $keepAliveInit);
    $mock_phpmailer->smtpClose();
}

// --- Benchmark ---
$emailCount = 10;
echo "Benchmarking $emailCount emails...\n";

// Run Original
$start = microtime(true);
process_queue_original($emailCount);
$originalTime = microtime(true) - $start;
echo sprintf("Original: %.4f seconds\n", $originalTime);

// Run Optimized
// Reset connection state
$mock_phpmailer->smtpClose();
$start = microtime(true);
process_queue_optimized($emailCount);
$optimizedTime = microtime(true) - $start;
echo sprintf("Optimized: %.4f seconds\n", $optimizedTime);

$improvement = $originalTime / $optimizedTime;
echo sprintf("Improvement: %.2fx faster\n", $improvement);
