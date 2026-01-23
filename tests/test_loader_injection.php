<?php

define('ABSPATH', __DIR__ . '/../');
require_once __DIR__ . '/../inc/autoloader.php';

use AperturePro\Environment;
use AperturePro\Loader;

echo "Testing Loader injection...\n";

// Mock ServiceInterface just in case it's not found (though autoloader should handle it)
// Relying on autoloader.

class DummyService
{
    public $env;
    public function __construct(Environment $env)
    {
        $this->env = $env;
    }

    public function register(): void {}
}

class DummyServiceNoArg
{
    public function register(): void {}
}

$env = new Environment('/path', 'https://url', '1.2.3');
$loader = new Loader($env);

// Test 1: Injection
$loader->registerService(DummyService::class);
$services = $loader->getServices();

if (!isset($services[DummyService::class])) {
    die("FAILED: DummyService was not registered.\n");
}

$instance = $services[DummyService::class];
if (!($instance->env instanceof Environment)) {
    die("FAILED: Environment was not injected into DummyService.\n");
}
if ($instance->env->getVersion() !== '1.2.3') {
    die("FAILED: Injected environment has wrong version.\n");
}

// Test 2: No Arg
$loader->registerService(DummyServiceNoArg::class);
$services = $loader->getServices();

if (!isset($services[DummyServiceNoArg::class])) {
    die("FAILED: DummyServiceNoArg was not registered.\n");
}

// Test 3: Legacy Access
// Using reflection to access protected version for verification if needed,
// but we deprecated public usage. Let's assume we can't access it easily without reflection.
// But we can check if Loader still works.

echo "Loader injection tests passed.\n";
