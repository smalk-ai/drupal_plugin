# Changelog

All notable changes to this project will be documented in this file.

## [1.2.0] - 2026-09-10

### Fixed
- Drupal 9 compatibility
- Scheduled task (cron) reliability
- IndexNow key file delivery
- Ad rendering and page caching

### Changed
- CKEditor 5 is no longer a required dependency
- Minimum PHP version declared as 8.0
- The API Timeout setting now applies everywhere

### Added
- Uninstall now reverts the text-format changes made at install

## [1.1.0] - 2026-05-21

### Added
- Preconnect + dns-prefetch hints injected before the async tracker script tag, targeting the Smalk API origin. Saves ~50-150ms on cold TLS+DNS handshakes (mobile / first-time visitors).

## [1.0.1] - 2026-02-12

### Fixed
- Add trailing slash to tracking API endpoint URL to avoid 301 redirects on every request
- Remove stray character in SmalkAdsMiddleware

## [1.0.0] - 2024-12-17

### Added
- Complete GEO integration for Drupal 9/10/11
- **Server-Side Tracking**: Every page visit tracked (including AI Agents)
- **Server-Side Ad Injection**: Replaces `<div smalk-ads>` with actual ad content
- **JavaScript Tracker**: Automatic injection for browser analytics
- Admin configuration form with feature toggles
- Path exclusion support (wildcards)
- Debug mode for troubleshooting
- Automatic text format configuration on install

### Technical
- Dual HTTP middleware architecture for optimal caching behavior
- SmalkTrackingMiddleware (priority 250): Tracks before page cache
- SmalkAdsMiddleware (priority 100): Injects ads, disables caching for ad pages
- SmalkAdsResponsePolicy: Prevents page cache from storing pages with ads

### Security
- API keys stored securely in Drupal configuration
- Only required headers sent to Smalk API
- Graceful degradation on API failures
