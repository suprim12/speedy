# Best Practices and Gotchas

What to set up so pages stay fast and correct, and the surprises that catch sites out.

## Best practices

### Setup

- **Add the server rules.** The web server then sends cached pages without starting PHP, in about a millisecond ([Serving without PHP](/getting-started/server-rules)).
- **Run the queue all the time,** with `queue/listen` or your host’s worker, and add the `speedy/expire` cron line ([Cron and queue](/recipes/cron)). Clearing, rebuilding and scheduled content all run in the queue.
- **Turn caching off on your own machine only.** Keep it on for staging, so caching problems show there before they reach production:
  ```php
  'enabled' => App::env('CRAFT_ENVIRONMENT') !== 'dev',
  ```
- **On a staging site behind a password,** set `warmUsername` and `warmPassword`, or pages can’t be rebuilt in the background and are removed instead.
- **With a base URL of `@web`,** list your domains in `allowedHosts`, so Speedy knows which hosts are yours.
- **Behind a CDN,** add its token or keys. Without them, Speedy tells the CDN not to keep pages, as it couldn’t clear them.
- **On several servers,** keep pages in Redis ([Several web servers](/recipes/multi-site#several-web-servers)).
- **With release folders,** share `web/speedy` between releases ([Release folders](/recipes/deploy#release-folders)).

### Deploys

- **Rebuild, don’t clear.** After a deploy that changes templates, run `php craft speedy/clear --rebuild`: visitors keep getting the old pages until the new ones are ready. Clearing everything makes every visitor wait for pages to be built again, and clears your CDN.
- **Rebuild when templates or the front-end build changed.** Cached pages link to the CSS and JavaScript they were built with, whose names change with their contents. PHP and config changes don’t change cached pages ([Clear on deploy](/recipes/deploy)).
- **Keep the previous build’s CSS and JavaScript** until pages are rebuilt, so cached pages never link to files that are gone ([Keep the old CSS and JavaScript](/recipes/deploy#keep-the-old-css-and-javascript)).
- **Leave Speedy out of `clear-caches/all`** in deploy scripts. It includes **Speedy Cache pages**, so it empties the cache. Clear only the Craft caches you need, such as `clear-caches/compiled-templates` and `clear-caches/data`.

### Templates

- **Choose a section in every element query,** such as `craft.entries.section('news')`. A query without one clears its page whenever any entry is saved. **Performance → Cleared by any change** on the utility page lists them, with the template and line.
- **Eager-load in loops,** with `.with()` or `.eagerly()`. **Performance → Repeated queries** shows where a loop runs the same query again and again.
- **Put personal parts in `craft.speedy.dynamic()`,** such as a cart count, a greeting or a form with steps. The rest of the page stays cached ([Templates](/guides/templates#personal-content-on-a-cached-page)).
- **Show cookie-based differences with JavaScript,** such as a consent banner, rather than reading the cookie in Twig. Then every page can be cached.
- **Keep pages lean.** Link to an icon sprite as a file instead of pasting it into each page, use four or five image widths in each `srcset`, and set `generateTransformsBeforePageLoad` to `true`, so cached pages link to finished images.

### Query strings

- **List the parameters that change a page,** such as `page` or `view`, under **Cached parameters**, and ones that don’t, such as tracking codes, under **Ignored parameters**. The **Query strings** tab shows the ones seen keeping pages out of the cache.
- **Don’t set `queryStrings` to `'ignore'`** on a site with pagination, filters or tabs in query strings: every version would get the page without them.
- **Leave search out.** Nearly every search is different, so cached results are rarely used again.

## Gotchas

### Changes that don’t show

- **Templates aren’t content.** Changing a template doesn’t clear any page. Rebuild the pages after the deploy.
- **Data from outside Craft** (an API, a feed, stock from another system) changes without Craft knowing. Give those pages a time limit with `craft.speedy.ttl()`, or a name to clear them by with `craft.speedy.tag()` ([Live data](/recipes/live-data)).
- **Anything worked out when the page is built is kept with it,** such as `now|date`, “open today”, a random testimonial, or `devMode`. It stays the same until the page is rebuilt. Use **Rebuild every day** for dates, and JavaScript for anything that must differ on each visit.
- **A changed named image transform, or a site name shown in templates,** doesn’t clear pages. Click **Rebuild all pages** afterwards.
- **A stopped queue** delays clearing. Speedy notices after ten minutes and clears pages straight away until it runs again, with an alert in the control panel.

### Pages that aren’t cached

- **You’re logged in.** Logged-in visitors always get fresh pages (`SKIP logged in`). Test in a private window.
- **Something sets a cookie on every page,** such as a plugin or a script on the server. Pages that set cookies aren’t cached, so no page is. The `X-Speedy` header says `SKIP sets a cookie`.
- **A template or plugin sends `Cache-Control: private` or `no-store`,** or a `Vary` header other than `Accept-Encoding`.
- **Pages with a query string** are sent by PHP rather than the web server rules, even when cached: only Craft can put the parameters in order and leave out tracking ones.
- **The cache folder is inside a deploy’s release folder.** Each release then starts with nothing cached, and old copies fill the disk ([Release folders](/recipes/deploy#release-folders)).

### Other surprises

- **A deploy that removes the old CSS and JavaScript** leaves cached pages without their styles and scripts until they’re rebuilt, as they link to the old files by name. Keep the previous build’s files ([Keep the old CSS and JavaScript](/recipes/deploy#keep-the-old-css-and-javascript)).
- **Links that change something when opened,** such as “add to cart” or “unsubscribe” links, are fetched ahead with [instant links](/guides/instant-links) when a visitor points at them. Mark them `data-no-prefetch`, or better, make them forms.
- **Cached pages committed to git, or copied with rsync,** overwrite another server’s pages. Run `git rm -r --cached web/speedy` once, and leave `web/speedy/` out of copies.
- **Big pages make a big cache.** Each page is saved with a compressed copy, and each cached parameter value adds another copy of the page, up to `maxQueryPages`. Cap the total with `maxCacheSize`.
