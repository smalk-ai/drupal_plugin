# Smalk for Drupal

Complete GEO (Generative Engine Optimization) integration for Drupal 9/10/11.

## Why Server-Side?

**AI Agents (ChatGPT, Claude, Perplexity, Google AIO, etc.) do not execute JavaScript.**

This means:
- ❌ Traditional JavaScript analytics → **invisible to AI Agents**
- ❌ Client-side ad injection → **never displayed to AI Agents**

Smalk solves this with:
- ✅ **Server-side tracking** - Detects ALL visitors including AI Agents
- ✅ **Server-side ad injection** - Ads are in the HTML before it reaches the AI Agent

**Result:** Publishers can finally monetize AI Agent traffic.

## What This Module Does

Once installed and configured, the module automatically:

1. **Injects the JavaScript tracker** on every page (for browser visitors)
2. **Sends server-side tracking** for every request (for AI Agent detection)
3. **Replaces `<div smalk-ads>` elements** with actual ad content before sending the page

No additional code required - it just works.

## Requirements

- Drupal 9.x, 10.x, or 11.x
- PHP 8.0+
- Smalk account ([app.smalk.ai](https://app.smalk.ai))

## Installation

### Step 1: Install the Module

```bash
# Download and place in modules/contrib/smalk
drush en smalk -y
drush cr
```

Or via admin UI: **Extend → Find "Smalk" → Install**

### Step 2: Configure

Go to **Administration → Configuration → Web services → Smalk**

Or: `/admin/config/services/smalk`

Enter your credentials:

| Setting | Where to Find |
|---------|---------------|
| **Project Key** | Dashboard → Integrations |
| **API Key** | Dashboard → Settings → API Keys |

**That's it for tracking!** Your site is now tracking AI Agents.

### Step 3: Add Ad Placements (Optional)

Add the `smalk-ads` attribute wherever you want ads to appear:

```html
<div smalk-ads></div>
```

For multiple placements, add unique IDs:

```html
<div smalk-ads id="header-ad"></div>
<div smalk-ads id="sidebar-ad"></div>
<div smalk-ads id="content-ad"></div>
```

#### In Twig Templates

```twig
<article>
  {{ '<div smalk-ads id="article-top"></div>'|raw }}
  
  {{ content.body }}
  
  {{ '<div smalk-ads id="article-bottom"></div>'|raw }}
</article>
```

#### As a Custom Block

1. Go to **Structure → Block layout → Custom block library**
2. Create a block with **Full HTML** format
3. Add: `<div smalk-ads id="sidebar-ad"></div>`
4. Place in your desired region

## How It Works

```
┌─────────────────────────────────────────────────┐
│              Visitor requests page               │
│         (Human browser OR AI Agent)              │
└──────────────────────┬──────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────┐
│         1. Server-Side Tracking                  │
│         Sends visit data to Smalk API            │
│         (detects AI Agents immediately)          │
└──────────────────────┬──────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────┐
│         2. Drupal renders the page               │
│         (with <div smalk-ads> placeholders)      │
└──────────────────────┬──────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────┐
│         3. Server-Side Ad Injection              │
│         Replaces <div smalk-ads> with real ads   │
└──────────────────────┬──────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────┐
│         Complete HTML sent to visitor            │
│         (ads already in place - no JS needed)    │
└─────────────────────────────────────────────────┘
```

## Troubleshooting

### Check Your Configuration

```bash
drush watchdog:show smalk
```

### Verify API Connection

```bash
curl -X POST https://api.smalk.ai/api/v1/tracking/visit \
  -H "Authorization: Api-Key YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"request_path":"/test","request_method":"GET","request_headers":{"User-Agent":"Test"}}'
```

### Ads Not Appearing?

1. Verify both Project Key and API Key are set
2. Ensure "Enable AI Search Ads" is checked
3. Check you have active ad campaigns in your Smalk dashboard
4. Make sure the div uses the attribute format: `<div smalk-ads></div>` (not self-closing)

## Support

- **Documentation**: https://smalk.ai/docs
- **Dashboard**: https://app.smalk.ai
- **Support**: support@smalk.ai

## License

GPL-2.0-or-later
