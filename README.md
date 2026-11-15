# Speedy Cache for Craft CMS

Full-page caching that works with your templates as they are, keeps your database clean, and clears Cloudflare and CloudFront when content changes.

## Features

- **Static pages, no database tables.** Pages are saved as HTML files. What each page depends on is stored next to it, so the database never grows.
- **Served without PHP.** Generated Nginx and Apache rules let the web server send cached pages directly, in a millisecond or two. A live check confirms it works.
- **Smart clearing.** Uses Craft’s own cache tags: saving an entry clears the pages that show it and the pages that list its section, and nothing else.
- **Never a slow first visit.** Cleared pages are rebuilt in the background before the old copy is replaced.
- **Safe by default.** Logged-in visitors, carts, pages that set cookies and pages with query strings are never cached. Drafts and autosaves never clear live pages.
- **Forms just work.** CSRF tokens on cached pages are refreshed automatically, with no template changes.
- **Cloudflare and CloudFront built in.** Detected automatically and cleared when pages change. CloudFront paths are batched into wildcards to stay in the free tier.
- **Scheduled content.** Pages clear when a post goes live or expires.
- **See why.** A plain-English log: “Saved entry ‘Summer Sale’ → 12 pages cleared”.

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
```

## Console commands

| Command | Does |
|---|---|
| `php craft speedy/clear [--url=…]` | Clears every page, or one URL |
| `php craft speedy/warm` | Builds the pages in the warm list |
| `php craft speedy/expire` | Clears pages whose scheduled content changed (cron) |
| `php craft speedy/status` | Pages cached, CDN and recent clears |
| `php craft speedy/server-rules [--apache]` | Prints the web server rules |

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
