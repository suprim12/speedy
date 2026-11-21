# What Isn’t Cached

Speedy only caches pages it can be sure are the same for everyone.

| Not cached | Why |
|---|---|
| Visitors who are logged in | They may see personal content, and editors need to see changes straight away |
| Visitors with a Commerce cart | Headers often show the cart. Set `skipWhenCart` to `false` if yours don’t |
| Visitors with something in their session | Such as the second step of a multi-page form, or a “Thanks for your message” flash. See below |
| Ajax requests | Libraries like htmx and Unpoly, and `X-Requested-With` requests, often get part of a page at the same URL |
| Cookies you list in `skipCookies` | Such as a consent banner rendered on the server. See below |
| Pages that send `Vary` | `Vary: User-Agent` or `Vary: Cookie` means the page differs per visitor. `Vary: Accept-Encoding` is fine |
| Hosts that aren’t a site’s | A request with someone else’s domain in its `Host` header. Sites whose base URL is `@web` accept any host |
| URLs with a query string | `?q=`, `?page=` and filters usually change the page. Tracking parameters (`utm_*`, `gclid`, `fbclid`…) are ignored, and the ones you list in `cachedParams` are cached ([Pagination and filters](#pagination-and-filters)) |
| URLs matching **Never cache** | Such as `checkout/*` or `account*` |
| URLs not matching **Only cache** | When **Only cache** lists any pages |
| Entries of an entry type in **Never cache entry types** | Such as articles only for members |
| Categories in **Never cache categories** | Their pages, and the pages of entries and products that have them |
| Any page while the disk is nearly full | Under 256 MB free on the cache folder’s disk. Cached pages are still sent ([Troubleshooting](/getting-started/troubleshooting#the-disk-is-nearly-full)) |
| Pages that set a cookie | A cookie usually means something personal. Craft’s CSRF and session cookies are fine |
| Pages marked private | `Cache-Control: private` or `no-store`, from your template or a plugin |
| Responses that aren’t a 200 HTML page | Redirects, JSON and feeds, and 404s unless **Pages that don’t exist** keeps them for a while |
| POST requests and actions | Form submissions and `actions/…` URLs |
| Live preview and share links | They show drafts |
| Pages that call `craft.speedy.skip()` | See [Templates](/guides/templates) |

The `X-Speedy` header on each response says `SKIP` and the reason.

## Never cache

Add URL paths on the settings page, one per line, or in `config/speedy.php`. `*` matches anything:

```php
'neverCache' => [
    'checkout/*',
    'account*',
    'search',
    'members/*',
],
```

Pages already cached that a new pattern leaves out, or that a new **Only cache** list no longer includes, are removed when the settings are saved, a deploy applies them, or someone next opens the control panel after `config/speedy.php` changes. Otherwise the web server would keep sending them.

### By entry type or category

When the pages to leave out don’t share a path, choose them by what they are, on the General tab or in `config/speedy.php`:

```php
// The pages of these entry types’ entries, by handle.
'neverCacheEntryTypes' => ['memberArticle'],
// These categories’ pages, and the pages of entries, products and other elements that have them, by group and slug.
'neverCacheCategories' => ['topics/members'],
```

Categories are kept by group handle and slug, so the setting works on every environment. Listings that show such entries are still cached, so keep anything private off them.

Pages already cached are removed when an entry type or category is added, after the settings are saved or a deploy applies them, so the web server stops sending them. `X-Speedy` says `SKIP never cache entry type` or `SKIP never cache category`, and the entry’s **Page cache** status says why.

### By site

On a Craft install with several sites, leave a whole site out, such as a members’ area, under **Never cache sites** on the General tab, or by handle in `config/speedy.php`:

```php
'neverCacheSites' => ['members'],
```

Its pages are built for every visit, its cached pages are removed when it’s added, and instant links on the other sites don’t fetch its pages. `X-Speedy` says `SKIP never cache site`.

## Caching only some pages

To cache a few sections and leave the rest alone, list them in **Only cache** on the settings page, or in `config/speedy.php`. `/` is the homepage:

```php
'onlyCache' => [
    '/',
    'news*',
    'about',
],
```

Pages that don’t match aren’t cached, and **Never cache** still applies to the ones that do. Leave it empty to cache every page.

On multi-site, **Never cache** and **Only cache** patterns match the path within each site too: for a site at `/de/`, `/` is its homepage and `news*` matches `/de/news/…`. To match one site only, include its folder, such as `de/news*`.

## Multi-page forms and flash messages

Some pages depend on what a visitor did a moment ago: a multi-page form that reloads the page for each step remembers the step in the visitor’s session, and a “Thanks for your message” notice is a flash message kept there too. Cached, one visitor’s step or message would be shown to everyone, and other visitors would be stuck on the cached step.

So while a visitor’s session holds anything, Speedy:

- doesn’t cache the pages it builds for them (`X-Speedy: SKIP session`);
- sets a `speedy_state` cookie, so it and the web server rules build their pages fresh instead of serving cached copies;
- removes the cookie once the session is empty again, such as when the form is finished.

Everyone else keeps getting cached pages. Forms that move between steps with Ajax don’t use the session this way and need nothing.

## Cookies that change pages

If a template reads a cookie, such as a consent banner that’s only shown until accepted, a cached copy would show one visitor’s choice to everyone. List those cookies, and visitors who have them get pages built for them:

```php
// config/speedy.php
'skipCookies' => ['cookie_consent', 'currency'],
```

Names are matched by part, and the [server rules](/getting-started/server-rules) include them, so copy the rules again after changing this. Better still, render the banner for everyone and hide it with JavaScript when the cookie is set; then every page can be cached.

### Made-up cookies can’t reach the shared copy

Even without `skipCookies`, a page is only ever saved from a request without cookies of its own, so nobody can send a made-up cookie to get their version cached for everyone. A visitor with other cookies is still sent the cached page; if there isn’t one yet, they get a page built for them (`X-Speedy: SKIP request cookies`) and the shared copy is built in the background.

Craft’s session and CSRF cookies, and analytics and bot-protection cookies such as `_ga`, `_fbp`, `_hj` and `__cf_bm`, are ignored, as they’re only read in the browser. Add others that never change a page, matched by the start of the name:

```php
// config/speedy.php
'ignoredCookies' => ['_my_analytics'],
```

## Pages that differ by device or location

Pages that change with the browser (`craft.app.request.isMobileBrowser`) or the visitor’s location can’t be told apart by URL. Use CSS or JavaScript for the differences, or add `{% do craft.speedy.skip() %}` to those templates.

## Response headers

Headers your templates or plugins send, such as `X-Robots-Tag`, `Link`, `Content-Language` and `Content-Security-Policy`, are saved with the page and sent again when PHP serves it. Pages served by the web server get the headers in your server configuration instead.

## Pagination and filters

Craft paginates with paths like `/news/p2`, which are cached like any page. If your site uses query strings, such as `?page=2`, `?category=shoes` or `?view=map` for a tab, list the parameters that make a page of their own under **Settings → Query strings → Cached parameters**, or in the config file:

```php
// config/speedy.php
'cachedParams' => ['page', 'category'],
```

If Craft paginates with a query string (`pageTrigger` set to `'?page='` in `config/general.php`), that parameter is cached without listing it.

Each combination is cached separately: `/news?page=2` and `/news?page=2&category=shoes` are two pages, whatever order the parameters come in. Tracking parameters are still ignored. Only the parameters you list are cached, with one short value each, and at most `maxQueryPages` versions of a page (100), so made-up query strings can’t fill the disk. Any other query string is passed to Craft as usual.

These pages are sent from the cache by Craft, in a few milliseconds, rather than by the [server rules](/getting-started/server-rules): only Craft can put the parameters in order and leave out tracking ones, so `?a=1&b=2` and `?b=2&a=1` share one copy.

Search is best left out: nearly every search is different, so cached results are rarely used again.

### Which parameters to list

Speedy notes the query parameters that keep pages out of the cache. When one is used often, such as `view` in `/homes?view=map`, the utility page says so with an example page, and the **Query strings** settings tab lists it with buttons to cache or ignore it:

- If the parameter changes what the page shows, add it to **Cached parameters**.
- If it doesn’t, such as a parameter only scripts read, add it to **Ignored parameters**, and the page is cached without it.

Pages with a query string are sent by PHP rather than the web server rules, which is still much faster than building them.
