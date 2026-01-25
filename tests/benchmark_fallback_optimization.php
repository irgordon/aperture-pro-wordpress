<?php

// tests/benchmark_fallback_optimization.php

$width = 2000;
$height = 2000;
$tempFile = tempnam(sys_get_temp_dir(), 'bench_bmp_') . '.bmp';

// Create a dummy BMP image using GD (if possible) or manual construction if needed.
// GD supports writing BMP since PHP 7.2. If older, we might need another format or manual header.
// Assuming PHP 7.4+ based on codebase style.

$im = imagecreatetruecolor($width, $height);
// Fill with noise
for ($i = 0; $i < 5000; $i++) {
    imagesetpixel($im, rand(0, $width), rand(0, $height), imagecolorallocate($im, rand(0,255), rand(0,255), rand(0,255)));
}

if (function_exists('imagebmp')) {
    imagebmp($im, $tempFile, true); // compressed = true/false
} else {
    // Fallback if imagebmp not available (unlikely in modern PHP, but safe)
    // Use PNG but rename to .bmp to trick extension checks if any, though getimagesize checks content.
    // Wait, getimagesize checks magic numbers.
    // If I want to trigger the fallback, I need a valid image file that is NOT JPEG, PNG or WEBP.
    // GIF or BMP.
    imagegif($im, $tempFile);
}
imagedestroy($im);

$size = getimagesize($tempFile);
$type = $size[2];
echo "Generated Image Type: $type (1=GIF, 6=BMP)\n";
echo "File size: " . round(filesize($tempFile) / 1024, 2) . " KB\n\n";

// --- Baseline: Current Logic (Fallback) ---
// Simulate the code path where switch case misses this type.
// Existing code handles JPEG, PNG, WEBP.
// If type is BMP or GIF, it goes to fallback.

echo "--- Baseline (Fallback Logic) ---\n";
if (function_exists('memory_reset_peak_usage')) {
    memory_reset_peak_usage();
}
$start = microtime(true);
$startMem = memory_get_usage();

// Actual Logic from ProofService
$src = null;
// Simulating the switch miss...

// Fallback
$srcData = file_get_contents($tempFile);
if ($srcData !== false) {
    $src = imagecreatefromstring($srcData);
}

$end = microtime(true);
$peakMem = memory_get_peak_usage();
$memDelta = $peakMem - $startMem;

echo "Time: " . number_format($end - $start, 5) . " s\n";
echo "Peak Memory Usage: " . number_format($peakMem / 1024 / 1024, 2) . " MB\n";
echo "Memory Delta: " . number_format($memDelta / 1024 / 1024, 2) . " MB\n";

if ($src) imagedestroy($src);
unset($srcData);
gc_collect_cycles(); // Ensure cleanup

// --- Optimized: Direct Loader ---
// This simulates what we WANT to implement: catching the type and using specific loader.

echo "\n--- Optimized (Direct Loader) ---\n";
if (function_exists('memory_reset_peak_usage')) {
    memory_reset_peak_usage();
}
$start = microtime(true);
$startMem = memory_get_usage();

$src = null;
if ($type === IMAGETYPE_BMP && function_exists('imagecreatefrombmp')) {
    $src = imagecreatefrombmp($tempFile);
} elseif ($type === IMAGETYPE_GIF && function_exists('imagecreatefromgif')) {
    $src = imagecreatefromgif($tempFile);
} else {
    // Should not happen in this test if we generated BMP/GIF
    $src = imagecreatefromstring(file_get_contents($tempFile));
}

$end = microtime(true);
$peakMem = memory_get_peak_usage();
$memDelta = $peakMem - $startMem;

echo "Time: " . number_format($end - $start, 5) . " s\n";
echo "Peak Memory Usage: " . number_format($peakMem / 1024 / 1024, 2) . " MB\n";
echo "Memory Delta: " . number_format($memDelta / 1024 / 1024, 2) . " MB\n";

if ($src) imagedestroy($src);

// Cleanup
@unlink($tempFile);
