# The Utility Page

**Utilities → Speedy Cache** shows what’s cached and lets you clear it. It needs the **Utilities → Speedy Cache** permission.

![The Speedy Cache utility page](/images/utility.jpg)

## At the top

- **Pages cached** and their size on disk.
- **CDN:** the one Speedy detected, and whether it can clear it.
- **CloudFront paths this month,** when CloudFront is set up.
- The last result from each CDN.

## Actions

| Button | What it does |
|---|---|
| **Clear all pages** | Removes every cached page, clears your CDN, then warms the pages you listed |
| **Clear URL** | Clears one page, such as `https://example.com/news` |
| **Warm pages** | Builds the pages in your `warm` list now. Shown when `warm` is set |
| **Check server serving** | Checks that the web server is serving cached pages without PHP. See [Server rules](/getting-started/server-rules) |
| **Test Cloudflare / Test CloudFront** | Checks the CDN details. Shown when they’re set |

## Recent clears

The last clears, with when, why, how many pages and which URLs:

![Recent clears](/images/log.jpg)

Each line names what changed: “Saved entry ‘Summer Sale’ → 3 pages”, “Resaved 1,200 entries → 340 pages”, “Cleared from the command line”. CDN failures show here too.

## Serve pages without PHP

The rules for your web server, ready to copy.

## Craft’s caches utility

**Utilities → Caches** has a **Speedy Cache pages** option, which clears all pages along with Craft’s other caches.
