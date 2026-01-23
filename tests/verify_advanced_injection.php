<?php

define('ABSPATH', __DIR__ . '/../');

// Mocks
if (!class_exists('stdClass')) {
    // Should exist in PHP
}

$wpdb = new class {
    public $prefix = 'wp_';
    public function insert($table, $data, $format = null) {
        // No-op
        return true;
    }
};

function wp_json_encode($data) {
    return json_encode($data);
}
function current_time($type) {
    return date('Y-m-d H:i:s');
}
function get_option($option) {
    return 'admin@example.com';
}
function admin_url($path) {
    return 'http://example.com/wp-admin/' . $path;
}
function wp_mail($to, $subject, $message, $headers = '', $attachments = []) {
    return true;
}

require_once __DIR__ . '/../inc/autoloader.php';

use AperturePro\Environment;
use AperturePro\Loader;

echo "Testing Advanced Loader injection...\n";

class PathService
{
    public string $path;
    public function __construct(string $pluginPath)
    {
        $this->path = $pluginPath;
    }
    public function register(): void {}
}

class DependentService
{
    public PathService $pathService;
    public function __construct(PathService $pathService)
    {
        $this->pathService = $pathService;
    }
    public function register(): void {}
}

$env = new Environment('/my/plugin/path', 'https://url', '1.0.0');
$loader = new Loader($env);

try {
    echo "Registering PathService...\n";
    $loader->registerService(PathService::class);
    $services = $loader->getServices();

    // We expect this to fail initially
    if (!isset($services[PathService::class])) {
        echo "EXPECTED FAILURE: PathService not registered (Missing injection logic).\n";
    } else {
        $pathService = $services[PathService::class];
        if ($pathService->path !== '/my/plugin/path') {
            echo "FAILED: Path was not injected correctly. Expected '/my/plugin/path', got '{$pathService->path}'\n";
        } else {
            echo "PathService registered and injected successfully.\n";
        }
    }

    echo "Registering DependentService...\n";
    $loader->registerService(DependentService::class);
    $services = $loader->getServices();

    if (!isset($services[DependentService::class])) {
        echo "EXPECTED FAILURE: DependentService not registered (Missing injection logic).\n";
    } else {
        $dependent = $services[DependentService::class];
        if (!$dependent->pathService instanceof PathService) {
            echo "FAILED: PathService was not injected into DependentService.\n";
        } else {
            echo "DependentService registered and injected successfully.\n";
        }
    }

} catch (\Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
}
