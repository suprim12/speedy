# Release Notes for Speedy Cache

## 1.7.0 - 2026-11-21

### Added
- **Never cache sites** on the General tab, on a site with several: a whole site, such as a members’ area, isn’t cached, and its pages cached before are removed. Also `neverCacheSites` in `config/speedy.php`.
- **Open pages ahead** for instant links: the browser opens the page out of sight once a visitor points at its link, so it shows at once. Speedy’s own scripts, such as the CSRF token refresh and dynamic parts, wait until the page is shown, and so do scripts marked `data-speedy-wait`, such as analytics. Off by default; `instantLinksPrerender` in `config/speedy.php`.
- The control panel in German, Dutch and French.
- **Clear by pattern** with a pattern ending in `/*`, and **Clear site**, clear Cloudflare by prefix or host in one request, which also clears copies Speedy never had, such as with tracking parameters.

### Fixed
- The API’s clear sent with a single URL or tag as plain text, such as `urls=https://example.com/news` rather than `urls[]=…`, cleared every page instead of that one. It now clears that page, and a call with an empty list is refused.
- Addresses with a part too long for a file name, such as made-up ones from bots, failed to save on every visit and filled the log with errors. They’re now not cached (`X-Speedy: SKIP url`).
- With instant links on, a browser fetching a page ahead for a visitor with something in their session, such as a flash message, could use it up on a page they didn’t open. That request is now refused, and the page opens as usual on the click.
- Pages already cached are removed when **Never cache** or **Only cache** starts leaving them out. The web server used to keep sending them until they were next cleared.
- Changes Speedy noted at the same moment, such as by a rebuild and a CDN clear, could undo each other. The status endpoint could then report a CDN clear that failed after a later one worked, the CloudFront path count for the month came out too low, and turning instant links on or off could rebuild every page a second time.
- With **Pages that don’t exist (404)** on, an entry or product that went live at its post date could still get the “not found” page kept from before, for as long as it was kept.
- The status endpoint, the utility page and **Clear all** no longer read the whole queue to find Speedy’s failed jobs, which was slow on a site with a big queue, such as during an import.

## 1.6.0 - 2026-11-20

### Added
- A **Page cache** column for the entries, categories and products tables, chosen from the table's columns menu: whether each page is cached and when, or why it isn't.
- A status endpoint for uptime monitors, `actions/speedy/api/status`: 200 while caching works, and 503 with the problems when something needs fixing, such as a stopped queue or a failed CDN clear. The **Advanced** tab of the settings shows its URL, with a token that can only read the status.
- **Clear section** and **Clear site** in the utility take several sections or sites at once, cleared together as one clear.
- **Clear section** also clears category groups and Commerce product types: their listings and the pages of their categories and products.
- KeyCDN: changed pages are cleared from it, set up on the **CDN** tab.
- **Pages that don’t exist (404)** on the General tab keeps the “not found” page for a while, so bots asking for missing addresses don’t load the server. It’s sent with status 404 and forgotten whenever content changes. Off by default.
- **Never cache entry types** and **Never cache categories** on the General tab: the pages of entries of those types, of those categories, and of entries and products that have them aren’t cached. Pages cached before are removed when one is added.
- Rebuilding waits while the server is busy, by its load for each CPU core (`warmBusyLoad`, 2 by default), for up to about five minutes.

### Fixed
- **Lifetimes by section** on the settings page showed a section that was renamed, deleted or no longer has pages as another section, so saving the settings moved its lifetime to that section. It’s now listed as it was saved, such as “news (not found)”.

## 1.5.0 - 2026-11-19

### Added
- **Lifetimes by section** on the settings page’s General tab: the pages of a section’s entries are rebuilt after the time you choose, such as an hour for ones that show data from outside Craft, and still cleared straight away when they change. Also `sectionLifetimes` in `config/speedy.php`, in seconds.

### Fixed
- **Coverage** on the utility page counted entries that redirect elsewhere, such as ones that only link to another page, as pages that couldn’t be built. They’re now left out of the count, with a note of how many redirect.
- An entry’s address with its letters in another case, such as `/News` for `/news`, opens the same entry, but each spelling was cached as a copy of its own, so made-up spellings could fill the cache. Only the URI’s own spelling is cached now (`X-Speedy: SKIP url case`), and copies kept from before are removed when they’re next rebuilt.
- On sites whose `index.php` changes the address before Craft starts, such as taking a prefix off to show the same page another way, both addresses shared one cached copy, so visitors to one could get the version built for the other. Each address now keeps its own copy, sent by the web server like any other page. Any other change to the address, such as adding a language, is no longer cached (`X-Speedy: SKIP rewritten URL`).

## 1.4.2 - 2026-11-18

### Added
- When cached pages can be opened directly in the cache folder, **Check server serving** reads the site’s `robots.txt`, and the utility page names the `Disallow` line that keeps search engines out of the folder, and the one nginx line that closes it.

### Changed
- **Cleared by any change** on the Performance tab lists each template line once, with the pages it’s on, the line on most pages first, rather than once for each page, so a query in a layout no longer fills the list.
- The Performance tab’s lists show their first 10 rows, and a page’s repeated queries their first 3, with **Show more** at the bottom, which becomes **Show less** once open. Click a query’s template and line to see the code there.

## 1.4.1 - 2026-11-18

### Added
- Pages sent by PHP answer “304 Not Modified” when the browser already has them, with `ETag` and `Last-Modified`, as the web server does for its own files, so returning visitors don’t download them again.
- **Check CSS and JavaScript** on the utility page’s Setup tab says whether cached pages link to CSS or JavaScript that no longer exists, such as after a deploy replaced the built files, with an example. It only runs when clicked.

### Changed
- The utility page’s actions are grouped into three cards: every page, some pages, and checking a page. **Rebuild all pages** comes first, as it keeps the old copies until the new ones are ready.

### Fixed
- Clearing everything left the Performance report and Speedy’s failed queue jobs about the pages it removed, so the utility page kept showing them. Both are now cleared with the pages.
- A browser that refuses a compression in its `Accept-Encoding` header, such as `gzip;q=0`, was still sent a page compressed that way when PHP sent it.
- With PHP’s Brotli extension, each page was compressed at Brotli’s slowest setting, delaying the visitor who built it, and the copy was never sent. It’s now compressed quickly and sent to browsers that accept it, by PHP and by the Apache rules; the nginx rules have a line for it to turn on. Copy the server rules again to send it.
- One dynamic part with an error in its template kept every other dynamic part on the page from loading. The others now load, and the error is logged.
- Background builds of slow pages failed when they ran past the queue’s time for a job (“exceeded the timeout of 300 seconds”), leaving the rest of their pages unbuilt. A job now stops starting pages a minute before its time is up, and queues the rest as a job of their own.
- **Clear all pages** replaced a cache folder linked into the site, such as `web/speedy` shared between a deploy’s releases, with a folder of its own, so releases stopped sharing it and the old pages were never deleted.

## 1.4.0 - 2026-11-18

### Added
- **Set up Cloudflare** on the utility page adds the cache rules that let Cloudflare keep pages, leaving out logged-in visitors, carts, forms and the control panel. Other rules in the zone are kept.
- Coverage on the utility page: how many of the pages of entries, categories and products are cached, by section, with the ones that aren’t, why a page couldn’t be built, and a button to build them all. Sections whose template doesn’t exist, and URIs with a query string, are left out.
- New pages are built in the background when an entry, category or product is saved or goes live, so the first visitor gets a cached page (`buildSavedPages`, on by default; not during imports).
- **Rebuild every day** rebuilds listed pages at a set time, for pages that show dates (`dailyRebuild` and `dailyRebuildTime`).
- **Clear URL** takes patterns such as `https://example.com/news/*` or `/news/*`, on the utility page, the command line and the API.
- **Clear site** on the utility page, and `speedy/clear --site`, clear every page of one site.
- **Clear its cached pages** on an entry’s, category’s or product’s details panel.
- **Show the code** on the Performance tab shows the template lines where each repeated query, or query without a section, ran, and **Show more** lists all of a page’s queries.

### Fixed
- Pages that redirect say where to, such as “redirects to /now-selling/land”, rather than “status 302”.
- The Performance tab named strings rendered as templates, such as `__string_template__…`, instead of the template file that rendered them.
- Pages couldn’t be built in the background (“SKIP rebuild token”) on servers that pass the path to `index.php` as a query parameter, such as Apache with `index.php?p=$1`.

## 1.3.0 - 2026-11-17

### Added
- `maxCacheSize`, a limit on the disk space cached pages may use.
- **Why isn’t this page cached?** on the utility page: opens a page as a visitor and says why it is or isn’t cached, and what to change.
- Pages built before their image transforms were made are rebuilt a minute later, so they link to the images themselves.
- CDNs keep sending pages while they fetch a new copy, and for a day while the site is down (`stale-while-revalidate` and `stale-if-error`).
- **Clear section** on the utility page clears every page that shows a section, such as after changing its templates.
- A permission to clear cached pages from entry, category and product indexes, without the rest of the utility.
- Instant links: browsers fetch a page as soon as a visitor points at its link, so it opens straight away (`instantLinks`, off by default). Turning them on or off rebuilds the cached pages.
- Pages that clear whenever anything of their type changes wait a minute before they’re rebuilt, so an import rebuilds them once (`broadRebuildDelay`).

### Fixed
- Cached pages show Commerce’s current prices: saving or deleting a pricing rule clears the products’ pages, and so does a price starting or ending on its date.
- Cached pages are kept out of git, so `git add` no longer picks them up.
- Pages left over from clearing everything are deleted even when the queue isn’t running.
- **Check server serving** warns when cached pages can be opened directly in the cache folder.
- `cachePath` and `dataPath` must be folders of their own: the settings page won’t save a folder such as the web root, an uploads folder or Craft’s published resources, and set in `config/speedy.php`, Speedy doesn’t cache in it, empty it or add a `.gitignore` to it, and **Clear all** says so.
- A page is only cached for a plain host: a request naming another, such as in `X-Forwarded-Host`, can’t save a broken copy of a page. On a site whose base URL is `@web`, the host must be the one the request was sent to.
- On a site whose base URL is `@web` without `allowedHosts`, visitors with cookies don’t get pages built in the background and the warming login isn’t sent, so a made-up host can’t make the server request it. The utility page asks for `allowedHosts`.
- Warming from a sitemap only requests pages and sitemaps on the sitemap’s own host or the sites’ hosts.
- A page whose content changed while it was being rebuilt is rebuilt again, rather than keeping its older copy until something else changes.

## 1.2.0 - 2026-11-17

### Added
- The utility page names query parameters that often keep pages out of the cache, and says whether to cache or ignore them.
- A Query strings settings tab: parameters to cache, such as `view` for a tab, parameters to ignore, and the ones seen keeping pages out of the cache, added in one click.
- Clearer settings: a table for each list of paths and parameters, and one row for each CDN.
- The utility page lists the slowest pages, and the template line of each query that makes a page clear whenever anything of its type is saved.
- Captchas and form tokens are refreshed on cached pages for supported form plugins, so pages with those forms are cached too.
- **Email support** on the utility page, with the support report.

### Fixed
- **Clear URL** clears the page’s query string versions too, such as `?page=2`.
- Craft’s garbage collection, which some importers run before each import, no longer clears every cached page.
- “Rebuild all pages” builds the pages to warm when nothing is cached, instead of rebuilding 0 pages.
- When pages can’t be rebuilt in the background, the log and the utility page say why, such as a password on the site.

## 1.1.0 - 2026-11-16

### Added
- A report of pages that run the same database query in a loop (N+1 queries), with the template and line.
- Tabs on the utility page, and a dashboard widget.
- **Only cache**, to cache only the pages you choose.
- Important pages are rebuilt first after clearing everything.
- `rebuildAfterDeploy`, to rebuild cached pages after a deploy.
- `cloudflareZones`, for a Cloudflare zone per domain.
- `allowedHosts`, to list the host names to cache for a site whose base URL is `@web`.

### Fixed
- **Never cache** matches the path within each site, such as for a site at `/de/`.
- Fixed cases where a page could stay out of date.
- Saving pages stays fast when thousands of them share a tag.
- Fixed a “queue hasn’t run” alert that showed when it shouldn’t.

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
