# Switching from Blitz

Speedy can read Blitz’s settings and templates and tell you what each becomes. Your content and templates stay as they are.

## 1. See what changes

With Blitz still installed:

```bash
php craft speedy/import-blitz
```

It reads Blitz’s settings, from the control panel and `config/blitz.php`, and prints:

- **Speedy settings** for everything that has a match, such as excluded URIs, query string parameters, Cloudflare and warming;
- **To check**, for anything that works differently, such as regular expressions that need writing as wildcards;
- **Templates**, every line that calls `craft.blitz`, with what to use instead.

Environment variables such as `$CLOUDFLARE_TOKEN` are kept as they are. Run it again with `--apply` to save the settings to Speedy.

## 2. Templates

| Blitz | Speedy |
|---|---|
| `craft.blitz.includeDynamic('_cart')` | `craft.speedy.dynamic('_cart')` |
| `craft.blitz.fetchUri(...)` | `craft.speedy.dynamic()` with a template that renders the same content |
| `craft.blitz.includeCached(...)` | A plain `{% include %}`: the page around it is cached already |
| `craft.blitz.csrfInput()` | `csrfInput()`: Speedy refreshes tokens on cached pages itself ([Forms](/guides/forms)) |
| `craft.blitz.options({ cachingEnabled: false })` | `craft.speedy.skip()` |
| `craft.blitz.options({ cacheDuration: 600 })` | `craft.speedy.ttl(600)` |
| `craft.blitz.options({ tags: ['x'] })` | `craft.speedy.tag('x')` |

Dynamic parts are fetched together in one request after the page loads, with no server-side includes to set up.

## 3. Swap the server rules

1. Disable Blitz in **Settings → Plugins**.
2. Remove Blitz’s rules from your nginx config or `web/.htaccess`.
3. Add Speedy’s: `php craft speedy/server-rules` ([Server rules](/getting-started/server-rules)).
4. Build the cache: `php craft speedy/warm`.

Check a page with `curl -I`: `X-Speedy: STATIC` means the web server sent it without PHP.

## Settings

| Blitz | Speedy |
|---|---|
| Included URI patterns | Speedy caches every page except **Never cache** |
| Excluded URI patterns | `neverCache`, as wildcards: `checkout/*` |
| Query string caching: unique pages | `cachedParams`, the parameters that make a page of their own |
| Query string caching: same page | `queryStrings` set to `'ignore'` |
| Excluded query string parameters | `ignoredParams` |
| Refresh mode (clear and generate) | `rebuildCleared` |
| Cache duration | `defaultTtl` |
| Non-cacheable element types | `ignoredElementTypes` |
| Refresh when saved unchanged | `ignoreUnchangedSaves`, the other way round |
| Cloudflare purger | **CDN** tab: an API token and zone ID |
| HTTP generator concurrency and basic auth | `warmConcurrency`, `warmUsername`, `warmPassword` |
| Yii cache storage | **Cache storage**: Craft cache |
| API key | `apiKey` |
| Server-side and edge-side includes | `craft.speedy.dynamic()` |
