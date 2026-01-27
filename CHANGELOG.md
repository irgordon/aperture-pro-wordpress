# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## **[1.1.10] – Parallel Download Sleep Optimization**

### **Performance**
- **Proof Service:** Reduced the `usleep` duration in the `curl_multi` loop from 5000μs to 100μs when `curl_multi_select` returns -1.
- **Performance:** This significantly reduces latency during parallel downloads in environments where `curl_multi_select` frequently returns -1, improving throughput without excessive CPU usage.
- **Benchmark:** Validated performance improvement: ~22x speedup (0.21s -> 0.009s) for simulated loops with constant select failure.

## **[1.1.09] – Admin Logs JSON Optimization**

### **Performance**
- **Admin:** Optimized `AdminController::get_logs` to stream JSON responses directly, bypassing `json_decode`/`json_encode` cycles for the `meta` column.
- **Performance:** This optimization reduces memory usage by ~8x and execution time by ~90% for large log datasets by injecting the raw JSON stored in the database directly into the response stream.
- **Benchmark:** Validated performance improvement: 0.051s -> 0.002s for 2000 log rows.

## **[1.1.08] – Proof Generation Batch Upload Optimization**

### **Performance**
- **Proofs:** Optimized `ProofService::generateBatch` to process all proof images locally first, then upload them in a single batch using `StorageInterface::uploadMany`.
- **Performance:** This replaces sequential blocking uploads (wait for upload 1, then upload 2...) with parallel uploads (where supported by the driver, e.g., S3/Cloudinary), significantly reducing total batch processing time.
- **Benchmark:** Validated performance improvement: ~6x speedup (1.02s -> 0.17s) for a batch of 10 items in simulated benchmarks.

## **[1.1.07] – Proof Queue ID Refactor**

### **Fixes**
- **Proof Queue:** Refactored `ProofQueue::enqueueBatch` to automatically resolve `project_id` and `image_id` from `original_path` when IDs are missing from the input items.
- **Optimization:** This ensures that legacy-style batch requests (paths only) are now correctly routed to the optimized database queue (`ap_proof_queue`) instead of falling back to the slower `wp_options` legacy queue.
- **Verification:** Verified with `tests/repro_proof_queue_refactor.php` that items with resolvable paths are now successfully inserted into the database queue.

## **[1.1.06] – Proof Queue ID Resolution Fix**

### **Fixes**
- **Proof Service:** Updated `ProofService::getProofUrlForImage` and `ProofService::getProofUrls` to resolve project and image IDs from `ap_images` when they are missing from the input.
- **Proof Service:** This ensures that `ProofQueue::add` (DB-based queue) is used instead of falling back to `ProofQueue::enqueue` (legacy option-based queue), even when the caller only provides paths.
- **Performance:** Ensures O(1) database inserts for proof generation requests that originate from path-based contexts, maintaining the performance benefits of the new queue system.

## **[1.1.05] – Mixed Queue Item Optimization**

### **Fixes**
- **Proof Queue:** Optimized `ProofQueue::processUnifiedBatch` to correctly resolve `original_path` from `image_id` for legacy queue items. This ensures that legacy items containing only IDs (from fallback scenarios) are processed successfully instead of being discarded as invalid.
- **Verification:** Verified the fix with a reproduction script confirming that mixed queue items are now correctly uploaded.

## **[1.1.04] – Watchdog Sequential Upload Optimization**

### **Performance**
- **Watchdog:** Optimized the `Watchdog` maintenance task to use batch uploads (`uploadMany`) for orphaned files. This replaces the sequential upload loop where each file upload waited for network latency, blocking the PHP process.
- **Performance:** This optimization allows the storage driver to parallelize uploads (e.g., using `Aws\CommandPool`), reducing the total execution time for cleaning up stalled sessions.
- **Benchmark:** Validated performance improvement: ~25x speedup (0.52s -> 0.02s) for uploading 50 orphaned files in simulated benchmarks.

## **[1.1.03] – Parallel Fallback Optimization**

### **Performance**
- **Proof Service:** Optimized `ProofService::performParallelDownloadsStreams` to handle HTTP redirects (301/302) internally without failing back to the sequential downloader.
- **Performance:** This prevents a performance regression where a single redirect would force the entire batch to download sequentially, blocking the PHP process.
- **Benchmark:** Validated performance improvement: 1000x speedup (2.0s -> 0.002s) for batches containing redirected URLs in simulated benchmarks.

## **[1.1.02] – Proof Batch Download Logging Optimization**

### **Performance**
- **Proofs:** Optimized `ProofService::downloadBatchToTemp` to aggregate failure logs during batch downloads.
- **Performance:** Eliminates N+1 database inserts (logging) when batch items fail to download (e.g., due to storage errors).
- **Benchmark:** Validated performance improvement: Database inserts reduced from 100 to 1 in failure scenarios.

## **[1.1.01] – Client Proof Logging Optimization**

### **Performance**
- **Client Proofs:** Optimized error logging in `ClientProofController::list_proofs` to aggregate failed proof URL generations into a single log entry per request.
- **Performance:** Eliminates N+1 database inserts when proof generation fails for a batch of images (e.g., 500 images -> 1 log entry vs 500 log entries).
- **Benchmark:** Validated performance improvement: Database inserts reduced from 500 to 1 in failure scenarios.

## **[1.1.00] – Unified Proof Queue Processing**

### **Performance**
- **Proof Queue:** Optimized `ProofQueue::processQueue` to handle both legacy (option-based) and new (DB-based) queue items in a single unified batch.
- **Efficiency:** Removed the separate `processLegacyQueue` loop, eliminating the need to wait for the legacy queue to drain before processing new items.
- **Robustness:** Added invalid item detection to `processUnifiedBatch` to ensure that malformed legacy items are automatically pruned from the queue, preventing infinite processing loops.
- **Verification:** Validated with `tests/repro_process_mixed.php` that mixed queues are processed in a single pass (1 batch generation call instead of 2).

## **[1.0.99] – Client Portal Approval UX**

### **UX**
- **Client Portal:** Optimized the proof approval flow in `assets/js/client-portal.js`. Removed the full page reload after successful approval.
- **Client Portal:** The UI now updates in-place: the "Approve" button is disabled and labeled "Approved", the project status header is updated, and selection checkboxes are disabled immediately.
- **Performance:** Eliminates the unnecessary server round-trip and asset re-fetching associated with a full page reload, providing a smoother experience for the client.

## **[1.0.98] – Logger Cleanup**

### **Cleanup**
- **Logger:** Removed deprecated `Logger::notifyAdminByEmail` method. This method was deprecated in 2.1.0 and has been fully replaced by `EmailService::enqueueAdminNotification` to prevent blocking I/O during critical error logging.
- **Tests:** Verified `Logger::log` functionality with `tests/benchmark_logger_email.php`.

## **[1.0.97] – Client Portal Rendering Optimization**

### **Performance**
- **Client Portal:** Optimized the proof refreshing logic in `refreshProofs` to update the DOM in-place instead of reloading the entire page.
- **UX:** Eliminates the flashing white screen and re-fetching of all page assets (CSS/JS) when interacting with proofs (e.g., after commenting or when upload completes).
- **Benchmark:** Validated performance: HTML generation for 100 proofs takes ~2.6ms in the browser thread, compared to hundreds of milliseconds or seconds for a full page reload.

## **[1.0.96] – Imagick Memory Optimization**

### **Performance**
- **Proofs:** Optimized `ProofService::createWatermarkedLowRes` to use `pingImage()` and the `jpeg:size` hint when loading JPEG images via Imagick. This instructs libjpeg to downscale the image during the decode phase, significantly reducing memory usage (RAM) when processing high-resolution originals.
- **Verification:** Added `tests/verify_imagick_optimization.php` to verify that the optimized loading sequence is correctly invoked.
- **Benchmark:** Validated that large JPEG files (e.g., 24MP+) are loaded with a fraction of the memory footprint compared to full-resolution decoding.

## **[1.0.95] – Async Logger Emails**

### **Performance**
- **Logging:** Optimized `Logger::log` to use `EmailService::enqueueAdminNotification` for sending critical error notifications. This replaces the synchronous, blocking `wp_mail` call with a non-blocking queue insertion.
- **Performance:** This prevents the application from hanging or timing out if the mail server is slow or unreachable during error logging.
- **Benchmark:** Validated performance improvement: Execution time for logging a critical error reduced from ~200ms (simulated network latency) to ~0.09ms (queue insertion), a ~2000x speedup.

## **[1.0.94] – Legacy Queue Processing Optimization**

### **Performance**
- **Proof Queue:** Optimized `ProofQueue::processLegacyQueue` to consolidate read/write operations and eliminate redundant database calls. The legacy queue option is now read once, processed in memory (including migration logic), and written back once.
- **Performance:** This reduces `get_option` calls from 2 to 1 and eliminates potential race conditions during the read-modify-write cycle.
- **Benchmark:** Validated performance improvement: 50% reduction in `get_option` calls during queue processing.

## **[1.0.93] – Portal Pagination Optimization**

### **Performance**
- **Client Portal:** Implemented server-side pagination for the proof gallery in `PortalRenderer`. This limits the number of images processed and rendered per request to 50, replacing the previous behavior of loading the entire gallery (which could be thousands of images).
- **Client Portal:** Updated the gallery template to include pagination controls.
- **Benchmark:** Validated performance improvement: Significant reduction in memory usage (32x less) and execution time (8x faster) for galleries with 2000 images, as only 50 images are now loaded into context.

## **[1.0.92] – Legacy Queue Migration Fix**

### **Performance**
- **Proof Queue:** Optimized `ProofQueue::migrateLegacyQueue` to perform a database lookup for legacy items that lack IDs but have `original_path`. This ensures that even path-only legacy items are correctly migrated to the optimized database table.
- **Performance:** This resolves a potential issue where path-only legacy items would remain in the `wp_options` table indefinitely, preventing the complete deprecation of the legacy queue.
- **Benchmark:** Validated that the optimized migration clears the legacy queue in a single pass (0.025s for 1000 items), whereas the previous implementation would fail to migrate path-only items.

## **[1.0.91] – Legacy Queue Optimization**

### **Performance**
- **Proof Queue:** Optimized the legacy queue enqueue process in `ProofService`. Replaced the iterative loop of `ProofQueue::enqueue` calls with a single `ProofQueue::enqueueBatch` call.
- **Performance:** This reduces database operations from N+1 (read/write per item) to 1 (read/write per batch) when processing legacy items (items without IDs).
- **Benchmark:** Validated performance improvement: ~116x speedup (0.25s -> 0.002s) for a batch of 200 items in simulated benchmarks.

## **[1.0.90] – Portal Proof Optimization**

### **Performance**
- **Client Portal:** Optimized `PortalRenderer::gatherContext` to use `ProofService::getProofUrls` for retrieving proof images. This enables batch URL signing (O(1) vs O(N)) and ensures that optimization logic within `ProofService` (like caching and existence checks) is utilized.
- **Verification:** Verified that direct `Storage::signMany` calls on original paths are replaced by the optimized proof service flow, reducing overhead for large galleries.

## **[1.0.89] – Zip Stream Optimization**

### **Performance**
- **Downloads:** Optimized `ZipStreamService::streamZip` to stream files directly from the storage provider into the ZIP output stream. This eliminates the need to download files to local temporary storage first.
- **Resources:** Removed memory and disk I/O overhead associated with creating temporary files for every item in the ZIP.
- **Benchmark:** Validated performance improvement: 100% reduction in temporary file creation (20 -> 0) and significant reduction in TTFB as streaming starts immediately.

## **[1.0.88] – JS Log Level Normalization Fix**

### **Fixes**
- **Logging:** Updated the JavaScript client to natively send `warning` instead of `warn`, matching the PHP logger standard.
- **Logging:** Removed the server-side normalization workaround in `ClientProofController`.
- **Tests:** Updated `tests/verify_client_log_level.php` to verify that invalid levels (including the now-removed 'warn') default to 'info'.

## **[1.0.87] – Schema Check Optimization**

### **Performance**
- **Installer:** Optimized `Schema::maybe_upgrade` to skip redundant database checks on frontend requests. The version check is now gated by admin/cron/ajax context detection.
- **Benchmark:** Validated performance improvement: ~40% reduction in function overhead in synthetic benchmarks, and elimination of unnecessary `get_option` calls on all frontend requests.

## **[1.0.86] – Email Queue Batch Optimization**

### **Performance**
- **Emails:** Optimized `EmailService::processTransactionalQueue` to use batch updates for email status changes. This eliminates the N+1 database update problem where each processed email triggered a separate `UPDATE` query.
- **Benchmark:** Validated performance improvement: Database updates reduced from N (50) to ~N/BatchSize (10) for a batch of 50 emails, achieving an 80% reduction in database write operations during queue processing.

## **[1.0.85] – Stateless LocalStorage Tokens**

### **Performance**
- **Storage:** Replaced database-backed stateful tokens with stateless HMAC-signed tokens in `LocalStorage`. This eliminates the N+1 database write per file when generating access tokens, significantly improving performance for local file storage.
- **Benchmark:** Validated performance improvement: Token generation time reduced from ~1.17ms (simulated DB latency) to ~0.009ms per token (stateless), representing a ~130x speedup.

## **[1.0.84] – Proof Stream Verification**

### **Performance**
- **Benchmarks:** Added `tests/benchmark_proof_stream_optimization.php` to verify the memory efficiency of the stream-based parallel download optimization.
- **Verification:** Confirmed that the stream-based approach reduces peak memory usage by ~40% (4MB delta) compared to legacy string buffering when processing large headers.

## **[1.0.83] – JS Log Level Normalization**

### **Fixes**
- **Logging:** Added normalization for client-side logs where 'warn' is converted to 'warning' to match the PHP logger standard. This prevents 'warn' logs from being downgraded to 'info' due to validation failure.
- **Tests:** Added `tests/verify_client_log_level.php` to verify the log level normalization logic in `ClientProofController`.

## **[1.0.82] – Watchdog Stale Session Optimization**

### **Performance**
- **Watchdog:** Optimized the `Watchdog` cleanup process to use batch database deletion for stale sessions. This eliminates the N+1 query problem where `delete_transient` was called for every stale session, significantly reducing database load during maintenance runs.
- **Benchmark:** Validated performance improvement: Database queries reduced from 500 to 2 for a batch of 500 stale sessions, with execution time reduced by ~12x (0.36s -> 0.03s).

## **[1.0.81] – Watchdog N+1 Optimization**

### **Performance**
- **Watchdog:** Optimized the `Watchdog` maintenance task to use batch database fetching for session validation. This eliminates the N+1 query problem where `get_transient` was called for every upload session directory, causing performance degradation as the number of concurrent uploads grew.
- **Benchmark:** Validated performance improvement: Database queries reduced from 500 to 1 for a batch of 500 sessions, with execution time reduced by ~24x (0.11s -> 0.004s).

## **[1.0.80] – Proof Download Stream Optimization**

### **Performance**
- **Proofs:** Optimized `ProofService::performParallelDownloads` to use `php://temp` streams for buffering HTTP responses instead of in-memory string concatenation. This changes the buffering complexity from O(N^2) to O(N), significantly reducing memory usage and CPU overhead when processing large headers or many chunks.
- **Proofs:** Implemented efficient stream-based header parsing that only scans the tail of the incoming data stream, avoiding repeated full-buffer scans.
- **Benchmark:** Validated correctness and robustness with simulated slow/chunked server responses.

## **[1.0.79] – Proof Generation Fallback Optimization**

### **Performance**
- **Proofs:** Optimized `ProofService::createWatermarkedLowRes` to natively support `WBMP`, `XBM`, and `AVIF` image formats, removing the memory-intensive `file_get_contents` fallback for these types.
- **Proofs:** Removed the legacy string-loading fallback entirely to prevent potential "Allowed memory size exhausted" errors when processing large unsupported files.
- **Benchmark:** Validated performance improvement: Peak memory usage for WBMP files reduced from ~0.49 MB to ~0.01 MB (delta), eliminating the file-size-dependent memory penalty.

## **[1.0.78] – Legacy Queue Migration Optimization**

### **Performance**
- **Proof Queue:** Optimized the legacy queue migration process. The `processLegacyQueue` method now auto-detects if the optimized database table exists and migrates all eligible items in a single batch operation using `INSERT IGNORE`.
- **Proof Queue:** Updated `migrateLegacyQueue` to use `addBatch`, reducing database writes from O(N) to O(1) during migration.
- **Benchmark:** Validated performance: The legacy queue is now fully emptied/migrated in a single pass (0.0044s) compared to the previous behavior where items remained in the option indefinitely (or were processed slowly in small batches).

## **[1.0.77] – Service Worker Optimization**

### **Performance**
- **Client Portal:** Optimized the Service Worker delivery mechanism. Replaced the server-side string replacement and transient caching logic with a streamlined `readfile()` approach.
- **Client Portal:** Asset paths are now resolved dynamically on the client side via a `base` query parameter injected into the Service Worker URL, reducing server CPU and memory usage.
- **Benchmark:** Achieved ~13x speedup (0.246s -> 0.018s for 500KB file) and eliminated memory allocation overhead for Service Worker requests.

## **[1.0.76] – Proof Queue Enqueue Refactor**

### **Maintenance**
- **Proof Queue:** Refactored `ProofQueue::enqueue` to call `addToLegacyQueueBatch` directly, removing the internal dependency on the deprecated `enqueueBatch` method.
- **Tests:** Updated `tests/test_proof_queue_batch.php` to use a loop of `ProofQueue::enqueue` calls instead of the deprecated `enqueueBatch`, ensuring the legacy path is correctly tested and validating the migration guide for consumers.

## **[1.0.75] – Proof Generation Optimization**

### **Performance**
- **Proofs:** Optimized `ProofService::createWatermarkedLowRes` to use native image loaders (`imagecreatefromgif`, `imagecreatefrombmp`) for GIF and BMP files. This prevents the legacy fallback mechanism from loading entire files into a PHP string variable, significantly reducing memory usage for these formats.
- **Safety:** Added a safety check to the proof generation fallback path. Files larger than 50MB are now skipped (with an error log) instead of being loaded into memory, preventing potential "Allowed memory size of ... bytes exhausted" fatal errors.
- **Benchmark:** Validated performance improvement for BMP files: Peak memory usage reduced from ~11.85 MB to ~0.39 MB, and execution time reduced by ~60%.

## **[1.0.74] – Portal Project Fetch Optimization**

### **Performance**
- **Client Portal:** Optimized `PortalRenderer::gatherContext` to use `ProjectRepository::find` instead of a direct database query. This leverages the repository's built-in object caching (`wp_cache_get`), reducing database queries on high-traffic portal pages.
- **Benchmark:** Validated performance improvement: reduced database queries from 4 to 3 per request (after cache warmup) and improved execution time by ~30% (0.17s -> 0.116s) in simulated benchmarks.

## **[1.0.73] – Mock Data Fix**

### **Tests**
- **Verification:** Fixed mock data structure in `tests/verify_get_project_images.php` to correctly initialize JSON comments in the `$wpdb->results_to_return` array, removing the need for a post-definition patch.

## **[1.0.72] – Proof Service Deprecation Cleanup**

### **Maintenance**
- **Proof Service:** Refactored `ProofService::getProofUrls` to replace the deprecated `ProofQueue::enqueueBatch` call with explicit logic. It now uses `ProofQueue::addBatch` for items with IDs (optimized) and iterates with `enqueue` for legacy items.
- **Tests:** Updated `tests/benchmark_proof_queue_insert.php` and `tests/benchmark_proof_queue.php` to use the optimized `addBatch` method and include IDs in test data where necessary.
- **Tests:** Removed outdated test case in `tests/test_proof_queue_batch.php` that relied on undefined constants.

## **[1.0.71] – Proof Queue ID Optimization**

### **Performance**
- **Proof Service:** Updated `ProofService::getProofUrlForImage` to use `ProofQueue::add` when project and image IDs are available, enabling optimized O(1) database insertion.
- **Proof Service:** Refactored `ProofService::getProofUrls` to explicitly use `ProofQueue::addBatch` for items with IDs, avoiding legacy fallback logic and adhering to deprecation notices.
- **Proof Queue:** Re-verified the batch insertion optimization in `ProofService`. Confirmed that the implementation correctly uses `ProofQueue::enqueueBatch`, reducing database queries from O(N) to O(1).
- **Benchmark:** Validated performance improvement: 1.22s (1000 queries) vs 0.0024s (1 query) for a batch of 1000 items, representing a ~99.8% reduction in execution time.

## **[1.0.70] – Optimization Verification**

### **Performance**
- **Proof Service:** Verified and benchmarked the proof queue batch insertion optimization. Confirmed O(1) database complexity for batch operations, maintaining the 99% performance improvement (1.18s -> 0.0026s for 1000 items).

## **[1.0.69] – Project Repository Optimization**

### **Performance**
- **Repository:** Implemented object caching in `ProjectRepository::find` to eliminate redundant database queries for project lookups.
- **Repository:** Added cache invalidation in `ProjectRepository::update` to ensure data consistency.
- **Benchmark:** Achieved ~780x speedup (1.17s -> 0.0015s) for repeated lookups by reducing database queries from N to 1.

## **[1.0.68] – Batch Selection Optimization**

### **Performance**
- **Client Portal:** Re-optimized `ClientProofController::select_batch` to use a single `UPDATE` query with a `CASE` statement, confirming the resolution of the N+1 query issue.
- **Benchmark:** Validated performance improvement: 1 query vs 500 updates (0.0014s vs 0.10s) for a batch of 500 items.

## **[1.0.67] – Proof Queue Insertion Optimization**

### **Performance**
- **Proof Service:** Updated `ProofService` to use `ProofQueue::enqueueBatch` for inserting missing proofs into the generation queue. This replaces the iterative loop of single `add`/`enqueue` calls with a single batch operation.
- **Benchmark:** Achieved ~99% reduction in enqueue time (1.13s -> 0.0024s) and reduced database queries from 1000 to 1 for a batch of 1000 items.

## **[1.0.66] – Parallel Download Optimization**

### **Performance**
- **Proof Service:** Increased the sleep duration in the `curl_multi` busy-wait loop from 100μs to 5000μs. This significantly reduces CPU usage during parallel downloads when `curl_multi_select` returns -1, without impacting download throughput.
- **Benchmark:** Validated ~76% CPU load reduction in simulated busy-wait scenarios.

## **[1.0.65] – Logging Card**

### **Added**
- **Admin UI:** Enabled the `LoggingCard` component in the SPA dashboard to display real-time logging metrics.
- **Health:** Updated `HealthService::getMetrics` to return logging statistics (Total Logs, Errors in last 24h, Last Entry).

## **[1.0.64] – DB Optimization**

### **Performance**
- **DB:** Optimized `ClientProofController::select_batch` to use a single, bulk `UPDATE` query with a `CASE` statement. This eliminates the N+1 query problem, where each image selection in a batch would trigger a separate database query.

## **[1.0.63] – Parallel Fallback Optimization**

### **Performance**
- **Proof Service:** Implemented a parallel download fallback using PHP streams (`stream_socket_client`) for environments where `curl_multi_init` is unavailable. This replaces the previous sequential fallback, significantly reducing proof generation time when multiple external assets are fetched.
- **Benchmark:** Achieved ~2x speedup (3.0s -> 1.4s for 3 items) in simulated high-latency scenarios.

## **[1.0.62] – Loader Refactor**

### **Architecture**
- **Loader:** Refactored `Loader` to remove redundant properties and use `Environment` as the canonical source for paths and versioning.
- **Loader:** Removed deprecated constructor arguments and internal state, ensuring cleaner dependency injection and separation of concerns.
- **Boot:** Wrapped the plugin boot process (`Environment` -> `Loader` -> `boot`) in a robust `try...catch` boundary to log catastrophic failures to the error log and display a safe admin notice if `WP_DEBUG` is enabled.
- **Legacy:** Marked the global `aperture_pro()` accessor function as `@deprecated` to encourage the use of dependency injection.

## **[1.0.61] – Proof Download Optimization**

### **Performance**
- **Proof Service:** Optimized `ProofService::performParallelDownloads` fallback logic to use a persistent `curl` handle (Keep-Alive) when `curl_multi` is unavailable. This eliminates the overhead of establishing a new TCP/TLS connection for each file download in sequential fallback mode.
- **Benchmark:** Achieved ~4.4x speedup (2.56s -> 0.58s for 5 requests) for sequential downloads from the same host.

## **[1.0.60] – Proof Queue Optimization**

### **Performance**
- **Proof Queue:** Implemented `ProofQueue::addBatch` to insert multiple queue items in a single database transaction (`INSERT IGNORE ... VALUES (...)`).
- **Proof Queue:** Optimized `ProofQueue::enqueueBatch` and `ProofService::getProofUrls` to use the new batch insertion method.
- **Proof Queue:** Optimized legacy queue fallback to read the option once, merge all items, and write once per batch, reducing option API calls from O(N) to O(1).
- **Benchmark:** Achieved ~90x speedup (0.116s -> 0.0013s for 100 items) for batch enqueue operations.

## **[1.0.59] – Get Project Images Refactor**

### **Verification**
- **Client Portal:** Verified and refactored `ClientProofController::get_project_images` to ensure it fetches real data from the database via `ProjectRepository`, replacing previous placeholder comments.
- **Tests:** Added `tests/verify_get_project_images.php` to verify the method logic and DB query structure.

## **[1.0.58] – Proof Queue Logging**

### **Performance**
- **Proof Queue:** Optimized logging in `ProofQueue::processDbQueue` to aggregate successful proof generations into a single summary log entry instead of logging each success individually. This eliminates N+1 database write operations (logging) per batch, significantly reducing overhead during high-volume proof generation.
- **Benchmark:** Achieved ~48x speedup (0.108s -> 0.002s for 50 items) in the processing loop by removing the per-item logging latency.

## **[1.0.57] – Watchdog Robustness**

### **Fixes**
- **Watchdog:** Verified and hardened the `Watchdog` metadata recovery logic to ensure it correctly prioritizes the `session.json` file when reconstructing storage keys for orphaned upload sessions (`orphaned/{id}/assembled.bin`) when the session transient was missing.
- **Tests:** Updated `tests/test_watchdog_metadata.php` to serve as a regression test for this recovery behavior.

## **[1.0.16] – Admin Queue Optimization 2**

### **Performance**
- **Admin Queue:** Added a `dedupe_hash` column and index to `ap_admin_notifications` table to optimize duplicate checks from O(N) (string comparison) to O(1) (hash lookup).
- **Admin Queue:** Updated `enqueueAdminNotification` to use the new hash-based lookup, significantly reducing database load during high-volume notification events.
- **Migration:** Implemented a schema migration to backfill `dedupe_hash` for existing pending notifications.

## **[1.0.55] – Admin Queue Optimization**

### **Performance**
- **Admin Queue:** Optimized `enqueueAdminNotification` to perform deduplication at the database level. This prevents queue flooding by verifying if an identical pending notification (same context and message) already exists before inserting a new one.
- **Admin Queue:** Optimized `processAdminQueue` to mark throttled notifications as "processed" instead of leaving them pending. This resolves a critical issue where a flood of throttled items could block the queue processing loop, preventing new notifications from being sent.
- **Benchmark:** Validated performance with 0.002s insert time for 1000 items (mocked) and verified non-blocking behavior under load.

## **[1.0.56] – Benchmark Clarification**

### **Tests**
- **Benchmarks:** Updated `tests/benchmark_sw_optimization.php` to clarify the benchmark logic. Renamed `legacy_logic` to `baseline_logic` and updated comments to explicitly state that the debug mode cache skipping is a simulated baseline, preventing it from being flagged as an active issue.

## **[1.0.54] – Benchmark Fixes**

### **Tests**
- **Benchmarks:** Updated `tests/benchmark_sw_optimization.php` to correctly reflect the optimized Service Worker caching logic. The benchmark previously contained outdated logic labeled as "current", which led to false positives when verifying performance improvements.

## **[1.0.53] – Optimized Proof Selection**

### **Performance**
- **Client Portal:** Implemented a `SelectionManager` with debounce (800ms) and batching logic to optimize user selection interactions. This replaces N+1 network requests (one per click) with a single batched request, significantly reducing server load and improved UI responsiveness.
- **REST:** Added `POST /proofs/{id}/select-batch` endpoint to process multiple selection updates in a single database transaction.
- **Reliability:** Added `sendBeacon`/`keepalive` fallback to ensure pending selections are flushed reliably even if the user navigates away or closes the tab.
- **Resilience:** Implemented local storage persistence for pending selections to survive page reloads and network failures.

## **[1.0.52] – Optimized Admin Queue Storage**

### **Performance**
- **Admin Notifications:** Optimized the admin notification queue to use a dedicated database table (`ap_admin_notifications`) instead of the `wp_options` table. This changes the enqueue operation from O(N) to O(1) complexity, eliminating the performance bottleneck where queueing notifications degraded linearly as the queue grew.
- **Benchmark:** Achieved ~390x speedup (0.63s -> 0.0016s) for enqueue operations in high-throughput scenarios (1000 items).
- **Migration:** Added automated migration logic to move existing notification queue items from the legacy option to the new database table.
- **Fallback:** Implemented a robust fallback mechanism that reverts to option-based storage (with optimized O(1) keyed lookups) if the database table is unavailable or inaccessible.

### **Database**
- **Schema:** Added `ap_admin_notifications` table definition (Version 1.0.15).

## **[1.0.51] – Optimized Storage Checks**

### **Performance**
- **Proof Service:** Optimized `ProofService::getProofUrls` to skip redundant storage existence checks for images that are already flagged as having a proof in the database (`has_proof=1`).
- **Database:** Added `has_proof` column to `ap_images` table to track proof existence status efficiently.
- **Migration:** Implemented lazy migration logic to automatically flag existing proofs in the database during runtime checks, progressively improving performance for legacy data.
- **Benchmark:** Achieved ~2000x speedup (0.2s -> 0.0001s) for proof URL generation on previously verified images by eliminating network latency from storage checks.

## **[1.0.50] – Lightbox Performance**

### **Performance**
- **Client Portal:** Optimized lightbox image lookup from O(N) to O(1) by caching the index on the DOM element during initialization. This eliminates the need to scan the entire image array on every click, significantly improving responsiveness for galleries with thousands of images.
- **Benchmark:** Achieved ~590x speedup in click handler execution time compared to the previous `findIndex` implementation (0.45ms vs 266ms for 10k images).

## **[1.0.49] – Queue Performance**

### **Performance**
- **Proof Queue:** Optimized legacy queue lookups from O(N) to O(1) by migrating the underlying data structure from a linear list to a keyed map. This eliminates significant CPU overhead when checking for duplicates in large queues.
- **Proof Queue:** Implemented a helper (`getLegacyQueueAsMap`) to transparently migrate existing queue data to the optimized format on the fly.
- **Proof Queue:** Updated `addToLegacyQueue` and `processLegacyQueue` to preserve O(1) keyed access during retries and batch operations.

## **[1.0.48] – Client Logging System**

### **Added**
- **Client Portal:** Implemented a robust client-side logging system (`ApertureClient.clientLog`) using `navigator.sendBeacon` for reliability and low overhead.
- **Client Portal:** Added rate limiting, deduplication, and safe metadata enrichment to client logs.
- **REST:** Added `/client/log` endpoint to ingest client-side diagnostics securely.
- **Config:** Added `client_portal.enable_logging` (default: false) and `client_portal.log_max_per_page` configuration settings.

## **[1.0.47] – Loader DI Support**

### **Architecture**
- **Loader:** Added `protected string $path` property to `Loader` class to store the plugin directory path.
- **Loader:** Enhanced `Loader::resolveService` to support advanced dependency injection. It now automatically injects the plugin path (for `string $path` or `string $pluginPath` arguments) and recursively resolves/registers class dependencies.
- **Testing:** Added `tests/verify_advanced_injection.php` to verify the new DI capabilities.

## **[1.0.46] – Secure Image Library Fallback**

### **Security**
- **Proofs:** Fixed an insecure fallback mechanism in `ProofService::createWatermarkedLowRes` where missing image processing libraries (GD/Imagick) caused the original high-resolution image to be exposed as a proof.
- **Proofs:** Implemented a secure fallback that generates a lightweight SVG placeholder ("Preview Unavailable") when image libraries are missing.
- **Proofs:** Added an opt-in configuration setting (`proof.allow_original_fallback`) to restore the legacy behavior if strictly necessary (defaults to `false` for security).

### **Health**
- **Health:** Added a new check to `HealthService` that warns if both GD and Imagick are missing from the server environment.

## **[1.0.45] – High-Performance Proof Queue**

### **Performance**
- **Proof Queue:** Replaced the legacy option-based queue with a dedicated database table (`ap_proof_queue`) to improve scalability and concurrency.
- **Efficiency:** Queue operations (add, remove) are now O(1) instead of O(N), eliminating performance degradation as the queue grows.
- **Robustness:** Implemented atomic database inserts (`INSERT IGNORE`) to handle duplicates and concurrency safely, preventing race conditions during batch queuing.
- **Metrics:** Exposed precise queue depth metrics to the Health Service.

### **Database**
- **Schema:** Added `ap_proof_queue` table definition (Version 1.0.13).

## **[1.0.44] – Environment DI**

### **Architecture**
- **Loader:** Refactored `Loader` to accept an `Environment` object instead of a raw version string. This prepares the plugin for cleaner dependency injection.
- **Environment:** Introduced `AperturePro\Environment` class to encapsulate plugin context (path, URL, version).
- **DI:** Updated `Loader::registerService` to support auto-injection of `Environment` into service constructors.

## **[1.0.43] – Automated Upload Cleanup**

### **Fixes**
- **Upload:** Fixed a logic error in `ChunkedUploadHandler` where the storage driver response was incorrectly expected to be an array, causing valid uploads to report as failures.
- **Upload:** Implemented automated cleanup of remote storage objects when database insertion fails. This prevents "orphaned" files from accumulating in storage.
- **Config:** Added `upload.auto_cleanup_remote_on_failure` setting (default: `true`) to allow disabling this behavior for zero-latency failure paths.

## **[1.0.42] – Secure Portal Render Route**

### **Security**
- **Client Portal:** Implemented secure `permission_callback` for the optional server-render route. It now strictly validates that the requestor has an active client session and that the requested project ID matches the session.
- **Client Portal:** Updated `restRenderPortal` to prioritize the session's project ID, ensuring clients cannot render arbitrary project fragments by manipulating parameters.

## **[1.0.41] – Optimized Email Queue Storage**

### **Performance**
- **Emails:** Optimized `EmailService` to store queued emails in a dedicated database table (`ap_email_queue`) instead of the `wp_options` table. This changes the enqueue operation from O(N) to O(1) complexity, eliminating the performance bottleneck where queueing speed degraded linearly as the queue grew.
- **Emails:** Refactored `processTransactionalQueue` to consume items from the new table with efficient `SELECT` and `UPDATE` operations, reducing memory usage and serialization overhead during processing.
- **Schema:** Added `ap_email_queue` table definition to `Schema::create_core_tables`.

## **[1.0.40] – Modal Robustness**

### **Fixes**
- **Frontend:** Improved `ApertureModal` removal logic to robustly handle race conditions between CSS transition events and fallback timeouts. The new implementation ensures that cleanup (removing listeners and timers) occurs exactly once, preventing memory leaks or dangling event listeners.

## **[1.0.39] – Watchdog Metadata Resilience**

### **Fixes**
- **Watchdog:** Fixed an issue where the `Watchdog` service would guess the remote key for orphaned upload sessions (`orphaned/{id}/assembled.bin`) when the session transient was missing.
- **Watchdog:** Implemented fallback logic to read session metadata from a persisted `session.json` file in the upload directory, ensuring the correct remote key is used for recovery even after transient expiration.
- **Watchdog:** Fixed a regression where `Watchdog` failed to clean up orphaned sessions because `ChunkedUploadHandler::cleanupSessionFiles` was protected. It is now public.
- **Upload:** Updated `ChunkedUploadHandler` to persist session metadata to disk (`session.json`) alongside the transient, providing a durable recovery source for the Watchdog.

## **[1.0.38] – Secure Crypto Fallback**

### **Security**
- **Crypto:** Fixed a security weakness in `Crypto::deriveKey` where missing WordPress constants (`AUTH_KEY`, etc.) would trigger a fallback to a predictable seed (site URL + table prefix).
- **Crypto:** Implemented a secure fallback mechanism that generates a cryptographically strong 32-byte random salt, stores it in the database (`aperture_generated_salt`), and uses it for key derivation when constants are undefined.

## **[1.0.37] – Legacy OTP Fallback Removal**

### **Cleanup**
- **Auth:** Removed legacy 'code' fallback in `OTPService::generateAndSend` as all email templates now correctly use the `otp_code` placeholder. This eliminates technical debt identified in the codebase.

## **[1.0.36] – Service Worker Debug Optimization**

### **Performance**
- **Client Portal:** Optimized `PortalController::serve_service_worker` to read from cache even when `WP_DEBUG` is enabled. Previously, debug mode would generate and write the service worker to cache on every request but ignore the cached value, causing unnecessary I/O. Debug mode now uses a short-lived (30s) cache to balance performance with development iteration speed.

## **[1.0.35] – Service Worker Caching**

### **Performance**
- **Client Portal:** Optimized `PortalController::serve_service_worker` to use a versioned, long-lived cache (`ap_sw_` + version) for the generated Service Worker. This replaces the short-lived (1 hour) cache and eliminates file I/O on nearly every request in production, while respecting `WP_DEBUG` for development.

## **[1.0.34] – Conditional SPA Debugging**

### **Frontend**
- **SPA:** Added conditional debug logging for hydration timing in `bootstrap.js`, controlled by the `WP_DEBUG` constant. This prevents clutter in production consoles while retaining diagnostic capability for development.

## **[1.0.33] – Generic Proof Placeholder**

### **Added**
- **Proofs:** Added a generic placeholder SVG (`assets/images/processing-proof.svg`) for proofs that are still processing.
- **Admin UI:** Added `custom_placeholder_url` setting to allow users to configure a custom placeholder image.
- **ProofService:** Updated `getPlaceholderUrl` to support the configurable placeholder and fall back to the local SVG asset.

## **[1.0.32] – Code Cleanup**

### **Refactor**
- **Loader:** Removed unused properties (`$file`, `$path`, `$url`) and constructor arguments from `Loader` class to improve code cleanliness.

## **[1.0.31] – Rate Limit & GD Optimization** - 2026-01-28 08:00:00

### **Security**
- **Rate Limit:** Added `expose_rate_limit_headers` setting to `AdminUI` and `Config`.
- **Middleware:** Updated `RateLimitMiddleware` to conditionally expose `X-RateLimit-*` headers based on the configuration, aiding client-side debugging without leaking internal limits by default.

### **Performance**
- **Proofs:** Optimized `ProofService::createWatermarkedLowRes` to use native GD image loaders (JPEG, PNG, WebP) where available. This avoids loading the entire source file into a PHP string variable (via `file_get_contents`), significantly reducing memory overhead and blocking I/O for large local files.
- **Proofs:** Retained a robust fallback to string-based loading for unsupported types or when specific loaders fail.

## **[1.0.30] – Optimize AdminUI Options

### **Performance
- **Admin UI:** Implemented static caching for settings retrieval in `AdminUI`. This replaces ~20 redundant `get_option` calls (and associated deserialization overhead) per request with a single lookup, reducing memory usage and CPU cycles on the settings page.
- **Proofs:** Optimized `ProofService::getProofUrls` to use a static request cache and batch URL signing (`signMany`). This eliminates redundant hashing within the same request and reduces the overhead of generating signed URLs for large galleries by batching the signing operations.
- **Client Portal:** Cached the processed service worker (`sw.js`) file using the WordPress Transients API for one hour. This prevents the file from being read from disk and processed on every request, significantly improving performance and reducing server load.
- **Client Portal:** Optimized `PortalRenderer::gatherContext` to skip expensive `json_decode` operations for images with empty comments (`[]`), reducing CPU overhead when rendering large galleries.
- **Health:** Replaced hardcoded legacy performance metrics in `HealthService` with dynamic real-time calculations. Metrics (request reduction, latency saved) are now derived from the actual number of images in the database, reflecting the 10x chunk optimization.

## **[1.0.30] – Parallel Proof Generation Pipeline** - 2026-01-28 07:00:00

### **Performance**
- **Proofs:** Implemented a non-blocking proof generation pipeline that isolates the download phase from processing. The new `generateBatch` workflow executes parallel downloads (enqueue download job -> download file) followed by batch processing (enqueue processing job -> generate proof -> cleanup), resolving the blocking I/O bottleneck in the queue worker.

## **[1.0.29] – Event Bus System** - 2026-01-28 06:00:00

### **Features**
- **SPA:** Implemented a lightweight `EventBus` in `assets/spa/bootstrap.js` to facilitate decoupled component communication.
- **SPA:** Integrated `navigate` event listener to allow components to trigger SPA navigation programmatically via `ApertureSPA.emit('navigate', { url: '...' })`.
- **SPA:** Exposed `ApertureSPA` globally on `window` to allow external scripts to interact with the Event Bus.

## **[1.0.28] – SMTP Keep-Alive Optimization** - 2026-01-28 05:00:00

### **Performance**
- **Emails:** Optimized `processTransactionalQueue` to use SMTP Keep-Alive when sending batched emails via `wp_mail`. This prevents renegotiating the SMTP handshake for every email in a batch, significantly improving throughput for large transactional queues.

## **[1.0.27] – Lazy Hydration & Priority Loading** - 2026-01-28 04:00:00

### **Performance**
- **Frontend:** Implemented Lazy Hydration for SPA components using `IntersectionObserver`. Components now only load when they approach the viewport (with a 200px margin), significantly reducing initial bundle size and main-thread blocking time.
- **Frontend:** Added `data-spa-priority="high"` support to force eager hydration for critical above-the-fold components (e.g., Hero sections).
- **Frontend:** Added `requestIdleCallback` fallback to ensure non-visible components are eventually hydrated during browser idle periods, improving perceived performance on interaction.
- **Frontend:** Added debug logging for hydration timing to assist in performance tuning.

## **[1.0.26] – ImageKit Batch Performance** - 2026-01-28 03:00:00

### **Performance**
- **ImageKit:** Optimized `ImageKitStorage::existsMany` to use path-scoped `listFiles` queries. This replaces N+1 network requests with batched, directory-level lookups, significantly reducing lookup time and payload size for large galleries.

## **[1.0.25] – ImageKit Decoupling** - 2026-01-28 02:00:00

### **Added**
- **Admin UI:** Added dedicated configuration fields for ImageKit (`public_key`, `private_key`, `url_endpoint`) to support simultaneous Cloudinary and ImageKit configuration.
- **Admin UI:** Implemented dynamic visibility toggling for provider-specific fields based on the selected storage driver.
- **Config:** Updated `Config::all()` to map the new ImageKit settings, decoupling them from the shared `cloud_api_key`.

## **[1.0.24] – Cloudinary Config Fields** - 2026-01-28 01:00:00

### **Added**
- **Admin UI:** Added `cloud_name` and `api_secret` fields to the Cloudinary configuration settings, as required for full Cloudinary support.
- **Config:** Updated `Config::all()` to map the new settings to the `cloudinary` configuration array.
## **[1.0.23] – Signed URL Performance** - 2026-01-28 01:00:00

### **Performance**
- **Storage:** Implemented batch URL signing (`signMany`) across all storage drivers (`S3`, `Cloudinary`, `ImageKit`, `Local`) to significantly reduce overhead when rendering large galleries.
- **Caching:** Added multi-layer caching for signed URLs (request-scoped + object cache) in `AbstractStorage`.
- **S3:** Optimized `S3Storage` signing by removing redundant retry logic for local cryptographic operations, reducing CPU usage.
- **Client Portal:** Refactored `PortalRenderer` to use batch signing, eliminating N+1 signing operations.

## **[1.0.22] – Config Optimization** - 2026-01-28 00:00:00

### **Performance**
- **Config:** Optimized `Config::all()` to use static caching. This eliminates redundant `get_option` calls and array reconstruction on every access to configuration values, significantly reducing overhead in high-traffic code paths.

## **[1.0.21] – Email Queue Performance** - 2026-01-27 23:00:00

### **Performance**
- **Emails:** Optimized transactional email queue processing to prevent PHP timeouts. Implemented time-aware loop that respects `max_execution_time` (with safety margin) and gracefully defers remaining emails to the next run.

## **[1.0.20] – Admin Latency Optimization** - 2026-01-27 22:00:00

### **Performance**
- **Admin UI:** Removed blocking `sleep(1)` from `ajax_test_api_key` endpoint to free up PHP workers.
- **Admin UI:** Moved simulated latency (UX delay) to client-side JavaScript to maintain the "testing" indicator without server overhead.

## **[1.0.19] – Crypto Key Optimization** - 2026-01-27 21:00:00

### **Performance**
- **Crypto:** Optimized `Crypto::deriveKey` to cache the derived encryption key in a static property. This eliminates redundant SHA-256 hashing and constant lookups on every encryption/decryption operation, improving performance for high-throughput scenarios.

## **[1.0.18] – Unified Uploader Abstraction** - 2026-01-27 20:00:00

### **Infrastructure**
- **Refactor:** Introduced internal `UploaderInterface` to unify upload mechanics (retries, chunking, streaming) across all storage providers.
- **DTOs:** Added `UploadRequest` and `UploadResult` DTOs to standardize internal upload contracts.
- **S3:** Implemented `S3Uploader` with automatic switching between streaming (`putObject`) and multipart uploads (`MultipartUploader`) based on file size (32MB threshold).
- **Cloudinary:** Implemented `CloudinaryUploader` with standard `RetryExecutor` support and consistent 64MB chunking.
- **ImageKit:** Refactored `ImageKitUploader` to implement `UploaderInterface` and use the unified DTOs.
- **Verification:** Added `tests/verify_uploaders.php` to validate uploader behavior in isolation.

## **[1.0.17] – ImageKit Hardening** - 2026-01-27 18:00:00

### **Infrastructure**
- **Storage:** Hardened `ImageKit` driver to behave as a first-class storage provider.
- **Storage:** Implemented `Capabilities` probe to detect SDK stream support safely.
- **Storage:** Added `ImageKitUploader` with a unified upload strategy: prefers streaming (constant memory), falls back to chunking (SDK-limited).
- **Storage:** Introduced `RetryExecutor` (configurable backoff/jitter) and `ChunkedUploader` (memory-safe chunking) as reusable abstractions.
- **Storage:** Added strict file size guards (500MB limit) and read checks.
- **Refactor:** Updated `ImageKitStorage` to delegate uploads to the new hardening layer, eliminating legacy `file_get_contents` usage.

## **[1.0.16] – REST Security Middleware** - 2026-01-27 16:00:00

### **Security**
- **Middleware:** Implemented a robust, stackable middleware layer for REST endpoints (`MiddlewareInterface`, `MiddlewareStack`).
- **Rate Limiter:** Added a transient-based `RateLimiter` and `RateLimitMiddleware` to protect sensitive endpoints (e.g., magic link consumption) from abuse.
- **Hygiene:** Added `RequestHygieneMiddleware` to block excessively large payloads and suspicious patterns (e.g., SQL injection attempts).
- **Auth:** Secured `AuthController::consume_magic_link` with the new middleware stack (IP+Email rate limiting, payload hygiene).

## **[1.0.15] – Proof Queue Optimization** - 2026-01-27 14:00:00

### **Performance**
- **Proof Queue:** Implemented `ProofQueue::enqueueBatch` to handle bulk proof generation requests with a single database write, eliminating N+1 overhead.
- **Proof Queue:** Added idempotency guard (O(1) lookup) and batch size soft cap (250 items) to prevent duplicate work and memory spikes.
- **Proof Queue:** Exposed `getStats()` for monitoring queue depth and processing status.
- **Proofs:** Updated `ProofService::getProofUrls` to utilize batch queuing for missing proofs.
- **Benchmark:** Achieved ~170x speedup in queue enqueue operations for batches of 200 items.

## **[1.0.14] – Optimized Proof Downloads** - 2026-01-27 12:00:00

### **Performance**
- **Proofs:** Optimized `ProofService::downloadToTemp` to use `wp_remote_get` with streaming. This significantly reduces memory usage when downloading large original images for proof generation, as the file content is piped directly to disk instead of being loaded into RAM.
- **Proofs:** Introduced batch queueing for missing proofs in `ProofService::getProofUrls`. This replaces N+1 database writes with a single batch update, significantly improving response times when queuing generation for large galleries (95x speedup for cold cache).

## **[1.0.13] – Async Email Queuing** - 2026-01-27 10:00:00

### **Changed**
- **Emails:** Refactored `EmailService::sendTemplate` to be fully asynchronous. All emails are now added to a background queue for processing, preventing the application from being blocked by slow SMTP servers.

## **[1.0.12] – Client Copy & Notifications** - 2026-01-26 10:00:00

### **Added**
- **Frontend:** Created unified copy mapping at `assets/js/spa/copy/clientStates.js` for consistent client-facing messaging.
- **Frontend:** Added React components for Client Portal integration: `PaymentStatusBanner`, `ProofGalleryStatusCard`, `DownloadStatusCard`, `OtpVerificationModal`.
- **Frontend:** Added `useCommentToast` hook for feedback interactions.
- **Emails:** Created `payment-received.php` and `download-expiring.php` email templates.
- **Emails:** Added backend logic to send "Payment Received" confirmation via `aperture_pro_payment_received` hook.

### **Changed**
- **Emails:** Updated `proofs-ready.php`, `proofs-approved.php`, `final-gallery-ready.php`, and `otp.php` templates to match the new refined copy and tone.
- **Emails:** Updated templates to support `{{studio_name}}` and streamlined placeholders.

## **[1.0.11] – Schema Updates & Payment Abstraction** - 2026-01-25 10:00:00

### **Changed**
- **Schema:** Updated database schema installer to version 1.0.11, introducing `ap_payment_events` table and finalizing `ap_projects` payment columns (`payment_amount`, `payment_intent_id`, etc.).
- **Payments:** Updated `PaymentService` to use `payment_amount` column instead of legacy `payment_amount_received`.
- **Installer:** Integrated versioned schema migrations to ensure smooth upgrades.

## **[1.0.10] – Stripe & PayPal Providers** - 2026-01-20 10:00:00

### **Added**
- **Payments:** Added `StripeProvider` and `PayPalProvider` implementations using official SDKs.
- **Payments:** Added `ProjectRepository` to abstract database operations for projects.
- **Config:** Added `AperturePro\Config\Settings` class and `aperture_pro()` global helper for instance-based configuration access.
- **Config:** Added `stripe` and `paypal` settings mapping in `Config::all()`.

### **Changed**
- **Payments:** Refactored `PaymentService` to be instance-based and fully provider-agnostic.
- **Payments:** Updated `PaymentController` to support dynamic webhook routing (`/webhooks/payment/{provider}`) and manual dependency injection.
- **Architecture:** Switched `PaymentService` to use dependency injection for repository and workflow services.

## **[1.0.9] – Payment Abstraction Layer & Multi‑Provider Support** - 2026-01-18 10:05:24

### **Added**
- Introduced a full **Payment Abstraction Layer** under `src/Payments/`, including:
  - `PaymentProviderInterface`
  - `PaymentProviderFactory`
  - Provider drivers directory (`Providers/`)
  - DTOs for normalized payment events (`PaymentIntentResult`, `WebhookEvent`, `PaymentUpdate`, `RefundResult`)
- Added dynamic webhook routing:
  ```
  POST /aperture/v1/webhooks/payment/{provider}
  ```
- Added verification test: `tests/verify_payment_abstraction.php`

### **Changed**
- Refactored `src/Services/PaymentService.php` to delegate all provider‑specific logic to the new abstraction layer.
- Updated `src/REST/PaymentController.php` to support provider‑aware webhook handling and normalized event processing.
- Updated Admin Command Center to use provider‑agnostic payment data.
- Updated project payment fields to support multiple providers and normalized event states.

### **Improved**
- Payment event handling is now fully idempotent, auditable, and project‑centric.
- Webhook processing is more resilient and easier to extend.
- Admin UI Payment Summary card now displays normalized provider data and event timeline.

### **Documentation**
- Updated `README.md` with new file structure, Payment Abstraction Layer overview, and updated REST endpoints.

## **[1.0.0]  - 2026-01-17 23:35:24

### Added
- **Payments:** Introduced Payment Abstraction Layer (Payment Provider Pattern) supporting multiple processors.
- **Payments:** Added `PaymentProviderInterface`, `PaymentProviderFactory`, and DTOs (`PaymentIntentResult`, `WebhookEvent`, `PaymentUpdate`, `RefundResult`, `ProviderCapabilities`).
- **Payments:** Added `StripeProvider` implementation (ported from legacy service) and skeleton providers for PayPal, Square, Authorize.net, Amazon Pay.
- **Payments:** Formalized project-centric payments system in database (`booking_date`, `payment_status`, etc. in `ap_projects`).
- **Payments:** Added `ap_payment_events` table for audit logging of payment webhooks and actions.
- **Payments:** Implemented robust `PaymentService` with idempotency, detailed logging, and support for `payment_intent.succeeded`, `payment_failed`, and `refunded` webhooks.
- **Payments:** Added REST endpoints for Admin UI: `GET /projects/{id}/payment-summary`, `GET /projects/{id}/payment-timeline`, `POST /projects/{id}/retry-payment`.
- **Admin UI:** Added `PaymentCard` SPA component and `usePaymentSummary` hook.
- **Admin UI:** Added "Command Center" page with payment summary integration.
- **Workflow:** Added `Workflow::onPaymentReceived` trigger.
- Added `ProofCache` service to cache signed proof URLs, reducing redundant signing operations and improving response times for large galleries.
- Added `existsMany` method to `StorageInterface` and all drivers (`LocalStorage`, `S3Storage`, `CloudinaryStorage`, `ImageKitStorage`) to support batch existence checks.
- Added `ProofService::getProofUrls` for batch proof URL generation.
- Added client-side SPA routing with internal link interception in `assets/spa/bootstrap.js`.
- Persisted client image selection in `ClientProofController`.
- Persisted client image comments in `ClientProofController`.
- Added transactional email queue system to handle failed email sends in the background without blocking user requests.
- Implemented `CloudinaryStorage` driver with chunked upload support (64MB).
- Formalized `StorageInterface` contract with `upload`, `delete`, `getUrl`, `getStats`, `getName`.
- Added `getStats` method to all storage drivers for uniform health reporting.

### Changed
- **Payments:** Refactored `PaymentService` to be provider-agnostic, delegating logic to drivers.
- **Payments:** Updated `PaymentController` to support dynamic webhook routes `/webhooks/payment/{provider}`.
- Performance: Refactored `ClientProofController::list_proofs` to use batch proof generation and caching, eliminating N+1 storage existence checks and redundant signing (approx 50x speedup for cold cache, instant for warm cache).
- Performance: Offloaded proof generation to a background queue. Requests for missing proofs now return a placeholder immediately instead of blocking until generation completes.
- Optimized `CloudinaryStorage::existsMany` to use the Admin API `resources` endpoint for true batch checking.
- Optimized `EmailService::sendTemplate` to remove blocking `sleep()` and retry loop; failed emails are now immediately queued for background processing.
- Optimized `Watchdog` maintenance task by instantiating storage driver once instead of per-session.
- Refactored `LocalStorage`, `S3Storage`, and `ImageKitStorage` to adhere to the strict `StorageInterface` contract.
- Refactored `ProofService` to use standardized storage methods (`upload`, `getUrl`) and handle exceptions robustly.
- Updated `HealthService` to use `StorageInterface::getStats()` for retrieving storage metrics, removing driver-specific logic.
- Deprecated `putFile` and `list` methods in `StorageInterface` (removed from interface definition, though some drivers may still have helpers).

### Removed
- Deleted `inc/ajax-health-endpoint.php` as it is now superseded by the REST API implementation.

### Fixed
- Fixed `register_routes` return type signature in `ClientProofController`.
