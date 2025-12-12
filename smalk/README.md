# Smalk for Drupal

Complete GEO (Generative Engine Optimization) integration module for Drupal 9/10/11.

## Features

### 1. 🌐 JavaScript Tracker (Frontend Analytics)
Automatically injects the Smalk tracker script on all pages to track browser-based visitors.

### 2. 🖥️ Server-Side Tracking (AI Bot Detection)
Tracks ALL page requests server-side to detect AI bots that don't execute JavaScript:
- ChatGPT, Claude, Perplexity
- Google AIO, Bing AI
- AI crawlers and scrapers
- Search engine bots

**Critical:** Without server-side tracking, you'll miss 70-90% of AI bot traffic!

### 3. 📊 AI Search Ads (Server-Side Injection)
Injects contextual ads directly into HTML responses. Ads are included in the page when it arrives to the user - no client-side loading.

## Requirements

- Drupal 9.x, 10.x, or 11.x
- PHP 8.0 or higher
- Guzzle HTTP Client (included with Drupal core)

## Installation

### Option 1: Manual Installation

1. Download from [GitHub releases](https://github.com/smalk-ai/drupal-plugin/releases)
2. Extract to `modules/contrib/smalk`
3. Clear Drupal caches

### Enable the Module

```bash
drush en smalk -y
drush cr
```

Or via admin UI: **Extend → Find "Smalk" → Check → Install**

## Configuration

Navigate to **Administration → Configuration → Web services → Smalk**

Or: `/admin/config/services/smalk`

### Required Settings

| Setting | Description |
|---------|-------------|
| **Project Key** | Your Smalk project UUID. Found in Dashboard → Integrations |
| **API Key** | Your API key for server-side requests. Found in Dashboard → Settings → API Keys |

### Feature Toggles

| Feature | Default | Description |
|---------|---------|-------------|
| Enable module | ✅ On | Master switch |
| Enable Tracking | ✅ On | JS tracker + server-side tracking |
| Enable AI Search Ads | ✅ On | Server-side ad injection |

## How It Works

### Automatic Tracking

Once configured, the module automatically:

1. **Injects tracker.js** on every page:
```html
<script src="https://api.smalk.ai/tracker.js?PROJECT_KEY=your-project-key" async></script>
```

2. **Sends server-side tracking** for every request to detect AI bots

**No additional setup required for tracking!**

### Ad Placement (Server-Side Injection)

Add the `smalk-ads` attribute to a div where you want ads:

```html
<div smalk-ads></div>
```

For multiple placements, add unique IDs:

```html
<div smalk-ads id="header-ad"></div>
<div smalk-ads id="sidebar-ad"></div>
<div smalk-ads id="footer-ad"></div>
```

The module replaces these divs with actual ad content **before** the page is sent to the user.

## Usage Examples

### Twig Template

```twig
<article>
  <h1>{{ node.title.value }}</h1>
  
  {{ '<div smalk-ads id="article-top"></div>'|raw }}
  
  {{ content.body }}
  
  {{ '<div smalk-ads id="article-bottom"></div>'|raw }}
</article>
```

### Custom Block

1. Go to **Structure → Block layout → Custom block library**
2. Create a new block with **Full HTML** format
3. Add: `<div smalk-ads id="sidebar-ad"></div>`
4. Place the block in your desired region

## Architecture

```
┌─────────────────────────────────────────────────┐
│                  User Request                    │
└──────────────────────┬──────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────┐
│         SmalkTrackingSubscriber                  │
│         (KernelEvents::REQUEST, priority 100)    │
│                                                  │
│  • Sends tracking to Smalk API                   │
│  • Fire-and-forget (non-blocking)               │
└──────────────────────┬──────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────┐
│              Drupal renders page                 │
│              (with <div smalk-ads> elements)     │
└──────────────────────┬──────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────┐
│         SmalkAdsResponseSubscriber               │
│         (KernelEvents::RESPONSE, priority -100)  │
│                                                  │
│  • Finds <div smalk-ads> elements                │
│  • Fetches ad content from API                   │
│  • Replaces divs with actual ads                │
└──────────────────────┬──────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────┐
│         Complete HTML sent to user               │
│         (with ads already in place)              │
└─────────────────────────────────────────────────┘
```

## Troubleshooting

### Tracking Not Working

1. **Verify credentials**: Check Project Key and API Key are correct
2. **Enable debug mode**: Check Drupal logs for details
3. **Test API**:
```bash
curl -X POST https://api.smalk.ai/api/v1/tracking/visit \
  -H "Authorization: Api-Key YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"request_path":"/test","request_method":"GET","request_headers":{"User-Agent":"Test"}}'
```

### Ads Not Appearing

1. **Check feature toggles**: Both "Enable module" and "Enable AI Search Ads" must be on
2. **Verify HTML syntax**: Use `<div smalk-ads></div>` (not self-closing)
3. **Check excluded paths**: Ensure the page isn't excluded
4. **Check ad availability**: You may not have active campaigns

### View Logs

```bash
drush watchdog:show smalk
```

## Module Files

```
smalk/
├── smalk.info.yml
├── smalk.module
├── smalk.services.yml
├── smalk.routing.yml
├── smalk.links.menu.yml
├── config/
│   ├── install/smalk.settings.yml
│   └── schema/smalk.schema.yml
├── src/
│   ├── EventSubscriber/
│   │   ├── SmalkTrackingSubscriber.php
│   │   └── SmalkAdsResponseSubscriber.php
│   └── Form/
│       └── SmalkSettingsForm.php
├── README.md
├── CHANGELOG.md
└── LICENSE.txt
```

## Support

- **Documentation**: https://smalk.ai/docs
- **Dashboard**: https://app.smalk.ai
- **Support**: support@smalk.ai

## License

GPL-2.0-or-later
