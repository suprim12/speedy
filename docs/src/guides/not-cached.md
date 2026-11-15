# What Isn’t Cached

Speedy only caches pages it can be sure are the same for everyone.

| Not cached | Why |
|---|---|
| Visitors who are logged in | They may see personal content, and editors need to see changes straight away |
| Visitors with a Commerce cart | Headers often show the cart. Set `skipWhenCart` to `false` if yours don’t |
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

## Pagination

Craft paginates with paths like `/news/p2`, which are cached like any page. If your site paginates with `?page=2`, those pages aren’t cached by default.
