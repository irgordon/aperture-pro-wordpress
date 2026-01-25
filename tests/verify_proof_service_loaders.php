<?php

namespace AperturePro\Config {
    if (!class_exists('AperturePro\Config\Config')) {
        class Config {
            public static function get($key, $default = null) {
                return $default;
            }
        }
    }
}

namespace AperturePro\Helpers {
    if (!class_exists('AperturePro\Helpers\Logger')) {
        class Logger {
            public static $logs = [];
            public static function log($level, $context, $message, $data = []) {
                self::$logs[] = "$level: $message";
            }
            public static function clear() {
                self::$logs = [];
            }
        }
    }
}

namespace AperturePro\Storage {
    if (!interface_exists('AperturePro\Storage\StorageInterface')) {
        interface StorageInterface {
            public function getUrl(string $path, array $options = []): string;
            public function upload(string $source, string $destination, array $options = []): array;
            public function exists(string $path): bool;
        }
    }
    if (!class_exists('AperturePro\Storage\StorageFactory')) {
        class StorageFactory {
            public static function create() { return null; }
        }
    }
}

namespace {
    // Mock WP functions
    if (!function_exists('wp_tempnam')) {
        function wp_tempnam($prefix) {
            return tempnam(sys_get_temp_dir(), $prefix);
        }
    }
    if (!function_exists('apply_filters')) {
        function apply_filters($tag, $value) { return $value; }
    }
    if (!function_exists('plugins_url')) {
        function plugins_url() { return ''; }
    }
    if (!function_exists('is_wp_error')) {
        function is_wp_error($thing) { return false; }
    }
    if (!function_exists('wp_remote_retrieve_response_code')) {
        function wp_remote_retrieve_response_code($response) { return 200; }
    }
    if (!defined('APERTURE_PRO_FILE')) {
        define('APERTURE_PRO_FILE', __FILE__);
    }

    // Load source
    require_once __DIR__ . '/../src/Proof/ProofService.php';

    use AperturePro\Proof\ProofService;

    // Expose protected method
    class TestableProofService extends ProofService {
        public static function testCreateWatermarkedLowRes(string $localOriginal): ?string {
            return parent::createWatermarkedLowRes($localOriginal);
        }
    }

    // Test Suite
    echo "Starting ProofService Loader Verification...\n";

    // 1. Verify BMP Loading (Optimization)
    $bmpFile = tempnam(sys_get_temp_dir(), 'test_bmp') . '.bmp';
    $im = imagecreatetruecolor(100, 100);
    // Fill with color
    imagefilledrectangle($im, 0, 0, 99, 99, imagecolorallocate($im, 255, 0, 0));

    if (function_exists('imagebmp')) {
        imagebmp($im, $bmpFile);
    } else {
        imagegif($im, $bmpFile);
    }
    imagedestroy($im);

    if (function_exists('imagecreatefrombmp')) {
        echo "Testing BMP optimization...\n";
        $proof = TestableProofService::testCreateWatermarkedLowRes($bmpFile);
        if ($proof && file_exists($proof)) {
            echo "PASS: BMP Proof generated.\n";
            unlink($proof);
        } else {
            echo "FAIL: BMP Proof failed.\n";
        }
    } else {
        echo "SKIP: imagecreatefrombmp not available.\n";
    }
    @unlink($bmpFile);


    // 2. Verify GIF Loading
    $gifFile = tempnam(sys_get_temp_dir(), 'test_gif') . '.gif';
    $im = imagecreatetruecolor(100, 100);
    imagefilledrectangle($im, 0, 0, 99, 99, imagecolorallocate($im, 0, 255, 0));
    imagegif($im, $gifFile);
    imagedestroy($im);

    echo "Testing GIF optimization...\n";
    $proof = TestableProofService::testCreateWatermarkedLowRes($gifFile);
    if ($proof && file_exists($proof)) {
        echo "PASS: GIF Proof generated.\n";
        unlink($proof);
    } else {
        echo "FAIL: GIF Proof failed.\n";
    }
    @unlink($gifFile);


    // 3. Verify Large File Safety Check
    echo "Testing Large File Safety Check (>50MB)...\n";
    $largeFile = tempnam(sys_get_temp_dir(), 'test_large') . '.bin';

    // Create sparse file (fast) or just write zeros.
    $fp = fopen($largeFile, 'w');
    fseek($fp, 50 * 1024 * 1024);
    fwrite($fp, '0');
    fclose($fp);

    // Clear logs
    \AperturePro\Helpers\Logger::clear();

    $proof = TestableProofService::testCreateWatermarkedLowRes($largeFile);

    if ($proof === null) {
        // Check logs
        $logs = \AperturePro\Helpers\Logger::$logs;
        $found = false;
        foreach ($logs as $log) {
            if (strpos($log, 'Image too large for fallback string loading') !== false) {
                $found = true;
                break;
            }
        }

        if ($found) {
            echo "PASS: Large file rejected and logged.\n";
        } else {
            echo "FAIL: Large file rejected but not logged properly.\nLogs: " . implode("\n", $logs) . "\n";
        }
    } else {
        echo "FAIL: Large file was processed (should be rejected).\n";
        @unlink($proof);
    }

    @unlink($largeFile);
}
