# Release Notes for Speedy Cache

## Unreleased

### Added
- **Repeated queries**: the utility page lists pages that run the same database query again and again (N+1 queries), with the template and line, and how to fix it. Counted while Speedy rebuilds pages, never on visitors’ requests.
- The utility page has tabs for cached pages, recent clears, repeated queries and setup, under the totals and actions.
- A dashboard widget with the pages cached, recent clears, the CDN and any problems.
- **Only cache**: cache just the pages that match, such as `news*`, with `/` for each site’s homepage. Empty caches every page, as before.
- `rebuildAfterDeploy` rebuilds cached pages in the background when a deploy applies project config changes.
- After clearing everything, the homepage and top pages are rebuilt first, using the sitemap’s priorities.
- A Cloudflare zone for each domain, with `cloudflareZones`, for sites on several domains. It can come from one environment variable.
- Importing the other page cache’s settings brings over its included pages and its Cloudflare zone for each site.

### Fixed
- Saving pages no longer gets slower and slower when thousands of cached pages share a tag, such as a section’s entries.
- Writing the list of what a page depends on is locked, so a page added while the list is tidied or cleared is never lost, and a line cut short by a full disk is taken back off.
- On a full disk, a page that’s only partly saved, or whose list of what it depends on couldn’t be saved, is taken out instead of being sent or left uncleared.
- New pages aren’t cached while the disk has less than 256 MB free, with an error on the utility page.
- The “queue hasn’t run” alert no longer shows when the control panel is opened after a quiet spell, on sites where the control panel runs the queue. It now shows only if the queue still hasn’t run ten minutes later.

## 1.0.0 - 2026-11-15

### Added
- Full-page caching of logged-out GET pages as static HTML files, with no database tables.
- Pages are served by the web server without PHP, with generated Nginx and Apache rules and a live check, or by PHP when the rules aren’t added.
- Clearing based on Craft’s own cache tags: saving an entry clears the pages that show it and the pages that list its section.
- One clear job per request, queue job or bulk operation, such as an import.
- Drafts, autosaves and revisions never clear live pages.
- Cleared pages are rebuilt in the background before the old copy is replaced.
- Pages clear when scheduled content goes live or expires, including the listing pages of entries and products with future post dates.
- Forms keep working on cached pages: CSRF tokens are refreshed automatically.
- Logged-in visitors, Commerce carts, pages that set cookies and pages with query strings aren’t cached.
- Pages are only saved from requests without cookies of their own, so a made-up cookie can’t end up in the copy everyone gets. Analytics cookies are ignored, and more can be added with `ignoredCookies`.
- Saving a supported SEO plugin’s site-wide settings rebuilds every cached page in the background.
- Saving without changes doesn’t clear pages, including control panel saves that send every field, and nor does saving a disabled entry that stays disabled.
- Commerce stock changes clear the pages that show the product, from orders, control panel edits and transfers.
- Replacing an asset’s file clears the old file and its transforms from the CDN.
- Pages with query strings can be cached separately, for the parameters listed in `cachedParams`, such as `?page=2`.
- `craft.speedy.tag()` names a page, to clear it with `speedy/clear --tag`, the API or PHP.
- While the queue isn’t running, pages are cleared straight away and expired pages are built for their next visitor, with an alert in the control panel, so changes still show.
- Events at each stage: before a request is cached, after a page is saved, when content changes, before pages are cleared, before and after warming, and before and after clearing the CDN.
- `ignoredElementTypes` for element types whose changes never clear pages.
- Craft’s pagination parameter is cached when `pageTrigger` uses a query string, such as `'?page='`.
- At most `maxQueryPages` query string versions of a page are kept, 100 by default.
- `speedy/migrate` shows what another page cache plugin’s settings and template calls become in Speedy, and can save the settings.
- The cache folder can’t be opened directly on Apache, even without the server rules.
- Clearing a page removes its empty folders.
- Multi-page forms and flash messages are safe: visitors with something in their session get their own pages, never a cached step or someone else’s message.
- Cloudflare and CloudFront: detected automatically and cleared when pages change, with CloudFront paths batched into wildcards and a monthly counter.
- CDNs are told not to keep pages that Speedy can’t clear from them.
- Warming from the sitemap or a list of URLs.
- Utilities → Speedy Cache, with stats, clear actions, a server check, CDN tests and a log of what was cleared and why.
- `craft.speedy.skip()` and `craft.speedy.ttl()` for templates.
- Cached pages end with a comment saying when they were cached (`cacheComment`).
- Ajax requests (htmx, Unpoly, `X-Requested-With`), pages that send `Vary`, and cookies listed in `skipCookies` aren’t cached or served from the cache.
- Response headers such as `X-Robots-Tag`, `Link` and `Content-Security-Policy` are kept with cached pages.
- Pages that aren’t cached are sent as `private`, so CDNs never keep them.
- Only the sites’ own domains are cached, so a forged `Host` header can’t fill the disk.
- Background rebuilds reach your server even when a CDN keeps pages.
- Settings page with General, CDN and Advanced tabs: what happens when content changes and after clearing everything, a card for each CDN, an API key field with a key generator, the values of options set in `config/speedy.php`, and a note on any setting the file overrides.
- Warnings and errors are written to `storage/logs/speedy.log`, rotated daily.
- A list of cached pages on the utility page, searchable, with what each page depends on in plain words and a Clear button.
- Checks and tips on the utility page: failed jobs, CDN errors, unwritable folders, pages that clear on every save, and more.
- A Page cache line in the details of entries and other elements: cached or not, when, and how many cached pages show it.
- A “Clear cached pages” action for entries, categories and Commerce products.
- Works with form plugins that keep progress in the session: pages showing such a form aren’t cached, and seeing one doesn’t stop the visitor getting cached copies of other pages.
- When many visitors ask for the same uncached page at once, one request builds it and the others wait for that copy.
- Clearing everything is instant however many pages there are; old files are deleted in the background.
- Rebuild all pages, from the utility page or `speedy/clear --rebuild`, keeping the old copies until the new ones are ready.
- `craft.speedy.dynamic()` for parts of a cached page built for each visitor, such as a cart count or a form, loaded in one request with their scripts.
- An API for clearing and rebuilding pages over HTTP with a key.
- Expired pages (`ttl()` and scheduled content) keep being sent while their new copy is built, so no visitor waits.
- Compressed copies of each page are saved, and sent by PHP and by nginx (`gzip_static`) without compressing each time.
- Turns itself off on hosts with a temporary disk, such as Craft Cloud, and says why.
- Pages can be kept in Craft’s cache, such as Redis, so several web servers share them without a shared disk.
- Fastly, Bunny and Varnish, alongside Cloudflare and CloudFront.
- Events for plugins and modules: before a page is cached, after pages are cleared, and registering another CDN.
- Rebuilds work on sites behind basic authentication, such as staging.
- A support report on the utility page, with secrets left out.
- Multi-site: each site’s pages are cached separately and cleared together when shared content changes, including sites in subfolders.
- Cached pages work on every address a site answers on, such as with and without www, or a CDN hostname.
- Console commands: `speedy/clear`, `speedy/warm`, `speedy/expire`, `speedy/status` and `speedy/server-rules`.
