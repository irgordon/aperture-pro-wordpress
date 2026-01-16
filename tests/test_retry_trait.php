<?php

// Mock Logger
namespace AperturePro\Helpers {
    class Logger {
        public static $logs = [];
        public static function log($level, $context, $message, $meta = []) {
            self::$logs[] = compact('level', 'context', 'message', 'meta');
            echo "LOG: [$level] $message\n";
        }
    }
}

namespace Test {

    // Manual require since no autoloader
    require_once __DIR__ . '/../src/Storage/Traits/Retryable.php';

    use AperturePro\Storage\Traits\Retryable;
    use AperturePro\Helpers\Logger;

    class RetryableTestClass {
        use Retryable;

        // Expose protected method for testing
        public function run($fn) {
            return $this->executeWithRetry($fn);
        }
    }

    echo "Starting Retryable Trait Tests...\n";

    $tester = new RetryableTestClass();

    // 1. Test Success
    echo "\nTest 1: Immediate Success\n";
    $res = $tester->run(function() {
        return "OK";
    });
    if ($res !== "OK") {
        die("FAILED: Expected OK, got $res\n");
    }
    echo "PASSED\n";

    // 2. Test Retry then Success
    echo "\nTest 2: Retry then Success\n";
    Logger::$logs = [];
    $attempts = 0;
    $res = $tester->run(function() use (&$attempts) {
        $attempts++;
        if ($attempts < 3) {
            throw new \Exception("Temporary 503 error", 503);
        }
        return "Recovered";
    });

    if ($res !== "Recovered") die("FAILED: Expected Recovered, got $res\n");
    if ($attempts !== 3) die("FAILED: Expected 3 attempts, got $attempts\n");
    if (count(Logger::$logs) !== 2) die("FAILED: Expected 2 logs, got " . count(Logger::$logs) . "\n");
    echo "PASSED\n";

    // 3. Test Max Retries Exceeded
    echo "\nTest 3: Max Retries Exceeded\n";
    Logger::$logs = [];
    $attempts = 0;
    try {
        $tester->run(function() use (&$attempts) {
            $attempts++;
            throw new \Exception("Permanent 503 error", 503);
        });
        die("FAILED: Should have thrown exception\n");
    } catch (\Exception $e) {
        if ($e->getMessage() !== "Permanent 503 error") die("FAILED: Wrong exception message\n");
        // Initial + 3 retries = 4
        if ($attempts !== 4) die("FAILED: Expected 4 attempts, got $attempts\n");
        if (count(Logger::$logs) !== 3) die("FAILED: Expected 3 logs, got " . count(Logger::$logs) . "\n");
    }
    echo "PASSED\n";

    // 4. Test Non-Retryable Error
    echo "\nTest 4: Non-Retryable Error (400)\n";
    Logger::$logs = [];
    $attempts = 0;
    try {
        $tester->run(function() use (&$attempts) {
            $attempts++;
            throw new \Exception("Client Error", 400);
        });
        die("FAILED: Should have thrown exception\n");
    } catch (\Exception $e) {
        if ($e->getMessage() !== "Client Error") die("FAILED: Wrong exception message\n");
        if ($attempts !== 1) die("FAILED: Expected 1 attempt, got $attempts\n");
        if (count(Logger::$logs) !== 0) die("FAILED: Expected 0 logs, got " . count(Logger::$logs) . "\n");
    }
    echo "PASSED\n";

    echo "\nAll tests passed!\n";
}
