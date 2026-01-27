<?php

namespace AperturePro\Proof {
    // Mock extension_loaded to force entry into Imagick block
    function extension_loaded($ext) {
        if ($ext === 'imagick') {
            return true;
        }
        return \extension_loaded($ext);
    }

    // Mock WP functions
    if (!function_exists('AperturePro\Proof\apply_filters')) {
        function apply_filters($tag, $value) {
            return $value;
        }
    }
}

namespace {
    if (!function_exists('apply_filters')) {
        function apply_filters($tag, $value) {
            return $value;
        }
    }
}

namespace AperturePro\Proof {
    // Mock other dependencies
    if (!class_exists('AperturePro\Helpers\Logger')) {
        class Logger {
            public static function log($level, $category, $message, $context = []) {
                // echo "[$level] $message\n";
            }
        }
    }

    if (!class_exists('AperturePro\Config\Config')) {
        class Config {
            public static function get($key, $default = null) {
                return $default;
            }
        }
    }

    // Mock wp_tempnam
    if (!function_exists('AperturePro\Proof\wp_tempnam')) {
        function wp_tempnam($prefix = '') {
            return tempnam(sys_get_temp_dir(), $prefix);
        }
    }
}

namespace {
    // Mock global Imagick class
    if (!class_exists('Imagick')) {
        class Imagick {
            const GRAVITY_SOUTHEAST = 9;

            public static $globalCalls = [];
            public $format = 'JPEG';

            public function __construct($files = null) {
                self::$globalCalls[] = ['method' => '__construct', 'args' => func_get_args()];
            }

            public function pingImage($filename) {
                self::$globalCalls[] = ['method' => 'pingImage', 'args' => func_get_args()];
                return true;
            }

            public function setImageFormat($format) {
                $this->format = $format;
                self::$globalCalls[] = ['method' => 'setImageFormat', 'args' => func_get_args()];
                return true;
            }

            public function getImageFormat() {
                 self::$globalCalls[] = ['method' => 'getImageFormat', 'args' => []];
                 return $this->format;
            }

            public function setOption($key, $value) {
                self::$globalCalls[] = ['method' => 'setOption', 'args' => func_get_args()];
                return true;
            }

            public function readImage($filename) {
                self::$globalCalls[] = ['method' => 'readImage', 'args' => func_get_args()];
                return true;
            }

            public function thumbnailImage($columns, $rows, $bestfit = false, $fill = false) {
                self::$globalCalls[] = ['method' => 'thumbnailImage', 'args' => func_get_args()];
                return true;
            }

            public function annotateImage($draw, $x, $y, $angle, $text) {
                self::$globalCalls[] = ['method' => 'annotateImage', 'args' => func_get_args()];
                return true;
            }

            public function setImageCompressionQuality($quality) {
                self::$globalCalls[] = ['method' => 'setImageCompressionQuality', 'args' => func_get_args()];
                return true;
            }

            public function writeImage($filename = null) {
                self::$globalCalls[] = ['method' => 'writeImage', 'args' => func_get_args()];
                // create dummy file
                touch($filename);
                return true;
            }

            public function clear() {
                self::$globalCalls[] = ['method' => 'clear', 'args' => []];
                return true;
            }

            public function destroy() {
                self::$globalCalls[] = ['method' => 'destroy', 'args' => []];
                return true;
            }

            public static function reset() {
                self::$globalCalls = [];
            }
        }

        class ImagickDraw {
            public function setFillColor($color) {}
            public function setFontSize($size) {}
            public function setGravity($gravity) {}
        }
    }
}

namespace AperturePro\Proof {
    require_once __DIR__ . '/../src/Proof/ProofService.php';

    // Create a dummy file to act as original
    $dummyOriginal = tempnam(sys_get_temp_dir(), 'orig');
    file_put_contents($dummyOriginal, 'dummy data');

    // Reset calls
    \Imagick::reset();

    echo "Invoking createWatermarkedLowRes (via exposed method or similar)...\n";

    // We can subclass ProofService to test the protected method directly
    class TestProofService extends ProofService {
        public static function testCreateWatermarkedLowRes($file) {
            return parent::createWatermarkedLowRes($file);
        }
    }

    TestProofService::testCreateWatermarkedLowRes($dummyOriginal);

    $calls = \Imagick::$globalCalls;
    $log = [];
    foreach ($calls as $c) {
        $log[] = $c['method'];
    }

    echo "Calls made:\n";
    print_r($log);

    // Analyze optimization
    $hasPing = in_array('pingImage', $log);
    $hasSetOption = in_array('setOption', $log);
    $hasReadImage = in_array('readImage', $log);
    $hasConstructWithArgs = false;

    foreach ($calls as $c) {
        if ($c['method'] === '__construct' && !empty($c['args'][0])) {
            $hasConstructWithArgs = true;
        }
    }

    if ($hasPing && $hasSetOption && $hasReadImage && !$hasConstructWithArgs) {
        echo "\nPASS: Optimized Imagick loading detected.\n";
    } elseif ($hasConstructWithArgs && !$hasPing) {
        echo "\nFAIL: Legacy memory-intensive loading detected.\n";
    } else {
        echo "\nUNKNOWN: Check call log.\n";
    }

    @unlink($dummyOriginal);
}
