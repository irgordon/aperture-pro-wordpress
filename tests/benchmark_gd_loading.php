<?php

// tests/benchmark_gd_loading.php

$width = 2000;
$height = 2000;
$tempFile = tempnam(sys_get_temp_dir(), 'bench_gd_') . '.jpg';

// Create a dummy JPEG image
$im = imagecreatetruecolor($width, $height);
// Fill with some noise so it's not trivial
for ($i = 0; $i < 1000; $i++) {
    imagesetpixel($im, rand(0, $width), rand(0, $height), imagecolorallocate($im, rand(0,255), rand(0,255), rand(0,255)));
}
imagejpeg($im, $tempFile, 90);
imagedestroy($im);

echo "Benchmark Image: $width x $height JPEG\n";
echo "File size: " . round(filesize($tempFile) / 1024, 2) . " KB\n\n";

// Test Case A: file_get_contents + imagecreatefromstring
$start = microtime(true);
$startMem = memory_get_usage();

$content = file_get_contents($tempFile);
$imgA = imagecreatefromstring($content);
unset($content); // simulate freeing string memory after creation

$end = microtime(true);
$peakMemA = memory_get_peak_usage() - $startMem;

echo "Case A (file_get_contents + imagecreatefromstring):\n";
echo "Time: " . number_format($end - $start, 5) . " s\n";
echo "Peak Memory Delta: " . number_format($peakMemA / 1024 / 1024, 2) . " MB\n";

if ($imgA) imagedestroy($imgA);

echo "\n";

// Test Case B: imagecreatefromjpeg (Direct Loader)
$start = microtime(true);
$startMem = memory_get_usage();

$imgB = imagecreatefromjpeg($tempFile);

$end = microtime(true);
$peakMemB = memory_get_peak_usage() - $startMem;

echo "Case B (imagecreatefromjpeg):\n";
echo "Time: " . number_format($end - $start, 5) . " s\n";
echo "Peak Memory Delta: " . number_format($peakMemB / 1024 / 1024, 2) . " MB\n";

if ($imgB) imagedestroy($imgB);

// Cleanup
@unlink($tempFile);
