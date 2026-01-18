# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
