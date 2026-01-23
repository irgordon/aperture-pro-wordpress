<?php
/**
 * Test Proof Queue Processing
 * Usage: php tests/test_proof_queue_processing.php
 */

namespace AperturePro\Storage {
    class StorageFactory
    {
        public static $mockInstance;
        public static function create() {
            return self::$mockInstance;
        }
    }
}

namespace AperturePro\Proof {
    // Override function_exists to disable parallel downloads in test
    function function_exists($func) {
        if ($func === 'curl_multi_init') return false;
        return \function_exists($func);
    }
}

namespace {
    $mock_options = [];
    function get_option($option, $default = false) {
        global $mock_options;
        return $mock_options[$option] ?? $default;
    }
    function update_option($option, $value, $autoload = null) {
        global $mock_options;
        $mock_options[$option] = $value;
        return true;
    }
    function current_time($type, $gmt = 0) { return date('Y-m-d H:i:s'); }
    function wp_next_scheduled($hook, $args = []) { return false; }
    function wp_schedule_single_event($timestamp, $hook, $args = []) { return true; }
    function get_transient($transient) { return false; }
    function set_transient($transient, $value, $expiration) { return true; }
    function delete_transient($transient) { return true; }
    function wp_tempnam($prefix = '') { return tempnam(sys_get_temp_dir(), $prefix); }
    function apply_filters($tag, $value) { return $value; }

    function is_wp_error($thing) { return false; }
    function wp_remote_retrieve_response_code($response) { return $response['response']['code'] ?? 500; }
    function wp_remote_get($url, $args = []) {
        if (isset($args['stream']) && $args['stream'] && isset($args['filename'])) {
            // Mimic download: copy source ($url in this test is a local path) to target
            copy($url, $args['filename']);
        }
        return ['response' => ['code' => 200]];
    }
}

namespace AperturePro\Test {

    require_once __DIR__ . '/../src/Storage/StorageInterface.php';
    // Do NOT require real StorageFactory
    require_once __DIR__ . '/../src/Proof/ProofCache.php';
    require_once __DIR__ . '/../src/Proof/ProofService.php';
    require_once __DIR__ . '/../src/Proof/ProofQueue.php';

    // Mock Logger
    class MockLogger {
        public static $logs = [];
        public static function log($level, $context, $message, $data = []) {
            self::$logs[] = "$level: $message";
        }
    }
    if (!class_exists('AperturePro\Helpers\Logger')) {
        class_alias('AperturePro\Test\MockLogger', 'AperturePro\Helpers\Logger');
    }

    use AperturePro\Storage\StorageInterface;
    use AperturePro\Storage\StorageFactory;
    use AperturePro\Proof\ProofQueue;

    // Mock Storage
    class MockQueueStorage implements StorageInterface
    {
        public $uploads = [];
        public $dummyFile;

        public function getName(): string { return 'MockQueue'; }

        public function upload(string $source, string $target, array $options = []): string {
            $this->uploads[] = $target;
            return 'url';
        }

        public function delete(string $target): void {}
        public function getUrl(string $target, array $options = []): string {
            return $this->dummyFile;
        }
        public function exists(string $target): bool { return false; }
        public function getStats(): array { return []; }
        public function existsMany(array $targets): array { return []; }
        public function getLocalPath(string $path): ?string { return null; }
        public function sign(string $path): ?string { return 'signed_' . $path; }
        public function signMany(array $paths): array {
            $r = [];
            foreach ($paths as $p) $r[$p] = 'signed_' . $p;
            return $r;
        }
    }

    // Setup
    // Mock WPDB
    $wpdb = new class {
        public $prefix = 'wp_';
        public function prepare($query, ...$args) { return $query; }
        public function get_var($query) { return null; }
        public function esc_like($text) { return $text; }
        public function query($query) { return true; }
        public function get_results($query) { return []; }
    };
    $GLOBALS['wpdb'] = $wpdb;

    $dummyFile = tempnam(sys_get_temp_dir(), 'test_orig');
    file_put_contents($dummyFile, base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='));

    $storage = new MockQueueStorage();
    $storage->dummyFile = $dummyFile;
    StorageFactory::$mockInstance = $storage;

    echo "Enqueuing item...\n";
    ProofQueue::enqueue('projects/1/orig.jpg', 'proofs/1/orig_proof.jpg');

    $queue = \get_option(ProofQueue::QUEUE_OPTION);
    if (count($queue) !== 1) {
        echo "FAILED: Queue should have 1 item.\n";
        exit(1);
    }

    echo "Processing queue...\n";
    ProofQueue::processQueue();

    // Verify upload happened
    if (count($storage->uploads) === 1 && $storage->uploads[0] === 'proofs/1/orig_proof.jpg') {
        echo "SUCCESS: Item processed and uploaded.\n";
    } else {
        echo "FAILED: Item not uploaded.\n";
        print_r($storage->uploads);
        print_r(MockLogger::$logs);
        exit(1);
    }

    // Verify queue empty
    $queue = \get_option(ProofQueue::QUEUE_OPTION);
    if (count($queue) !== 0) {
        echo "FAILED: Queue should be empty. Got " . count($queue) . "\n";
        print_r($queue);
        exit(1);
    }

    // Cleanup
    @unlink($dummyFile);

}
