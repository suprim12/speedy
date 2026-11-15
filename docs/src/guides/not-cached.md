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
| URLs with a query string | `?q=`, `?page=` and filters usually change the page. Tracking parameters (`utm_*`, `gclid`, `fbclid`…) are ignored |
| URLs matching **Never cache** | Such as `checkout/*` or `account*` |
| Pages that set a cookie | A cookie usually means something personal. Craft’s CSRF and session cookies are fine |
| Pages marked private | `Cache-Control: private` or `no-store`, from your template or a plugin |
| Responses that aren’t a 200 HTML page | 404s, redirects, JSON and feeds |
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

## Pagination

Craft paginates with paths like `/news/p2`, which are cached like any page. If your site paginates with `?page=2`, those pages aren’t cached by default.
