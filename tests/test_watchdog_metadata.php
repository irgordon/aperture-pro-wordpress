<?php

namespace {
    // Mock WordPress environment
    $mock_transients = [];
    $mock_options = [];

    function wp_upload_dir() {
        return ['basedir' => sys_get_temp_dir() . '/ap_test_uploads'];
    }

    function trailingslashit($string) {
        return rtrim($string, '/') . '/';
    }

    function get_transient($transient) {
        global $mock_transients;
        return $mock_transients[$transient] ?? false;
    }

    function set_transient($transient, $value, $expiration = 0) {
        global $mock_transients;
        $mock_transients[$transient] = $value;
        return true;
    }

    function delete_transient($transient) {
        global $mock_transients;
        unset($mock_transients[$transient]);
        return true;
    }

    function current_time($type) {
        return date('Y-m-d H:i:s');
    }

    function is_wp_error($thing) {
        return false;
    }

    function sanitize_file_name($name) {
        return preg_replace('/[^a-zA-Z0-9_.-]/', '', $name);
    }

    function sanitize_text_field($str) {
        return trim($str);
    }

    function wp_mkdir_p($path) {
        return mkdir($path, 0777, true);
    }

    function maybe_unserialize($original) {
        if (is_serialized($original)) {
            return @unserialize($original);
        }
        return $original;
    }

    function is_serialized($data, $strict = true) {
        if (!is_string($data)) return false;
        $data = trim($data);
        if ('N;' == $data) return true;
        if (strlen($data) < 4) return false;
        if (':' !== $data[1]) return false;
        if ($strict) {
            $lastc = substr($data, -1);
            if (';' !== $lastc && '}' !== $lastc) return false;
        }
        $token = $data[0];
        switch ($token) {
            case 's': case 'a': case 'O': case 'b': case 'd': case 'i': return true;
        }
        return false;
    }

    function wp_using_ext_object_cache() {
        return false;
    }

    // Mock WPDB
    class MockWPDB {
        public $options = 'wp_options';
        public $prefix = 'wp_';

        public function get_results($query, $output = 'OBJECT') {
            global $mock_transients;
            $results = [];
            foreach ($mock_transients as $key => $value) {
                $row = new \stdClass();
                $row->option_name = '_transient_' . $key;
                $row->option_value = serialize($value);
                $results[] = $row;
            }
            return $results;
        }

        public function prepare($query, ...$args) {
            return $query;
        }
    }

    global $wpdb;
    $wpdb = new MockWPDB();
}

// Mocks for dependencies
namespace AperturePro\Helpers {
    class Logger {
        public static function log($level, $context, $message, $data = []) {
            if ($level === 'error') {
                 echo "LOG [$level]: $message\n";
                 if (!empty($data)) print_r($data);
            }
        }
    }
    class Utils {}
}

namespace AperturePro\Email {
    class EmailService {
        public static function enqueueAdminNotification($level, $type, $subject, $data) {}
    }
}

namespace AperturePro\Auth {
    class CookieService {}
}

namespace AperturePro\Storage {
    interface StorageInterface {
        public function upload($source, $destination, $options = []);
        public function uploadMany(array $files);
    }

    class MockStorage implements StorageInterface {
        public $uploaded = [];

        public function upload($source, $destination, $options = []) {
            $this->uploaded[] = ['source' => $source, 'destination' => $destination];
            return ['success' => true, 'key' => $destination];
        }

        public function uploadMany(array $files) {
            $results = [];
            foreach ($files as $file) {
                $this->uploaded[] = ['source' => $file['source'], 'destination' => $file['target']];
                $results[$file['target']] = ['success' => true, 'key' => $file['target']];
            }
            return $results;
        }
    }

    class StorageFactory {
        public static $mockStorage;
        public static function make() {
            if (!self::$mockStorage) {
                self::$mockStorage = new MockStorage();
            }
            return self::$mockStorage;
        }
    }
}

namespace {
    use AperturePro\Upload\Watchdog;
    use AperturePro\Upload\ChunkedUploadHandler;
    use AperturePro\Storage\StorageFactory;

    // Load code under test
    require_once __DIR__ . '/../src/Upload/ChunkedUploadHandler.php';
    require_once __DIR__ . '/../src/Upload/Watchdog.php';

    // Helper to cleanup
    function cleanup_test_dir() {
        $dir = sys_get_temp_dir() . '/ap_test_uploads';
        if (is_dir($dir)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
            rmdir($dir);
        }
    }

    // TEST EXECUTION
    cleanup_test_dir();

    // 1. Setup orphan session with assembled file and NO transient
    $uploads = wp_upload_dir();
    $baseDir = trailingslashit($uploads['basedir']) . 'aperture-uploads/';
    $uploadId = 'test_orphan_session';
    $sessionDir = $baseDir . $uploadId . '/';

    if (!file_exists($sessionDir)) {
        mkdir($sessionDir, 0777, true);
    }

    // Create assembled file
    $assembledFile = $sessionDir . ChunkedUploadHandler::ASSEMBLED_FILENAME;
    file_put_contents($assembledFile, 'fake_image_content');

    // Create session.json (simulate persisted metadata)
    $meta = [
        'upload_id' => $uploadId,
        'project_id' => 123,
        'meta' => [
            'original_filename' => 'photo.jpg',
            'storage_key' => 'projects/123/photo.jpg'
        ]
    ];
    file_put_contents($sessionDir . 'session.json', json_encode($meta));

    echo "Running Watchdog...\n";
    Watchdog::run();

    $storage = StorageFactory::$mockStorage;
    if (empty($storage->uploaded)) {
        echo "FAILED: No upload attempted.\n";
        exit(1);
    }

    $lastUpload = end($storage->uploaded);
    $destination = $lastUpload['destination'];

    echo "Upload destination: " . $destination . "\n";

    $expectedKeyBase = 'uploads/123/' . $uploadId . '/photo.jpg';

    if ($destination === $expectedKeyBase) {
        echo "SUCCESS: Watchdog used metadata from session.json.\n";
    } else {
        echo "FAILURE: Watchdog did not use metadata. Got: $destination\n";
        // Check if it used the fallback (orphan path)
        if (strpos($destination, 'orphaned/' . $uploadId) !== false) {
            echo "Reason: Watchdog used fallback orphaned path.\n";
        }
        exit(1);
    }

    // Verify Cleanup
    if (file_exists($sessionDir)) {
        echo "FAILURE: Session directory was not cleaned up.\n";
        $files = scandir($sessionDir);
        echo "Remaining files: " . implode(', ', $files) . "\n";
        exit(1);
    } else {
        echo "SUCCESS: Session directory cleaned up.\n";
    }

    cleanup_test_dir();

    // 2. Test Fallback (No session.json)
    echo "\nRunning Fallback Test (No session.json)...\n";
    $uploads = wp_upload_dir();
    $baseDir = trailingslashit($uploads['basedir']) . 'aperture-uploads/';
    $uploadId = 'test_fallback_session';
    $sessionDir = $baseDir . $uploadId . '/';

    if (!file_exists($sessionDir)) {
        mkdir($sessionDir, 0777, true);
    }
    $assembledFile = $sessionDir . ChunkedUploadHandler::ASSEMBLED_FILENAME;
    file_put_contents($assembledFile, 'fake_image_content');

    // Reset storage
    StorageFactory::$mockStorage = new \AperturePro\Storage\MockStorage();

    Watchdog::run();

    $storage = StorageFactory::$mockStorage;
    if (empty($storage->uploaded)) {
        echo "FAILED: No upload attempted in fallback.\n";
        exit(1);
    }
    $lastUpload = end($storage->uploaded);
    $destination = $lastUpload['destination'];

    echo "Fallback Upload destination: " . $destination . "\n";
    if (strpos($destination, 'orphaned/' . $uploadId) !== false) {
        echo "SUCCESS: Watchdog used fallback for missing metadata.\n";
    } else {
        echo "FAILURE: Watchdog did not use fallback. Got: $destination\n";
        exit(1);
    }

    cleanup_test_dir();
}
