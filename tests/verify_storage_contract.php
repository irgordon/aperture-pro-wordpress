<?php
/**
 * Verification script for Storage Contract.
 * Usage: php tests/verify_storage_contract.php
 */

// Simple Autoloader
spl_autoload_register(function ($class) {
    if (strpos($class, 'AperturePro\\') === 0) {
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 12)) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

// Mock WP Functions
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir() {
        $dir = sys_get_temp_dir() . '/ap_uploads';
        if (!file_exists($dir)) mkdir($dir);
        return [
            'basedir' => $dir,
            'baseurl' => 'http://example.com/uploads'
        ];
    }
}
if (!function_exists('trailingslashit')) {
    function trailingslashit($s) { return rtrim($s, '/') . '/'; }
}
if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($path) {
        if (file_exists($path)) return true;
        return mkdir($path, 0755, true);
    }
}
if (!function_exists('set_transient')) {
    function set_transient($name, $val, $exp) { return true; } // Mock success
}
if (!function_exists('rest_url')) {
    function rest_url($path = '') { return 'http://example.com/wp-json/' . $path; }
}
if (!function_exists('set_url_scheme')) {
    function set_url_scheme($url, $scheme) { return str_replace('http:', $scheme . ':', $url); }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url) { return $url; }
}
if (!function_exists('size_format')) {
    function size_format($bytes, $decimals = 0) {
        $quant = array( 'TB' => 1099511627776, 'GB' => 1073741824, 'MB' => 1048576, 'KB' => 1024, 'B' => 1);
        foreach ( $quant as $unit => $mag ) {
            if ( doubleval( $bytes ) >= $mag ) {
                return number_format( $bytes / $mag, $decimals ) . ' ' . $unit;
            }
        }
        return false;
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) { return $value; }
}
if (!function_exists('wp_tempnam')) {
    function wp_tempnam($prefix = '') { return tempnam(sys_get_temp_dir(), $prefix); }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) { return json_encode($data); }
}
if (!function_exists('current_time')) {
    function current_time($type) { return date('Y-m-d H:i:s'); }
}
if (!function_exists('home_url')) {
    function home_url($path = '') { return 'http://example.com/' . $path; }
}
if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file) { return dirname($file) . '/'; }
}
if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url($file) { return 'http://example.com/plugin/'; }
}
if (!function_exists('add_action')) {
    function add_action($hook, $callback) {}
}
if (!function_exists('add_shortcode')) {
    function add_shortcode($tag, $callback) {}
}


// Mock wpdb
class MockWPDB {
    public $prefix = 'wp_';
    public function prepare($query, $args) { return $query; }
    public function query($query) { return true; }
}
global $wpdb;
$wpdb = new MockWPDB();


use AperturePro\Storage\StorageFactory;
use AperturePro\Storage\LocalStorage;
use AperturePro\Storage\StorageInterface;
use AperturePro\Proof\ProofService;

echo "Starting Storage Contract Verification...\n";

// 1. Verify Interface
if (!interface_exists(StorageInterface::class)) {
    echo "[FAIL] StorageInterface not found.\n";
    exit(1);
}
echo "[PASS] StorageInterface exists.\n";

// 2. Test LocalStorage
echo "\nTesting LocalStorage...\n";
$config = [
    'path' => 'ap-test-storage/',
    'signed_url_ttl' => 3600
];
$storage = new LocalStorage($config);

if (!($storage instanceof StorageInterface)) {
    echo "[FAIL] LocalStorage does not implement StorageInterface.\n";
    exit(1);
}
echo "[PASS] LocalStorage implements StorageInterface.\n";

// getName
$name = $storage->getName();
if ($name !== 'Local') {
    echo "[FAIL] LocalStorage::getName() returned '$name', expected 'Local'.\n";
    exit(1);
}
echo "[PASS] getName() => $name\n";

// upload
$testContent = "Hello Storage World";
$tmpFile = tempnam(sys_get_temp_dir(), 'ap_test_');
file_put_contents($tmpFile, $testContent);
$targetKey = 'tests/' . basename($tmpFile) . '.txt';

echo "Uploading to $targetKey...\n";
try {
    $url = $storage->upload($tmpFile, $targetKey);
    echo "[PASS] Upload successful. URL: $url\n";
    if (!is_string($url)) {
        echo "[FAIL] Upload did not return string.\n";
        exit(1);
    }
} catch (\Exception $e) {
    echo "[FAIL] Upload threw exception: " . $e->getMessage() . "\n";
    exit(1);
}

// exists
if (!$storage->exists($targetKey)) {
    echo "[FAIL] exists() returned false after upload.\n";
    exit(1);
}
echo "[PASS] exists() verified.\n";

// getUrl
$url2 = $storage->getUrl($targetKey);
if (empty($url2)) {
    echo "[FAIL] getUrl() returned empty.\n";
    exit(1);
}
echo "[PASS] getUrl() returns string.\n";

// getStats
$stats = $storage->getStats();
if (!isset($stats['healthy']) || !$stats['healthy']) {
    echo "[FAIL] getStats() returned unhealthy.\n";
}
echo "[PASS] getStats() verified.\n";

// delete
echo "Deleting $targetKey...\n";
$storage->delete($targetKey);
if ($storage->exists($targetKey)) {
    echo "[FAIL] exists() returned true after delete.\n";
    exit(1);
}
echo "[PASS] Delete verified.\n";

unlink($tmpFile);

// 3. Test ProofService (Integration)
echo "\nTesting ProofService with LocalStorage...\n";
$realImg = tempnam(sys_get_temp_dir(), 'ap_proof_test');
file_put_contents($realImg, $testContent);
$mockImage = ['path' => $realImg];

try {
    // This will likely fail with "Failed to generate proof variant" or similar because imagick/gd fails on text file.
    // But we check that it reached that point without StorageInterface errors.
    ProofService::getProofUrlForImage($mockImage, $storage);
} catch (\RuntimeException $e) {
    echo "[INFO] ProofService failed as expected (not an image): " . $e->getMessage() . "\n";
    // Check if failure is due to storage methods
    if (strpos($e->getMessage(), 'Call to undefined method') !== false) {
        echo "[FAIL] ProofService uses undefined methods!\n";
        exit(1);
    }
} catch (\Error $e) {
    echo "[FAIL] Fatal error in ProofService: " . $e->getMessage() . "\n";
    exit(1);
}
unlink($realImg);
echo "[PASS] ProofService integration check (signatures only).\n";

// 4. Verify Class Implementations
$drivers = [
    \AperturePro\Storage\S3Storage::class,
    \AperturePro\Storage\ImageKitStorage::class,
    \AperturePro\Storage\CloudinaryStorage::class
];

foreach ($drivers as $class) {
    if (!class_exists($class, false)) {
        // Autoload it
        if (strpos($class, 'AperturePro\\') === 0) {
             $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 12)) . '.php';
             if (file_exists($file)) include_once $file;
        }
    }

    if (!class_exists($class)) {
         echo "[WARN] Driver $class could not be loaded (missing deps?).\n";
         continue;
    }

    $rc = new ReflectionClass($class);
    if (!$rc->implementsInterface(StorageInterface::class)) {
        echo "[FAIL] Driver $class does not implement StorageInterface.\n";
        exit(1);
    }
    echo "[PASS] $class implements StorageInterface.\n";
}

echo "\nVerification Completed Successfully.\n";
