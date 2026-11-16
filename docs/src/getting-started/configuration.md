# Configuration

The settings page (**Settings → Plugins → Speedy Cache**) has three tabs:

- **General:** caching on or off, URLs to never cache or to only cache, what happens when content changes, and what to rebuild after clearing everything.
- **CDN:** your Cloudflare and CloudFront details, and whether Speedy has seen your site behind either.
- **Advanced:** the [API](/guides/api) key, with a button to generate one, and the options below that are only set in `config/speedy.php`, with the values in use now.

![Speedy Cache settings, General tab: a status strip, Cache pages, Never cache, Only cache, When content changes and After clearing everything](/images/settings.jpg)

![The Advanced tab: cache storage, the API key and the options set in config/speedy.php](/images/settings-advanced.jpg)

Everything else goes in `config/speedy.php`. Settings in the file override the settings page, which then shows the field as locked with a note saying so.

```php
<?php

use craft\helpers\App;

return [
    // Turn caching off on dev and staging.
    'enabled' => App::env('CRAFT_ENVIRONMENT') === 'production',

    // URL paths that are never cached. * matches anything.
    'neverCache' => ['checkout/*', 'account*', 'search'],

    // Pages to build after everything is cleared: 'sitemap', a list of URLs, or false.
    'warm' => 'sitemap',

    // CDN details, best kept in .env.
    'cloudflareToken' => '$CLOUDFLARE_TOKEN',
    'cloudflareZoneId' => '$CLOUDFLARE_ZONE_ID',
];
```

## All options

| Option | Default | Description |
|---|---|---|
| `enabled` | `true` | Whether pages are cached |
| `neverCache` | `[]` | URL paths never cached, such as `checkout/*`. On the settings page, one per line |
| `onlyCache` | `[]` | URL paths to cache, when only some pages should be, such as `['/', 'news*']`. `/` is the homepage. Empty caches every page ([What isn’t cached](/guides/not-cached#caching-only-some-pages)) |
| `queryStrings` | `'skip'` | `'skip'`: pages with a query string aren’t cached. `'ignore'`: they’re cached without it, for sites that never use query strings for content |
| `cachedParams` | `[]` | Query parameters that make a page of their own, such as `['page', 'category']` ([Pagination and filters](/guides/not-cached#pagination-and-filters)) |
| `maxQueryPages` | `100` | The most query string versions of one page to keep ([Pagination and filters](/guides/not-cached#pagination-and-filters)) |
| `ignoredElementTypes` | `[]` | Element types whose changes never clear pages, such as `[craft\elements\User::class]` |
| `clearInRequest` | `'auto'` | Clear pages straight away instead of in the queue: `'auto'` only while the queue isn’t running, `true` always, `false` never ([Cron and queue](/recipes/cron#if-the-queue-stops)) |
| `ignoreUnchangedSaves` | `true` | Don’t clear pages when something is saved without changes ([How clearing works](/guides/clearing#what-doesn-t-clear-pages)) |
| `ignoredParams` | `utm_*`, `gclid`, `fbclid`… | Tracking parameters that never stop a page being cached |
| `skipWhenCart` | `true` | Don’t cache for visitors with a Commerce cart |
| `skipCookies` | `[]` | Cookies that mean a visitor sees their own version of pages, matched by part of the name, such as `['cookie_consent']` ([What isn’t cached](/guides/not-cached#cookies-that-change-pages)) |
| `ignoredCookies` | `[]` | Cookies that never change pages, as well as the built-in analytics ones. Pages are only saved from requests without other cookies ([What isn’t cached](/guides/not-cached#made-up-cookies-can-t-reach-the-shared-copy)) |
| `refreshCsrf` | `true` | Refresh CSRF tokens on cached pages ([Forms](/guides/forms)) |
| `rebuildCleared` | `true` | Rebuild cleared pages in the background before replacing them ([Warming](/guides/warming)) |
| `rebuildAfterDeploy` | `false` | Rebuild every cached page in the background when a deploy applies project config changes ([Deploy script](/recipes/deploy)) |
| `queryReport` | `true` | Count the queries of pages Speedy rebuilds, and list pages that run the same query again and again ([Repeated queries](/guides/utility#repeated-queries)) |
| `warm` | `false` | Pages to build after a full clear: `'sitemap'` or a list of URLs |
| `sitemap` | `'sitemap.xml'` | The sitemap to read for `warm`, relative to each site |
| `warmConcurrency` | `2` | How many pages are built at once |
| `defaultTtl` | `null` | A maximum age for every page, in seconds ([Live data](/recipes/live-data)) |
| `apiKey` | `''` | A key of 16 characters or more for clearing over HTTP ([API](/guides/api)). Also on the Advanced tab. Empty turns the API off |
| `cacheComment` | `true` | Add `<!-- Cached by Speedy Cache on … -->` to the end of cached pages |
| `storage` | `'files'` | Where pages are kept: `'files'`, or `'craftCache'` for Craft’s cache such as Redis, shared by several servers ([Multi-site and multiple servers](/recipes/multi-site#keep-pages-in-redis)). Also on the Advanced tab |
| `cachePath` | `'@webroot/speedy'` | Where pages are saved. Must be in the web root for [server serving](/getting-started/server-rules) |
| `dataPath` | `'@storage/speedy'` | Where Speedy keeps tags, the log and CDN details |
| `cloudflareToken`, `cloudflareZoneId` | | [Cloudflare](/guides/cloudflare) |
| `cloudflareZones` | `[]` | A Cloudflare zone ID for each domain, such as `['example.com' => '$CF_ZONE_COM']` ([Several zones](/guides/cloudflare#several-domains)) |
| `cloudfrontDistributionId`, `awsAccessKeyId`, `awsSecretAccessKey` | | [CloudFront](/guides/cloudfront). The keys fall back to `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` |
| `fastlyToken`, `fastlyServiceId`, `bunnyApiKey`, `bunnyPullZoneId`, `varnishUrls` | | [Fastly, Bunny and Varnish](/guides/other-cdns) |
| `warmUsername`, `warmPassword` | | For rebuilding pages on a site behind basic authentication, such as staging. Use environment variables |
| `cdnMaxAge` | `31536000` | How long the CDN keeps pages, when Speedy can clear it |
| `wildcardThreshold` | `30` | CloudFront: more paths than this are grouped into wildcards |
