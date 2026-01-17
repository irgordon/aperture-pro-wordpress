# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Implemented `CloudinaryStorage` driver with chunked upload support (64MB).
- Formalized `StorageInterface` contract with `upload`, `delete`, `getUrl`, `getStats`, `getName`.
- Added `getStats` method to all storage drivers for uniform health reporting.

### Changed
- Refactored `LocalStorage`, `S3Storage`, and `ImageKitStorage` to adhere to the strict `StorageInterface` contract.
- Refactored `ProofService` to use standardized storage methods (`upload`, `getUrl`) and handle exceptions robustly.
- Updated `HealthService` to use `StorageInterface::getStats()` for retrieving storage metrics, removing driver-specific logic.
- Deprecated `putFile` and `list` methods in `StorageInterface` (removed from interface definition, though some drivers may still have helpers).

### Removed
- Deleted `inc/ajax-health-endpoint.php` as it is now superseded by the REST API implementation.
