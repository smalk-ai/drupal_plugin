# Changelog

All notable changes to this project will be documented in this file.

## [1.0.0] - 2024-12-12

### Added
- Complete GEO integration for Drupal 9/10/11
- **JavaScript Tracker**: Automatic injection of tracker.js for frontend analytics
- **Server-Side Tracking**: AI bot detection via `/api/v1/tracking/visit`
- **Server-Side Ad Injection**: Replaces `<div smalk-ads>` with actual ad content
- Admin configuration form with feature toggles
- Path exclusion support (wildcards)
- Debug mode for troubleshooting
- Guzzle-based async HTTP requests

### Security
- API keys stored securely in Drupal configuration
- Only required headers sent to Smalk API (User-Agent, Referer, X-Real-IP)
- Graceful degradation on API failures

---

## Planned
- Block plugin for easier placement via UI
- Performance metrics dashboard
