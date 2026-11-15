# DiceStack (Full Edition) — Free All-in-One WordPress Plugin (200+ Tools)

> **One plugin. Every tool. Always free.** DiceStack bundles **200+ modular tools** for security, performance, SEO, WooCommerce, caching, backups, forms, and site management into a single lightweight dashboard — and you turn on **only what you need**. Disabled tools load **zero code**, so your site stays fast.

![Version](https://img.shields.io/badge/version-1.6.3-2563EB)
![License](https://img.shields.io/badge/license-GPLv2%2B-0bb8d9)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4)
![Tools](https://img.shields.io/badge/tools-200%2B-16a34a)
![Price](https://img.shields.io/badge/price-100%25%20free-success)

**Built by [Dice Codes](https://dicecodes.com) — your digital dream team.**

🔗 **[Download on WordPress.org](https://wordpress.org/plugins/dicestack/)** &nbsp;•&nbsp; 🌐 **[Plugin Home](https://dicecodes.com/dicestack/)** &nbsp;•&nbsp; 📖 **[Documentation](https://dicecodes.com/dicestack/docs/)**

<p align="center">
  <img src=".wordpress-org/github-social.png" alt="DiceStack — the free all-in-one WordPress plugin with 200+ modular tools for security, performance, SEO, WooCommerce, caching and backups" width="100%">
</p>

---

## Two editions

DiceStack comes in two builds. **This repository is the Full Edition.**

| | **Full Edition** (this repo) | **WordPress.org Edition** |
|---|---|---|
| Where | [GitHub Releases](https://github.com/IamRamgarhia/dicestack-security-speed-seo-tools/releases) | [wordpress.org/plugins/dicestack](https://wordpress.org/plugins/dicestack/) |
| Tools | **200+** | ~170 |
| Includes power tools | ✅ File manager, code snippets, database tools, wp-config editor, .htaccess editor, header/footer code, custom CSS | ❌ (not allowed on the .org directory) |
| Updates | One-click **from GitHub** (built-in updater) | WordPress.org auto-updates |
| Best for | Developers & agencies who want everything | Most sites — lean and directory-vetted |

Both are **100% free**. The two never clash: the Full Edition carries an `Update URI` header, so WordPress will never cross-update one into the other.

---

## Why DiceStack?

Most WordPress sites run a dozen plugins — one for caching, one for security, one for SEO, another for backups — each adding weight, cost, and conflicts. **DiceStack replaces that whole stack** with one free, modular toolkit:

- **🧩 200+ tools, one install** — security, performance, SEO, WooCommerce, media, marketing, admin, accessibility and more.
- **⚡ Zero bloat by design** — every tool is off until you enable it, and disabled tools load **no PHP, JS, or CSS**. The dashboard shows the exact RAM / JS / DB cost of each tool *before* you turn it on.
- **💯 100% free, forever** — no "Pro" upsell, no locked features, no nag screens.
- **🛡️ Safe Mode & isolation** — a failing tool auto-disables itself instead of taking down your site.
- **🤝 Conflict-aware** — DiceStack detects plugins you already run (e.g. an SEO or caching plugin) and warns you before enabling an overlapping tool.
- **🏢 Agency Mode** — a distinct client toolkit, plus a 1-click "Recommended setup" that scans your site and enables the right essentials.

---

## Features — 200+ tools across 10 categories

| Category | Tools | Examples |
|---|---|---|
| 🛡️ **Security** | 29 | Login protection, two-factor auth (2FA), **malware File Scanner**, **core file integrity check**, **Under Attack mode**, **Login IP allowlist**, vulnerability scanner, security headers, Cloudflare control |
| ⚡ **Performance** | 27 | Page cache, object cache (Redis/Memcached), **CDN URL rewrite**, **cache warmup**, critical CSS, minify, lazy-load, heartbeat control |
| 🔍 **SEO** | 21 | Meta titles & descriptions, schema markup, breadcrumbs, redirects, robots.txt, Open Graph, **broken-link checker** |
| 📝 **Forms** | 8 | Contact forms, spam protection, SMTP, submission logging |
| 🛒 **WooCommerce** | 25 | Direct checkout, abandoned cart recovery, PDF invoices, catalog mode, custom order statuses, checkout field editor |
| 🖼️ **Media** | 11 | Image optimization, WebP/AVIF, media folders, lightbox, **AI alt-text** |
| 📣 **Content & Marketing** | 32 | AI content assistant, announcement bar, cookie consent, GA4/Pixel, related posts, table of contents |
| 🧰 **Admin & Developer** | 35 | **Code Snippets**, **Database tools**, **wp-config editor**, **Cron manager**, file manager, .htaccess editor, admin menu editor, WP-CLI |
| 🗄️ **Site Management** | 14 | Backups & restore, cloud backup, **email log**, monthly reports, error monitor, activity log, diagnostics |
| ♿ **Accessibility & Legal** | 7 | Cookie consent, accessibility toolbar, skip links, terms consent |

> 👉 Full tool-by-tool guides: **[dicecodes.com/dicestack/docs](https://dicecodes.com/dicestack/docs/)**

### 🔐 Malware & hardening suite

DiceStack ships a real security sweep, not just checkboxes:

- **File Scanner** — scans `wp-content` for malware-shaped code (obfuscated/encoded payloads, dangerous functions fed request input, web-shell markers, PHP hidden inside fake image uploads). Precision-tuned to avoid flagging legitimate framework/vendor code.
- **Core file integrity** — verifies `wp-admin`/`wp-includes`/root against the official WordPress.org checksums and flags **modified, missing, or injected** core files.
- **Under Attack mode** — emergency lockdown: block XML-RPC, block the REST API for logged-out visitors, disable registration, and per-IP rate limiting. Logged-in users are never affected.
- **Login IP allowlist**, **login protection**, **security headers**, **bad-bot blocking**, **two-factor auth**, and **vulnerability scanner**.

### 🧑‍💻 Developer power tools (Full Edition only)

Gated behind admin capabilities, with clear warnings and safe defaults:

- **Code Snippets** — run PHP snippets; each is wrapped in try/catch and auto-disables if it errors.
- **Database Tools** — browse/optimize tables and run SQL (read-only by default).
- **wp-config editor** — toggle common constants safely; backs up the file first.
- Plus **file manager**, **.htaccess editor**, **header/footer code**, **custom CSS**, and **cron manager**.

---

## Installation

### Full Edition (this repo — 200+ tools)
1. Download the latest **`dicestack.zip`** from the [**Releases**](https://github.com/IamRamgarhia/dicestack-security-speed-seo-tools/releases) page (use the attached `dicestack.zip`, **not** the auto-generated "Source code").
2. In wp-admin go to **Plugins → Add New → Upload Plugin** and choose the zip.
3. **Activate**, then open the **DiceStack** dashboard.
4. Future updates appear as a normal one-click update in wp-admin (checked from GitHub Releases).

### WordPress.org Edition (lean — ~170 tools)
1. In wp-admin go to **Plugins → Add New**, search for **DiceStack**, click **Install Now → Activate**. Updates come through WordPress.org.

---

## How it works

DiceStack uses a **modular architecture**: each tool is a self-contained module that is only loaded when you enable it.

- Disabled module → **0 KB** PHP/JS/CSS, **0** extra DB queries.
- Each card in the dashboard shows the tool's footprint *(e.g. "If on: +12 KB")* so you make informed choices.
- Your enabled/disabled choices **persist across updates** — updating the plugin never re-enables tools or loses settings.

---

## A free alternative to premium WordPress plugins

| Looking for a free alternative to… | Use DiceStack's built-in tools |
|---|---|
| **WP Rocket / W3 Total Cache** (caching) | Page Cache, Object Cache (Redis/Memcached), Minify, Lazy Load, Defer JS, CDN rewrite, Cache Warmup |
| **Wordfence / Sucuri** (security) | Login Protection, Two-Factor Auth, **File Scanner (malware)**, **Core Integrity**, **Under Attack mode**, Vulnerability Scanner, Security Headers, Login IP allowlist |
| **Really Simple SSL** (HTTPS) | Force HTTPS, Mixed Content Fixer, HSTS & Security Headers, SSL certificate monitor |
| **Yoast SEO Premium / All in One SEO** (SEO) | Meta Tags, Schema / JSON-LD, Breadcrumbs, Redirect Manager, robots.txt, Analytics (GA4), Broken-link checker |
| **UpdraftPlus / BlogVault** (backups) | Backup & Restore, Cloud Backup (FTP / WebDAV / Email / Google Drive) |
| **Imagify / ShortPixel / Smush** (images) | Image Optimizer, WebP / AVIF, bulk compression, AI alt-text |
| **WPForms / Gravity Forms** (forms) | Contact Form, Spam Shield, SMTP, universal Submissions Tracker |
| **Code Snippets / WP Data Access** (developer) | Code Snippets, Database Tools, wp-config editor, Cron manager |

Every one of these is **100% free** in DiceStack — no premium tier, no upsell.

---

## Requirements

- **WordPress** 6.0 or higher
- **PHP** 7.4 or higher
- Some tools need server capabilities (Redis/Memcached for object cache, Imagick for image optimization, ZipArchive for backups) — DiceStack detects these and clearly marks any tool your server can't run.

---

## FAQ

**Is there a free all-in-one WordPress plugin?**
Yes — DiceStack is a completely free all-in-one WordPress plugin. One install gives you 200+ modular tools for security, performance, SEO, WooCommerce, caching, backups and more, with no premium tier and no upsell.

**What's the difference between the Full Edition and the WordPress.org version?**
The Full Edition (here on GitHub) includes ~200+ tools, including developer power tools (code snippets, database tools, file manager, wp-config editor) that the WordPress.org directory doesn't allow. The WordPress.org version is a leaner, directory-vetted ~170-tool build. Both are free; pick whichever fits.

**How does the Full Edition update?**
It has a built-in updater that checks this repo's GitHub Releases and offers a normal one-click update inside wp-admin. It will never be overwritten by the WordPress.org version (it carries an `Update URI` header).

**Are the power tools safe?**
They run admin-authored code, so they're gated behind the highest capabilities (`manage_options`, and `unfiltered_html` for snippets), protected by nonces, default to safe modes (e.g. the SQL runner is read-only unless you opt in), and show clear warnings. Use them on a site you control, and keep backups.

**Will it slow my site down?**
No. Tools you don't enable load nothing at all. Enabling the caching and optimization tools typically makes sites **faster**.

**Will it conflict with my existing SEO / caching / security plugin?**
DiceStack detects common plugins and warns you before you enable a tool that overlaps.

**What happens to my settings when I update?**
Nothing changes — enabled tools stay enabled, disabled stay disabled, and all settings are preserved.

---

## Contributing

Issues and pull requests are welcome. Please open an [issue](https://github.com/IamRamgarhia/dicestack-security-speed-seo-tools/issues) for bugs or feature requests.

## License

DiceStack is free software, released under the **[GNU General Public License v2.0 or later](LICENSE)** — the same license as WordPress itself.

## Credits

Designed and built by **[Dice Codes](https://dicecodes.com)**.
📧 Contact@dicecodes.com
