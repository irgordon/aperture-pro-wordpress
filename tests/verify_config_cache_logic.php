<?php

namespace {
    // Mock WordPress functions
    $mock_options = [];
    $get_option_calls = 0;

    function get_option($key, $default = []) {
        global $mock_options, $get_option_calls;
        $get_option_calls++;
        return $mock_options[$key] ?? $default;
    }

    function update_option($key, $value) {
        global $mock_options;
        $mock_options[$key] = $value;
        return true;
    }
}

namespace AperturePro\Config {
    require_once __DIR__ . '/../src/Config/Config.php';

    // Reset stats
    global $get_option_calls;
    $get_option_calls = 0;

    echo "--- Testing Config Cache Logic ---\n";

    // 1. First call should trigger get_option
    echo "1. First Config::all() call...\n";
    $config1 = Config::all();
    if ($get_option_calls === 1) {
        echo "PASS: get_option called once.\n";
    } else {
        echo "FAIL: get_option called $get_option_calls times (expected 1).\n";
    }

    // 2. Second call should NOT trigger get_option (cached)
    echo "2. Second Config::all() call...\n";
    $config2 = Config::all();
    if ($get_option_calls === 1) {
        echo "PASS: get_option call count remained at 1 (cached).\n";
    } else {
        echo "FAIL: get_option called $get_option_calls times (expected 1).\n";
    }

    // 3. Config::set should invalidate cache
    echo "3. Config::set('foo', 'bar')...\n";
    Config::set('foo', 'bar'); // This should reset cache

    // 4. Third call should trigger get_option again
    echo "4. Third Config::all() call...\n";
    $config3 = Config::all();
    if ($get_option_calls === 2) { // 1 initial + 1 after reset
        echo "PASS: get_option called again (cache invalidated).\n";
    } else {
        echo "FAIL: get_option called $get_option_calls times (expected 2).\n";
    }
}
