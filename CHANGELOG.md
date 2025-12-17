# Changelog

All notable changes to this project will be documented in this file.

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
