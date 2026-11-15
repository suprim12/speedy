# Troubleshooting

Start with the `X-Speedy` header. Open the page in a private window, or use curl:

```bash
curl -sI https://example.com/news | grep -i x-speedy
```

## A page is never cached

The header says why, after `SKIP`:

| `SKIP …` | Why | What to do |
|---|---|---|
| `logged in` | You’re logged in to Craft | Test in a private window |
| `cart` | The visitor has a Commerce cart | Expected. Set `skipWhenCart` to `false` if your pages show nothing from the cart |
| `query string` | The URL has `?something=` | Expected, as query strings usually change the page. Tracking parameters like `utm_source` are ignored |
| `session` | The visitor has something in their session, such as a form step or a flash message | Expected. It clears once the session is empty. See [What isn’t cached](/guides/not-cached#multi-page-forms-and-flash-messages) |
| `ajax` | An htmx, Unpoly or `X-Requested-With` request | Expected |
| `cookie` | The visitor has a cookie listed in `skipCookies` | Expected |
| `request cookies` | The page wasn’t cached yet and the visitor has cookies of their own, so the shared copy is built in the background instead | Expected. Add cookies that never change pages to `ignoredCookies` ([What isn’t cached](/guides/not-cached#made-up-cookies-can-t-reach-the-shared-copy)) |
| `vary` | The page sends `Vary` with something other than `Accept-Encoding` | Remove the header if the page is the same for everyone |
| `unknown host` | The `Host` header isn’t one of your sites’ domains | Check the site’s base URL |
| `never cache` | The URL matches **Never cache** | Check the patterns in the settings |
| `sets a cookie` | The page sets a cookie, so it may be personal | Find what sets it, or move it to a page that isn’t cached |
| `private` | The template or a plugin sent `Cache-Control: private` or `no-store` | Remove it if the page isn’t personal |
| `template` | The template called `craft.speedy.skip()` | Expected |
| `status 404` | Only 200 pages are cached | Expected |
| `preview` | Live preview or a share link | Expected |
| `disabled` | Caching is off | Check `enabled` in the settings or `config/speedy.php` |

## A change doesn’t show

- **Was it a draft?** Drafts and autosaves never clear pages. Apply or publish the draft.
- **Is the queue running?** Clearing and rebuilding run in Craft’s queue. Check **Utilities → Queue Manager**, and make sure the queue runs on your server ([Cron jobs](/recipes/cron)).
- **Was it rebuilt?** Cleared pages keep their old copy until the new one is built, usually a few seconds. **Utilities → Speedy Cache → Recent clears** shows each clear and its pages.
- **Does the content come from outside Craft?** Speedy can’t see changes in APIs or feeds. Give those pages a time limit ([Live data](/recipes/live-data)).
- **Is it the CDN?** If the page is right on your server but old at the CDN, check the CDN result on the utility page, and use **Test Cloudflare** or **Test CloudFront**.

To clear something straight away, use **Clear URL** or **Clear all pages** on the utility page, or `php craft speedy/clear`.

## Forms say “Bad request” on cached pages

The CSRF token on cached pages is replaced by a fresh one as the page loads. If your site blocks the request to `actions/speedy/csrf`, or a script on the page runs before the token is filled in, submissions fail. Check the browser console for errors, and see [Forms and CSRF](/guides/forms).

## The server check says pages are served by PHP

The rules aren’t being used yet:

- Check you added them to the right site’s config, and reloaded the web server.
- Check `cachePath` is inside the web root (the default, `@webroot/speedy`, is).
- Print the rules again with `php craft speedy/server-rules` if you changed `cachePath`.

## The cache folder is large

Pages are plain HTML, typically 20–100 KB each. **Utilities → Speedy Cache** shows the total. Clear everything any time; pages are rebuilt as they’re visited.

## Logs

- **Recent clears** on the utility page and in `php craft speedy/status`: what was cleared, why, and which pages, plus pages that couldn’t be rebuilt and CDN errors. It keeps the last 1,000 entries.
- **`storage/logs/speedy.log`**: warnings and errors, such as a page that couldn’t be saved, a CDN error or a sitemap that couldn’t be read. With `devMode` on, it also lists each page that couldn’t be rebuilt and why. Craft starts a new file each day and keeps five.

Speedy never logs each visit, so the logs stay small however busy the site is.

## Images load slowly on cached pages

If image transforms are generated on first view, a cached page can keep links that create them. Set `generateTransformsBeforePageLoad` to `true` in `config/general.php`, so cached pages always link to finished images.
