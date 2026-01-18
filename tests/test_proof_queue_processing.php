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
    }

    // Setup
    $dummyFile = tempnam(sys_get_temp_dir(), 'test_orig');
    file_put_contents($dummyFile, 'dummy data');

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
