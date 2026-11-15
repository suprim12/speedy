<p align="center"><img src="https://plugins.speedy.suprim.com.au/logo.svg" alt="Speedy Cache" width="96" height="96"></p>

# Speedy Cache for Craft CMS

Full-page caching that works with your templates as they are, keeps your database clean, and clears Cloudflare, CloudFront, Fastly, Bunny and Varnish when content changes.

![Utilities → Speedy Cache: pages cached, recent clears and a server check](https://plugins.speedy.suprim.com.au/images/utility.jpg)

## Features

- **Static pages, no database tables.** Pages are saved as HTML files. What each page depends on is stored next to it, so the database never grows.
- **Served without PHP.** Generated Nginx and Apache rules let the web server send cached pages directly, in a millisecond or two. A live check confirms it works.
- **Smart clearing.** Uses Craft’s own cache tags: saving an entry clears the pages that show it and the pages that list its section, and nothing else.
- **Never a slow first visit.** Cleared pages are rebuilt in the background before the old copy is replaced.
- **Safe by default.** Logged-in visitors, carts, form steps, flash messages and pages that set cookies are never cached, and a made-up cookie can’t reach the copy everyone gets. Drafts, autosaves and saves that change nothing never clear live pages.
- **Query strings, your way.** Not cached unless you list them, such as `page`, with a cap per page so the disk can’t fill.
- **Commerce stock.** Orders, control panel edits and transfers clear the product’s pages.
- **Keeps working when the queue stops.** Pages are cleared straight away, with a control panel alert, until it runs again.
- **Forms just work.** CSRF tokens on cached pages are refreshed automatically, with no template changes.
- **Cloudflare, CloudFront, Fastly, Bunny and Varnish built in.** Cleared when pages change, and other plugins can add more. CloudFront paths are batched into wildcards to stay in the free tier.
- **Scheduled content.** Pages clear when a post goes live or expires, including the listings it appears on.
- **See why.** A plain-English log: “Saved entry ‘Summer Sale’ → 12 pages cleared”.
- **For developers.** An event at every stage, `craft.speedy.tag()`, an API and console commands.

![Recent clears, with the reason and the pages](https://plugins.speedy.suprim.com.au/images/log.jpg)

![Each entry shows whether its page is cached](https://plugins.speedy.suprim.com.au/images/entry-status.jpg)

![CDN settings](https://plugins.speedy.suprim.com.au/images/settings-cdn.jpg)

## Requirements

Craft CMS 5.6 or later and PHP 8.2 or later.

## Installation

```bash
composer require suprim/speedy
php craft plugin/install speedy
```

Pages are cached straight away. For the fastest pages, add the web server rules from **Utilities → Speedy Cache**, or print them:

```bash
php craft speedy/server-rules            # Nginx
php craft speedy/server-rules --apache   # Apache
```

## In templates

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
| `php craft speedy/clear [--url=…] [--tag=…] [--rebuild]` | Clears every page, one URL or tagged pages, or rebuilds them in the background |
| `php craft speedy/warm` | Builds the pages in the warm list |
| `php craft speedy/expire` | Clears pages whose scheduled content changed (cron) |
| `php craft speedy/status` | Pages cached, CDN and recent clears |
| `php craft speedy/server-rules [--apache]` | Prints the web server rules |
| `php craft speedy/migrate [--apply]` | Reads another page cache plugin’s settings and templates, and shows what each becomes |

## Configuration

Create `config/speedy.php`:

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

## Running the tests

The tests boot the Craft project Speedy Cache is installed in. From the project root, with PHPUnit installed in the project:

```bash
php vendor/bin/phpunit -c plugins/speedy/phpunit.xml.dist --testsuite unit,integration
SPEEDY_TEST_URL=https://example.test php vendor/bin/phpunit -c plugins/speedy/phpunit.xml.dist   # real requests too
```

The http suite makes real requests, with its own temporary templates, so no other page cache plugin may be enabled.

## Support

Documentation: [plugins.speedy.suprim.com.au](https://plugins.speedy.suprim.com.au). Questions and bugs: [support@suprim.com.au](mailto:support@suprim.com.au).
