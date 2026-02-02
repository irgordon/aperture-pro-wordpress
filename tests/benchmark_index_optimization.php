<?php
/**
 * Benchmark Script for Index Optimization on `storage_key_original`
 * Usage: php tests/benchmark_index_optimization.php
 */

// Connect to SQLite DB for benchmark (in-memory or file)
// Using a file to be more realistic and persistent during the run if needed, but memory is faster for setup.
// Let's use memory for speed, but file might be better for realistic disk I/O simulation.
// Given we want to measure index lookup vs table scan, memory is fine as it isolates CPU/Algorithm.
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 1. Create Schema
// We mimic `ap_images` and `ap_galleries` structure relevant to the query.
$pdo->exec("
    CREATE TABLE ap_galleries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER NOT NULL
    );
");

// Create ap_images WITHOUT the index first
$pdo->exec("
    CREATE TABLE ap_images (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        gallery_id INTEGER NOT NULL,
        storage_key_original TEXT,
        FOREIGN KEY(gallery_id) REFERENCES ap_galleries(id)
    );
");

// 2. Populate Data
echo "Populating database with 100,000 rows...\n";
$stmtGallery = $pdo->prepare("INSERT INTO ap_galleries (project_id) VALUES (?)");
$stmtImage = $pdo->prepare("INSERT INTO ap_images (gallery_id, storage_key_original) VALUES (?, ?)");

$pdo->beginTransaction();
// Create 100 galleries
for ($g = 1; $g <= 100; $g++) {
    $stmtGallery->execute([rand(1, 50)]);
}

// Create 100,000 images
$targetPaths = [];
for ($i = 1; $i <= 100000; $i++) {
    $galleryId = rand(1, 100);
    $path = "projects/123/uploads/" . uniqid() . "_image_{$i}.jpg";
    $stmtImage->execute([$galleryId, $path]);

    // Keep track of some paths to query later
    if ($i % 1000 === 0) {
        $targetPaths[] = $path;
    }
}
$pdo->commit();

echo "Database populated. Target paths count: " . count($targetPaths) . "\n";

// 3. Define the Query Function
function runBenchmark($pdo, $targetPaths, $label) {
    // Prepare placeholders
    $placeholders = implode(',', array_fill(0, count($targetPaths), '?'));
    $sql = "
        SELECT i.id as image_id, i.storage_key_original, g.project_id
        FROM ap_images i
        JOIN ap_galleries g ON i.gallery_id = g.id
        WHERE i.storage_key_original IN ($placeholders)
    ";

    $stmt = $pdo->prepare($sql);

    $start = microtime(true);
    // Run query 10 times to average
    for ($run = 0; $run < 10; $run++) {
        $stmt->execute($targetPaths);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $end = microtime(true);

    $avgTime = ($end - $start) / 10;
    echo "[$label] Average Query Time: " . number_format($avgTime, 6) . " seconds\n";
    return $avgTime;
}

// 4. Run Benchmark WITHOUT Index
echo "\n--- Benchmarking WITHOUT Index ---\n";
// Ensure SQLite didn't automatically create an index we didn't ask for (it shouldn't for TEXT column unless UNIQUE/PK)
$timeNoIndex = runBenchmark($pdo, $targetPaths, "NO INDEX");

// 5. Add Index
echo "\nAdding Index on storage_key_original...\n";
$startIdx = microtime(true);
$pdo->exec("CREATE INDEX idx_storage_key_original ON ap_images(storage_key_original)");
$endIdx = microtime(true);
echo "Index creation took: " . number_format($endIdx - $startIdx, 4) . " seconds\n";

// 6. Run Benchmark WITH Index
echo "\n--- Benchmarking WITH Index ---\n";
$timeWithIndex = runBenchmark($pdo, $targetPaths, "WITH INDEX");

// 7. Calculate Improvement
if ($timeWithIndex > 0) {
    $improvement = $timeNoIndex / $timeWithIndex;
    echo "\nImprovement Factor: " . number_format($improvement, 2) . "x faster\n";
} else {
    echo "\nImprovement: Infinite (Time was 0)\n";
}
