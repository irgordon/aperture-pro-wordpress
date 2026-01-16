<?php

namespace Aws\S3 {
    if (!class_exists('Aws\S3\S3Client')) { class S3Client {} }
}
namespace Aws\CloudFront {
    if (!class_exists('Aws\CloudFront\CloudFrontClient')) { class CloudFrontClient {} }
    if (!class_exists('Aws\CloudFront\UrlSigner')) { class UrlSigner {} }
}
namespace ImageKit {
    if (!class_exists('ImageKit\ImageKit')) { class ImageKit {} }
}
namespace {
    // Mocks
    if (!function_exists('wp_upload_dir')) { function wp_upload_dir() { return ['basedir'=>'/tmp', 'baseurl'=>'http://localhost']; } }
    if (!function_exists('wp_mkdir_p')) { function wp_mkdir_p($p) { return true; } }
    if (!function_exists('trailingslashit')) { function trailingslashit($p) { return rtrim($p, '/') . '/'; } }
    if (!function_exists('set_transient')) { function set_transient($k,$v,$e) { return true; } }
    if (!function_exists('rest_url')) { function rest_url($p) { return "http://api/$p"; } }
    if (!function_exists('set_url_scheme')) { function set_url_scheme($u, $s) { return $u; } }
    if (!function_exists('esc_url_raw')) { function esc_url_raw($u) { return $u; } }
    if (!function_exists('apply_filters')) { function apply_filters($t, $v) { return $v; } }
    if (!function_exists('current_time')) { function current_time($type) { return date('Y-m-d H:i:s'); } }
    if (!function_exists('wp_json_encode')) { function wp_json_encode($d) { return json_encode($d); } }
    if (!function_exists('get_option')) { function get_option($o) { return ''; } }
    if (!function_exists('admin_url')) { function admin_url($p) { return "http://admin/$p"; } }
    if (!function_exists('wp_mail')) { function wp_mail() { return true; } }

    // Load files
    require_once __DIR__ . '/../src/Storage/StorageInterface.php';
    require_once __DIR__ . '/../src/Helpers/Logger.php';
    require_once __DIR__ . '/../src/Storage/Traits/Retryable.php';
    require_once __DIR__ . '/../src/Storage/S3Storage.php';
    require_once __DIR__ . '/../src/Storage/ImageKitStorage.php';
    require_once __DIR__ . '/../src/Storage/LocalStorage.php';

    use AperturePro\Storage\StorageInterface;
    use AperturePro\Storage\S3Storage;
    use AperturePro\Storage\ImageKitStorage;
    use AperturePro\Storage\LocalStorage;

    $drivers = [
        S3Storage::class,
        ImageKitStorage::class,
        LocalStorage::class,
    ];

    echo "Verifying drivers...\n";

    foreach ($drivers as $driver) {
        echo "Checking $driver...\n";
        $rc = new \ReflectionClass($driver);

        if (!$rc->implementsInterface(StorageInterface::class)) {
            die("FAILED: $driver does not implement StorageInterface\n");
        }

        // Check putFile existence (compat)
        if (!$rc->hasMethod('putFile')) {
            die("FAILED: $driver missing putFile method\n");
        }

        // Check StorageInterface methods
        $interface = new \ReflectionClass(StorageInterface::class);
        foreach ($interface->getMethods() as $method) {
            if (!$rc->hasMethod($method->getName())) {
                 die("FAILED: $driver missing interface method " . $method->getName() . "\n");
            }
        }

        echo "  OK\n";
    }

    echo "All drivers verified.\n";
}
