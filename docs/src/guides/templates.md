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

A cached page is the same for everyone, so a greeting or cart count can’t be in it. Load those after the page, from a template that isn’t cached:

```twig
{# templates/_header.twig #}
<span id="cart-count"></span>
<script>
  fetch('/cart-count').then(r => r.text()).then(n => {
    document.getElementById('cart-count').textContent = n;
  });
</script>
```

```twig
{# templates/cart-count.twig, with 'cart-count' in Never cache #}
{{ craft.commerce.carts.cart.totalQty }}
```

## Is this page cached?

Look at the `X-Speedy` response header in your browser’s dev tools:

| Value | Meaning |
|---|---|
| `HIT` | Served from the cache by PHP |
| `MISS` | Built now, and cached |
| `WARM` | Built by Speedy’s warming |
| `SKIP <reason>` | Not cached, and why |
| `STATIC` | Served by the web server, without PHP |
