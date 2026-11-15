# Roadmap

What’s planned for Speedy Cache. Plans change with what people need; suggestions are welcome at [support@suprim.com.au](mailto:support@suprim.com.au).

Every 1.x release stays backward compatible: settings, events, console commands and the cache folder layout keep working as they are.

## 1.0.x: fixes from real sites

- Live testing of CDN clearing with Cloudflare, CloudFront, Fastly and Bunny accounts, and fixes for anything they turn up.
- Fixes from early reports, such as multi-site setups, unusual nginx configurations and shared hosting.

## 1.1: less to do by hand

| Feature | Why |
|---|---|
| **Rebuild after deploys** | When `craft up` applies project config changes, pages are rebuilt in the background on their own, with no step in the deploy script. |
| **Dashboard widget** | Pages cached, recent clears and CDN status at a glance. |
| **Important pages first** | After clearing everything, the homepage and top sections are rebuilt before the rest, using the sitemap’s priorities. |
| **Several Cloudflare zones** | For multi-site setups with a zone for each domain. |
| **Cache only some URLs** | The opposite of **Never cache**, for sites that only want a few sections cached. |

## 1.2: proof and polish

- A benchmarks page: response times and requests per second, without caching and with Speedy, on the same server, with the steps to repeat them.
- Two web servers sharing Redis, tested, and a guide for load-balanced hosting.
- A first-run check on the utility page: “Is it working?”, step by step.

## 2.0: Craft 6

- A version for Craft 6, ready around Craft 6’s release.
- Craft 6 runs on Laravel, so Speedy’s request handling moves to middleware, and the test suite runs on Craft 6 and PHP 8.5.
- 1.x keeps getting fixes for Craft 5.

## Not planned

- **Caching JSON and other responses.** Speedy caches HTML pages.
- **Server-side or edge-side includes.** Dynamic parts (`craft.speedy.dynamic()`) cover personal content with no server setup.
- **Caching search results.** Nearly every search is different, so cached results are rarely used again; it can be turned on with `cachedParams` where it helps.
- **Deploying cached pages to a Git repository or static host.**
