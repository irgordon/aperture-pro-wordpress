# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Added missing `screenshot.png` to `aperture-pro-theme/` to comply with WordPress theme standards.
- Added `tests/verify_theme_load.php` to verify theme asset loading and template part execution.

### Fixed
- Fixed fatal error in `aperture-pro-theme` where `wp_template_part()` was called instead of `block_template_part()` in `header.php` and `footer.php`.
- Fixed missing styles in `aperture-pro-theme` by enqueuing `header.css` and `navigation.css` in `inc/enqueue.php`.
