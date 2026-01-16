<?php

namespace {
    // Globals to control mock behavior
    $GLOBALS['ap_config_mock'] = [];

    // Mock WordPress functions
    function get_option($key, $default = false) {
        if ($key === 'aperture_pro_config') {
            return $GLOBALS['ap_config_mock'] ?: $default;
        }
        return $default;
    }

    function update_option($key, $value) {}

    function wp_upload_dir() {
        return [
            'basedir' => '/tmp/uploads',
            'baseurl' => 'http://example.com/uploads'
        ];
    }

    function wp_json_encode($data) {
        return json_encode($data);
    }

    function current_time($type) {
        return date('Y-m-d H:i:s');
    }

    // Mock Constants
    if (!defined('AUTH_KEY')) define('AUTH_KEY', 'test_auth_key');
    if (!defined('SECURE_AUTH_KEY')) define('SECURE_AUTH_KEY', 'test_secure_auth_key');
    if (!defined('LOGGED_IN_KEY')) define('LOGGED_IN_KEY', 'test_logged_in_key');
    if (!defined('OPENSSL_RAW_DATA')) define('OPENSSL_RAW_DATA', 1);

    // Include interfaces and helpers first
    require_once __DIR__ . '/../src/Storage/StorageInterface.php';
    require_once __DIR__ . '/../src/Config/Config.php';
    require_once __DIR__ . '/../src/Helpers/Crypto.php';
}

namespace Aws\S3 {
    class S3Client {
        public function __construct(array $args) {}
    }
}

namespace Aws\CloudFront {
    class CloudFrontClient {
        public function __construct(array $args) {}
    }
}

namespace AperturePro\Helpers {
    class Logger {
        public static function log($level, $context, $message, $meta = []) {}
    }
}

namespace AperturePro\Config {
    class Defaults {
        public static function all() { return []; }
    }
}

namespace AperturePro\Storage {
    use Aws\S3\S3Client;
    use Aws\CloudFront\CloudFrontClient;

    // Mock S3Storage that implements StorageInterface to satisfy StorageFactory
    class S3Storage implements StorageInterface {
        protected $s3;
        protected $bucket;
        protected $region;

        public function __construct(array $config)
        {
            $this->bucket = $config['bucket'] ?? '';
            $this->region = $config['region'] ?? '';

            $accessKey = $config['access_key'] ?? '';
            $secretKey = $config['secret_key'] ?? '';

            // This is the expensive part (creating client)
            $this->s3 = new S3Client([
                'version'     => 'latest',
                'region'      => $this->region,
                'credentials' => [
                    'key'    => $accessKey,
                    'secret' => $secretKey,
                ],
            ]);
        }

        public function upload(string $localPath, string $remoteKey, array $options = []): array { return []; }
        public function getUrl(string $remoteKey, array $options = []): ?string { return ''; }
        public function delete(string $remoteKey): bool { return true; }
        public function exists(string $remoteKey): bool { return true; }
        public function list(string $prefix = '', array $options = []): array { return []; }
    }
}

namespace {
    // Include the rest
    require_once __DIR__ . '/../src/Storage/LocalStorage.php';
    require_once __DIR__ . '/../src/Storage/StorageFactory.php';

    use AperturePro\Storage\StorageFactory;
    use AperturePro\Helpers\Crypto;

    // Prepare Config with Encrypted Credentials to force decryption overhead
    $encrypted = Crypto::encrypt('super_secret_key');

    $GLOBALS['ap_config_mock'] = [
        'storage' => [
            'driver' => 's3',
            's3' => [
                'bucket' => 'test-bucket',
                'region' => 'us-east-1',
                'access_key' => $encrypted,
                'secret_key' => $encrypted,
            ]
        ]
    ];

    echo "Running Benchmark: StorageFactory Instantiation Overhead\n";
    $iterations = 1000;

    // 1. Baseline: Create new instance every time
    $start = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $storage = StorageFactory::make();
    }
    $durationBaseline = microtime(true) - $start;
    echo "Baseline (New Instance x $iterations): " . number_format($durationBaseline, 4) . " s\n";

    // 2. Optimized: Reuse instance
    $start = microtime(true);
    $storage = StorageFactory::make(); // Create once
    for ($i = 0; $i < $iterations; $i++) {
        // Simulating usage of the same instance
        $s = $storage;
    }
    $durationOptimized = microtime(true) - $start;
    echo "Optimized (Reuse Instance x $iterations): " . number_format($durationOptimized, 4) . " s\n";

    if ($durationOptimized > 0) {
        $speedup = $durationBaseline / $durationOptimized;
        echo "Speedup: " . number_format($speedup, 2) . "x\n";
    }
}
