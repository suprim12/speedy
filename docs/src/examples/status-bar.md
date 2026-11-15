# Cache Status Bar for Development

While building templates, it helps to see whether a page came from the cache. This bar shows when the page was built and what Speedy did with it.

![A dark bar: This page was built at 23:02:19. This URL now: HIT. Served from the cache.](/images/examples/status.jpg)

## The template

Add it to your layout for dev only:

```twig
{# templates/_layout.twig, before </body> #}
{% if craft.app.config.general.devMode %}
  <div class="speedy-bar">
    Built at <strong>{{ now|date('H:i:s') }}</strong>
    · This URL now: <span id="speedy-state">checking…</span>
  </div>
  <script>
    fetch(location.pathname, { method: 'HEAD', cache: 'no-store', credentials: 'same-origin' })
      .then(r => {
        document.getElementById('speedy-state').textContent = r.headers.get('X-Speedy') || 'STATIC';
      });
  </script>
  <style>
    .speedy-bar { position: fixed; bottom: 12px; left: 12px; z-index: 9999;
      padding: 8px 14px; border-radius: 8px; background: #14161f; color: #fff; font: 13px/1.4 system-ui; }
  </style>
{% endif %}
```

## Reading it

| You see | Meaning |
|---|---|
| The build time stays the same when you reload | The page is cached |
| `HIT` | Served from the cache by PHP |
| `STATIC` | Served by the web server, without PHP |
| `MISS` | Built and cached just now |
| `SKIP logged in` | Not cached for you, and why. Use a private window to see what visitors get |

Because `devMode` is part of the cached page, turn caching off on dev (`'enabled' => false`), or clear the cache when you change `devMode`.
