# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.6] - 2026-10-08

### Fixed
- Clearing a price or stock cell no longer saves 0. An empty sale price now ends the sale; an empty regular price or stock quantity is refused with a message
- Sale prices that are not below the regular price are refused instead of being silently dropped
- The save result is now read from the server: refused items stay highlighted with the reason, instead of every save reporting success
- Saves larger than the server limit (100 products or 100 variations) are sent in several requests instead of being rejected
- Reverting a version that no longer exists no longer rolls back the newer versions first
- A failed revert now shows the error and closes the dialog instead of leaving it stuck
- wp-admin no longer crashes when WooCommerce is inactive

### Changed
- The product list is no longer cached for 5 minutes, so stock and prices shown are always current
- The browser now warns before leaving the page with unsaved changes
- Save and Reset are disabled while a save is in progress
- Setting a price to 0 asks for confirmation

## [1.0.1] - 2024-01-15

### Fixed
- Fixed sorting order reset when clearing date filters
- Fixed duplicate event handlers in top controls
- Fixed save controls not being disabled on fresh load

## [1.0.0] - 2024-01-10

### Added
- Initial release of Stock Management plugin
- Product stock quantity editing
- Stock status management
- Price editing (regular and sale prices)
- Date range filtering
- Category and tag filtering
- Stock status filtering
- Price range filtering
- Sales range filtering
- Pagination support
- Variable product support with variations
- Version history and revert functionality
- Responsive design with sidebar toggle
- Export functionality
- Bulk operations 