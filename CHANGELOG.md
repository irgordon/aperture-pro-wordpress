# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- New `HealthService::getMetrics()` method to provide centralized performance and storage metrics.
- New REST endpoint `GET /aperture/v1/admin/health-metrics` for retrieving dashboard metrics.
- New `useStorageMetrics.js` hook for fetching storage data.
- New `StorageCard.js` component for the admin health dashboard.
- Added `storage-card` to SPA `bootstrap.js`.

### Changed
- Moved health metrics logic from procedural `inc/ajax-health-endpoint.php` to object-oriented `HealthService` and `AdminController`.
- Updated `usePerformanceMetrics.js` to use the new REST API endpoint instead of `admin-ajax.php`.
- Updated `AdminUI.php` to localize `restBase` and `restNonce` for admin SPA assets.
- Updated `README.md` to reflect the correct file structure.

### Removed
- Deleted `inc/ajax-health-endpoint.php` as it is now superseded by the REST API implementation.
