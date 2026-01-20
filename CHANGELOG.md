# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Performance
- **Client Portal:** Cached the processed service worker (`sw.js`) file using the WordPress Transients API for one hour. This prevents the file from being read from disk and processed on every request, significantly improving performance and reducing server load.

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
- **Proofs:** Introduced batch queueing for missing proofs in `ProofService::getProofUrls`. This replaces N+1 database writes with a single batch update, significantly improving response times when queuing generation for large galleries (95x speedup in benchmarks).

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
