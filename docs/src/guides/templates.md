# In Templates

Most sites need no template changes. Two tags are there for the exceptions.

## Don’t cache a page

```twig
{% do craft.speedy.skip() %}
```

Anywhere in the template, including an include or a macro. The page is built as usual for each visitor, with `X-Speedy: SKIP template`.

For example, a search page that shows results without a query string:

```twig
{% if craft.app.request.getSegment(1) == 'search' %}
  {% do craft.speedy.skip() %}
{% endif %}
```

For whole sections of the site, **Never cache** in the settings is simpler. See [What isn’t cached](/guides/not-cached).

## Rebuild a page after a while

```twig
{% do craft.speedy.ttl(600) %}
```

The page is cleared and rebuilt after 600 seconds. Use it for pages showing data from outside Craft. If it’s called more than once, the shortest time wins. See [Pages with live data](/recipes/live-data).

## Personal content on a cached page

A cached page is the same for everyone, so a cart count, a “Hi, Alex” or a form can’t be in it. Put those parts in their own template and include them with `craft.speedy.dynamic()`:

```twig
<header>
  {{ craft.speedy.dynamic('_partials/cart-count') }}
</header>
```

```twig
{# templates/_partials/cart-count.twig #}
<a href="/cart">Cart ({{ craft.commerce.carts.cart.totalQty }})</a>
```

The page is cached as usual. Where the include goes, the cached copy has an empty placeholder, and a small script fetches every part on the page in one request and puts it in place, running any scripts the part needs.

- **Variables:** `craft.speedy.dynamic('_partials/greeting', { size: 'small' })`. Use plain values; they’re visible in the page source, so never secrets.
- **Placeholder:** `craft.speedy.dynamic('_partials/cart-count', {}, { placeholder: 'Cart' })` shows something until the part arrives.
- **Pages that aren’t cached,** such as for logged-in visitors, render the part in place, with no extra request.
- **Only templates you include can be fetched.** Each placeholder carries a signed token, so visitors can’t ask for any other template.
- Elements a part shows don’t clear the page it’s on, since the part is always built fresh.

## Is this page cached?

Look at the `X-Speedy` response header in your browser’s dev tools:

| Value | Meaning |
|---|---|
| `HIT` | Served from the cache by PHP |
| `MISS` | Built now, and cached |
| `WARM` | Built by Speedy’s warming |
| `SKIP <reason>` | Not cached, and why |
| `STATIC` | Served by the web server, without PHP |

Cached copies also end with a comment saying when they were cached, which you can see with **View Source** even when a CDN removes the header:

```html
<!-- Cached by Speedy Cache on 2026-11-15T16:02:20+11:00 -->
```

Turn it off with `cacheComment` set to `false`.
