<?php

// Check if we can reach outside world or if we need to mock
$url = 'https://www.google.com';

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
// Just headers
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_NOBODY, true);

$mh = curl_multi_init();
curl_multi_add_handle($mh, $ch);

$active = null;
do {
    $mrc = curl_multi_exec($mh, $active);
} while ($mrc == CURLM_CALL_MULTI_PERFORM);

echo "Initial exec mrc: $mrc, active: $active\n";

$select_calls = 0;
$minus_one_count = 0;

$start = microtime(true);

while ($active && $mrc == CURLM_OK) {
    $s = curl_multi_select($mh);
    $select_calls++;
    if ($s == -1) {
        $minus_one_count++;
        usleep(5000);
    }
    do {
        $mrc = curl_multi_exec($mh, $active);
    } while ($mrc == CURLM_CALL_MULTI_PERFORM);
}

$end = microtime(true);

echo "Total time: " . ($end - $start) . "s\n";
echo "Select calls: $select_calls\n";
echo "Returns -1: $minus_one_count\n";
