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

Enter your **API Key** (found in Dashboard → Settings → API Keys).

**That's it!** The workspace info is fetched automatically from the API.

The module will display:
- Your workspace name
- Publisher status (whether ads are available)

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

**Note:** Ads are only injected if Publisher is activated in your Smalk workspace.

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
│         (only if Publisher is activated)         │
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

### Ads Not Appearing?

1. **Check Publisher status**: Go to module settings and verify "Publisher Status: Active"
2. **Activate Publisher**: If not active, go to your Smalk Dashboard to activate
3. Ensure "Enable AI Search Ads" is checked in module settings
4. Make sure the div uses the attribute format: `<div smalk-ads></div>` (not self-closing)
5. Check you have active ad campaigns

## Support

- **Documentation**: https://smalk.ai/docs
- **Dashboard**: https://app.smalk.ai
- **Support**: support@smalk.ai

## License

GPL-2.0-or-later
