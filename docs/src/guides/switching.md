# Switching from Another Page Cache

If you’re moving from another full-page cache plugin, Speedy can read its settings and templates and tell you what each becomes. Your content and templates stay as they are.

## 1. See what changes

With the other plugin still installed:

```bash
php craft speedy/migrate
```

It reads the other plugin’s settings, from the control panel and its config file, and prints:

- **Speedy settings** for everything that has a match, such as excluded URIs, query string parameters, Cloudflare and warming;
- **To check**, for anything that works differently, such as regular expressions that need writing as wildcards;
- **Templates**, every line that calls the other plugin’s template variable, with what to use instead.

Environment variables such as `$CLOUDFLARE_TOKEN` are kept as they are. Run it again with `--apply` to save the settings to Speedy.

## 2. Templates

| In your templates | With Speedy |
|---|---|
| Dynamic includes, or fetching a URI into the page | `craft.speedy.dynamic('_cart')` ([Personal content](/guides/templates#personal-content-on-a-cached-page)) |
| Cached includes | A plain `{% include %}`: the page around it is cached already |
| CSRF helpers | `csrfInput()`: Speedy refreshes tokens on cached pages itself ([Forms](/guides/forms)) |
| Turning caching off for a page | `craft.speedy.skip()` |
| A cache duration for a page | `craft.speedy.ttl(600)` |
| Tags for a page | `craft.speedy.tag('x')` |

Dynamic parts are fetched together in one request after the page loads, with no server-side includes to set up.

## 3. Swap the server rules

1. Disable the other plugin in **Settings → Plugins**.
2. Remove its rules from your nginx config or `web/.htaccess`.
3. Add Speedy’s: `php craft speedy/server-rules` ([Server rules](/getting-started/server-rules)).
4. Build the cache: `php craft speedy/warm`.

Check a page with `curl -I`: `X-Speedy: STATIC` means the web server sent it without PHP.

## Settings

| Setting elsewhere | Speedy |
|---|---|
| Included URI patterns | **Only cache**, as wildcards: `news/*`, with `/` for the homepage |
| Excluded URI patterns | `neverCache`, as wildcards: `checkout/*` |
| Query strings cached as unique pages | `cachedParams`, the parameters that make a page of their own |
| Query strings cached as the same page | `ignoredParams` for the parameters that don’t change a page. `queryStrings` set to `'ignore'` caches every query string as the page without it, so `?page=2` and filters would show the first page: only for sites that never use query strings for content |
| Excluded query string parameters | `ignoredParams` |
| Regenerating pages after clearing them | `rebuildCleared` |
| Cache duration | `defaultTtl` |
| Element types that aren’t cached | `ignoredElementTypes` |
| Refreshing when saved unchanged | `ignoreUnchangedSaves`, the other way round |
| Cloudflare | **CDN** tab: an API token and zone ID, and `cloudflareZones` for a zone for each site |
| Generator concurrency and basic auth | `warmConcurrency`, `warmUsername`, `warmPassword` |
| Craft cache storage | **Cache storage**: Craft cache |
| API key | `apiKey` |
| Server-side and edge-side includes | `craft.speedy.dynamic()` |
