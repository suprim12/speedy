<p align="center"><img src="https://plugins.speedy.suprim.com.au/logo.svg" alt="Speedy Cache" width="96" height="96"></p>

# Speedy Cache for Craft CMS

**Pages in a millisecond, cleared only when they change.** Speedy Cache saves your pages as static files the web server sends without starting PHP, and uses Craft’s own cache tags to clear just the pages an edit affects. It works with your templates as they are, adds no database tables, and keeps Cloudflare, CloudFront, Fastly, Bunny and Varnish in step.

<img src="https://plugins.speedy.suprim.com.au/images/how-it-works.svg" alt="Saving an entry clears the 3 pages that show it; the next request is served as a static file" width="720">

## Why Speedy Cache

- **Fast for every visitor.** The web server sends cached pages directly, in about a millisecond, compressed with gzip or Brotli. Returning visitors get “304 Not Modified” and download nothing.
- **Cleared just right.** Saving an entry clears the pages that show it and the listings of its section, and nothing else. Drafts, autosaves and saves that change nothing never clear a live page.
- **Never a slow first visit.** Cleared pages are rebuilt in the background while visitors keep getting the old copy, and a new entry’s page is built as soon as it’s saved.
- **Safe by default.** Logged-in visitors, carts, form steps, flash messages and pages that set cookies are never cached, and a made-up cookie can’t reach the copy everyone gets.
- **It tells you what to fix.** Open any page as a visitor from the control panel and Speedy says, in words, why it isn’t cached and what to change.

## See what’s happening

<img src="https://plugins.speedy.suprim.com.au/images/utility.jpg" alt="The Speedy Cache utility page: pages cached, coverage, checks and tips, and actions to rebuild or clear pages" width="720">

- **Why isn’t this page cached?** Enter any address and get an answer such as “Not cached: the page sets `cart_token`”, with what to do about it.
- **Coverage.** How many pages of each section are cached, which couldn’t be built and why, and one button to build the rest.
- **Find slow templates.** The slowest pages, queries that run once for each item in a loop, and queries that clear a page whenever anything is saved, each with the template line that ran it.
- **A log in plain English.** “Saved entry ‘Summer Sale’ → 12 pages cleared”, with the pages.
- **Checks after a deploy.** Whether the web server rules are working, and whether cached pages link to CSS or JavaScript a deploy removed.
- **A dashboard widget,** and a button on each entry to clear its pages. Editors can be allowed to clear pages without the rest of the utility.

## Works with your site

- **Forms just work.** Cached pages get a fresh CSRF token as they load, with no template changes, and forms with steps keep their place.
- **Personal parts stay personal.** Put a cart count or a greeting in `craft.speedy.dynamic()` and the rest of the page stays cached.
- **Commerce.** Orders, stock, pricing rules and sales that start or end on a date clear the product’s pages.
- **Scheduled content.** Pages clear when a post goes live or expires, including the listings it appears on.
- **Imports.** Thousands of saves make one clear, and listings are rebuilt once at the end, not after each save.
- **Query strings, your way.** Only the parameters you list, such as `page`, are cached, with a cap so the disk can’t fill. Tracking codes are ignored.
- **Form, SEO and JavaScript plugins.** Forms send from cached pages, SEO tags are kept, and Alpine.js components start, inside dynamic parts too.

## Your CDN, cleared for you

Cloudflare, CloudFront, Fastly, Bunny and Varnish are built in. Speedy spots your CDN from its headers, clears it when pages change, and sets up Cloudflare’s cache rules in one click. CloudFront paths are batched into wildcards to stay in the free tier. With a CDN, visitors keep getting pages for a day if your server goes down.

## Tested before every release

Every release passes the whole test suite on two setups: the newest Craft on nginx, and the oldest supported Craft on Apache with its server rules in place. Real requests check that nobody gets someone else’s page, that forms send from cached pages, that SEO tags are kept, and that cached files can’t be opened at their own address. [How Speedy Cache is tested](https://plugins.speedy.suprim.com.au/getting-started/testing)

## Requirements

Craft CMS 5.6 or later and PHP 8.2 or later.

## Installation

```bash
composer require suprim/speedy
php craft plugin/install speedy
```

Pages are cached straight away. For the fastest pages, add the web server rules from **Utilities → Speedy Cache**, or print them:

```bash
php craft speedy/server-rules            # nginx
php craft speedy/server-rules --apache   # Apache
```

## In templates

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

## Console commands

| Command | Does |
|---|---|
| `php craft speedy/clear [--url=…] [--site=…] [--tag=…] [--rebuild]` | Clears every page, one URL or a pattern such as `/news/*`, one site or tagged pages, or rebuilds them in the background |
| `php craft speedy/warm` | Builds the pages in the warm list |
| `php craft speedy/expire` | Clears pages whose scheduled content changed (cron) |
| `php craft speedy/status` | Pages cached, CDN and recent clears |
| `php craft speedy/server-rules [--apache]` | Prints the web server rules |
| `php craft speedy/migrate [--apply]` | Reads another page cache plugin’s settings and templates, and shows what each becomes |

## Configuration

Most sites need two settings, both in the control panel: caching on, and the pages to never cache. For everything else, create `config/speedy.php`:

```php
return [
    'enabled' => true,
    'neverCache' => ['checkout/*', 'account*'],
    'warm' => 'sitemap',
    'cloudflareToken' => '$CLOUDFLARE_TOKEN',
    'cloudflareZoneId' => '$CLOUDFLARE_ZONE_ID',
    'cloudfrontDistributionId' => '$CLOUDFRONT_DISTRIBUTION_ID',
];
```

An event at every stage, an API and `craft.speedy.tag()` are there for developers.

## Support

Documentation: [plugins.speedy.suprim.com.au](https://plugins.speedy.suprim.com.au). Questions and bugs: [support@suprim.com.au](mailto:support@suprim.com.au).
