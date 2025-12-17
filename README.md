# Smalk for Drupal

**Monetize AI Agent traffic with server-side ads for Drupal 9/10/11.**

AI Agents (ChatGPT, Claude, Perplexity, Google AIO) don't execute JavaScript - traditional ads are invisible to them. Smalk injects ads server-side so they appear in the HTML before it reaches AI Agents.

## Requirements

- Drupal 9.x, 10.x, or 11.x
- PHP 8.0+
- Smalk account ([app.smalk.ai](https://app.smalk.ai))

## Installation

### Step 1: Install the Module

1. Download the `smalk` folder
2. Place it in `modules/contrib/smalk` (or `modules/custom/smalk`)
3. Go to **Extend** (`/admin/modules`)
4. Search for "Smalk" and check the box
5. Click **Install**

### Step 2: Configure Your API Key

1. Go to **Configuration → Web services → Smalk** (`/admin/config/services/smalk`)
2. Enter your API Key from [Smalk Dashboard](https://app.smalk.ai) → Settings → API Keys
3. Click **Save configuration**

Your workspace information will be fetched automatically.

## Adding Ads to Your Content

Add this HTML where you want ads to appear:

```html
<div smalk-ads></div>
```

### How to Add

1. **Edit** your page or article
2. Click the **Source** button in the editor toolbar
3. Paste `<div smalk-ads></div>` where you want the ad
4. **Save** your content

### Multiple Ad Placements

Use unique IDs to distinguish different ad slots:

```html
<div smalk-ads id="article-top"></div>
<div smalk-ads id="article-bottom"></div>
```

### In Twig Templates

```twig
{{ '<div smalk-ads></div>'|raw }}
```

### As a Block

1. Go to **Structure → Block layout → Custom block library**
2. Create a block with **Full HTML** format
3. Add: `<div smalk-ads id="sidebar-ad"></div>`
4. Place it in your desired region

## How It Works

The module automatically:

1. **Tracks every page visit** - Including AI Agent traffic (server-side, before cache)
2. **Injects ads into your content** - Replaces `<div smalk-ads>` with actual ad HTML
3. **Ensures fresh ads** - Pages with ads are not cached to enable proper ad rotation and impression tracking

## Troubleshooting

### Ads Not Appearing

1. Check **Publisher Status** is "Active" in module settings
2. Verify "Enable AI Search Ads" is checked
3. Ensure you have active ad campaigns in your Smalk Dashboard

### smalk-ads Attribute Being Stripped

This is configured automatically on install. If you still have issues:

1. Go to **Configuration → Web services → Smalk**
2. Open **Advanced Settings → Troubleshooting**
3. Click **Re-configure Text Formats**

### View Logs

1. Go to **Reports → Recent log messages** (`/admin/reports/dblog`)
2. Filter by type "smalk"

## Support

- **Dashboard**: [app.smalk.ai](https://app.smalk.ai)
- **Documentation**: [smalk.ai/docs](https://smalk.ai/docs)
- **Support**: support@smalk.ai

## License

GPL-2.0-or-later
