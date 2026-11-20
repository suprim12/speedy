<p align="center"><img src="https://plugins.speedy.suprim.com.au/logo.svg" alt="Speedy Cache" width="96" height="96"></p>

<h1 align="center">Speedy Cache for Craft CMS</h1>

<p align="center"><strong>Full-page caching that sends pages in a millisecond and clears only what changed.</strong><br>No database tables, CDNs built in, and it tells you what to fix.</p>

<p align="center"><a href="https://plugins.speedy.suprim.com.au">Documentation</a> · <a href="https://plugins.speedy.suprim.com.au/getting-started/installation">Get started</a> · <a href="https://github.com/suprim12/speedy/blob/main/CHANGELOG.md">Changelog</a> · <a href="mailto:support@suprim.com.au">Support</a></p>

<p align="center"><img src="https://plugins.speedy.suprim.com.au/images/how-it-works.svg" alt="Saving an entry clears the 3 pages that show it; the next request is served as a static file" width="720"></p>

Speedy Cache saves each page as a static file that your web server sends without starting PHP or touching the database. When content changes, Craft’s own cache tags tell Speedy exactly which pages showed it, so only those are cleared and rebuilt in the background. Your templates stay as they are.

## Highlights

| | |
|---|---|
| **Fast for every visitor** | Cached pages are sent by the web server in about a millisecond, compressed with gzip or Brotli. Returning visitors get “304 Not Modified” and download nothing. |
| **Cleared precisely** | Saving an entry clears the pages that show it and the listings of its section, and nothing else. Drafts, autosaves and saves that change nothing never clear a live page. |
| **No slow first visit** | Cleared pages are rebuilt in the background while visitors keep getting the old copy. A new entry’s page is built as soon as it’s saved. |
| **Safe by default** | Logged-in visitors, carts, form steps, flash messages and pages that set cookies are never cached, and a made-up cookie can’t reach the copy everyone gets. |
| **CDNs built in** | Cloudflare, CloudFront, Fastly, Bunny, KeyCDN and Varnish are cleared for you, with Cloudflare’s cache rules set up in one click. |
| **Clean install** | No database tables and no template changes. Uninstalling removes its cached pages and data. |

## It tells you what to fix

<p align="center"><img src="https://plugins.speedy.suprim.com.au/images/utility.jpg" alt="The Speedy Cache utility page: pages cached, coverage, checks and tips, and actions to rebuild or clear pages" width="720"></p>

- **Why isn’t this page cached?** Enter any address and get a plain answer, such as “Not cached: the page sets `cart_token`”, with the change to make.
- **Coverage.** How many pages of each section are cached, which couldn’t be built and why, and one button to build the rest.
- **Performance.** The slowest pages, queries that run once for each item in a loop, and queries that clear a page whenever anything is saved, each with the template and line that ran it.
- **A log in plain English.** “Saved entry ‘Summer Sale’ → 12 pages cleared”, with the pages.
- **Checks after a deploy.** Whether the web server rules work, whether the cache folder is closed to direct requests and search engines, and whether cached pages link to CSS or JavaScript a deploy removed.
- **For editors.** A dashboard widget, a **Page cache** column in entry lists, a button on each entry to clear its pages, clearing several sections or sites at once, and a permission to clear pages without the rest of the utility.
- **For uptime monitors.** A status URL that answers 200 while caching works and 503 with the problem when something needs fixing, such as a stopped queue.

## Works with your site

- **Forms.** Cached pages get a fresh CSRF token as they load, and forms with steps keep each visitor on their own step.
- **Personal content.** Put a cart count or a greeting in `craft.speedy.dynamic()` and the rest of the page stays cached.
- **Scheduled content.** Pages clear when a post goes live or expires, including the listings it appears on. Sections that show data from outside Craft can have a lifetime of their own.
- **Imports.** Thousands of saves make one clear, and listings are rebuilt once at the end.
- **Query strings.** Only the parameters you list, such as `page`, are cached, with a cap so the disk can’t fill. Tracking codes are ignored.
- **Instant links.** Optionally fetch a page as soon as a visitor points at its link, so it’s there before they click.
- **Scripts.** Alpine.js, React and Vue components start on cached pages, inside dynamic parts too.
- **Bots and busy servers.** The “not found” page can be kept for a while, so bots asking for missing addresses don’t load the server, and rebuilding waits while the server is busy.
- **Multi-site and several servers.** Pages can be cleared per site, and kept in Craft’s cache, such as Redis, when several servers share the site.

## Works with popular plugins

| | |
|---|---|
| **Craft Commerce** | Orders, stock changes, pricing rules and sales that start or end on a date clear the product’s pages. Visitors with a cart get pages built for them. |
| **Formie** | Forms send from cached pages: their tokens are refreshed as the page loads, and forms with several pages keep each visitor on their own page. |
| **SEOmatic** | Meta tags, JSON-LD and tracking scripts stay in the cached page, and saving SEOmatic’s settings rebuilds every page in the background. |
| **Feed Me** | An import clears each page once, however many entries it saves, and the clean-up run before each feed doesn’t clear the cache. |

Each is covered by the test suite, such as sending a Formie form from a cached page. [Compatibility](https://plugins.speedy.suprim.com.au/guides/compatibility)

## Moving from another page cache

`php craft speedy/migrate` reads your current page cache plugin’s settings and templates and shows what each becomes, before you change anything. [Switching guide](https://plugins.speedy.suprim.com.au/guides/switching)

## Tested before every release

Every release passes the full test suite on two setups: the newest Craft on nginx, and the oldest supported Craft on Apache with its server rules in place. Real requests check that nobody gets someone else’s page, that forms send from cached pages, that SEO tags are kept, and that cached files can’t be opened at their own address. A headless browser checks that scripts start on cached pages. [How Speedy Cache is tested](https://plugins.speedy.suprim.com.au/getting-started/testing)

## Requirements

- Craft CMS 5.6 or later
- PHP 8.2 or later
- nginx, Apache or any server that runs Craft. The optional server rules for the fastest pages are given for nginx and Apache.

## Installation

Install from the Plugin Store in the control panel, or with Composer:

```bash
composer require suprim/speedy
php craft plugin/install speedy
```

Pages are cached straight away. For the fastest pages, add the web server rules shown under **Utilities → Speedy Cache**, or print them:

```bash
php craft speedy/server-rules            # nginx
php craft speedy/server-rules --apache   # Apache
```

## For developers

Nothing to change to get started. When you need more:

```twig
{# Never cache this page #}
{% do craft.speedy.skip() %}

{# Clear this page after 10 minutes, for content from outside Craft #}
{% do craft.speedy.ttl(600) %}

{# Name this page, to clear it with speedy/clear --tag=weather #}
{% do craft.speedy.tag('weather') %}

{# A part built for each visitor, such as a cart count #}
{{ craft.speedy.dynamic('_partials/cart') }}
```

| Command | Does |
|---|---|
| `php craft speedy/clear [--url=…] [--site=…] [--tag=…] [--rebuild]` | Clears every page, one URL or a pattern such as `/news/*`, one site or tagged pages, or rebuilds them in the background |
| `php craft speedy/warm` | Builds the pages in the warm list |
| `php craft speedy/expire` | Clears pages whose scheduled content changed (cron) |
| `php craft speedy/status` | Pages cached, CDN and recent clears |
| `php craft speedy/server-rules [--apache]` | Prints the web server rules |
| `php craft speedy/migrate [--apply]` | Shows, then applies, what another page cache plugin’s settings become |

Most sites need two settings, both in the control panel: caching on, and the pages to never cache. Everything else can go in `config/speedy.php`:

```php
return [
    'enabled' => true,
    'neverCache' => ['checkout/*', 'account*'],
    'warm' => 'sitemap',
    'cloudflareToken' => '$CLOUDFLARE_TOKEN',
    'cloudflareZoneId' => '$CLOUDFLARE_ZONE_ID',
];
```

Events at every stage and a PHP API are covered in the [documentation](https://plugins.speedy.suprim.com.au/guides/events).

## Support

- Documentation: [plugins.speedy.suprim.com.au](https://plugins.speedy.suprim.com.au)
- Questions and bug reports: [support@suprim.com.au](mailto:support@suprim.com.au). The **Setup** tab on the utility page has a support report with everything needed to reproduce an issue.
