# Instant Links

With instant links on, a browser fetches a page as soon as a visitor points at its link, so by the time they click, the page is already there. Cached pages cost the server almost nothing to send, so fetching a few ahead is cheap.

Turn it on under **Settings → Plugins → Speedy Cache → General**, or in `config/speedy.php`:

```php
'instantLinks' => true,
```

Cached pages are rebuilt in the background when you turn it on or off in the control panel or apply the change with a deploy. Set in `config/speedy.php`, it applies to pages as they’re rebuilt; use **Rebuild all pages** on the utility page to apply it straight away.

## How it works

Speedy adds the browser’s speculation rules to each cached page. Browsers that support them (Chrome, Edge and other Chromium browsers) fetch a link’s page when the visitor’s pointer rests on it, or their finger touches it. Other browsers ignore the rules and work as before.

- **Only the HTML is fetched**, unless [Opening pages ahead](#opening-pages-ahead) is on. The page’s scripts don’t run until the visitor opens it, so analytics don’t count a visit that didn’t happen.
- **Only when pointed at**, not every link on the page.
- Browsers skip it themselves on data saver and battery saver.
- A visitor with something in their session, such as a flash message or the next step of a form, is never sent a page ahead: the browser’s request is refused (`425`, `X-Speedy: SKIP speculative`), so the message isn’t used up on a page they don’t open. Their click opens the page as usual.

## Opening pages ahead

With **Open pages ahead** on as well, the browser doesn’t just fetch the page: it opens it out of sight, with its images, styles and scripts, so it shows at once on the click. Chrome and Edge keep two at most, and only start once the visitor points at a link.

```php
'instantLinks' => true,
'instantLinksPrerender' => true,
```

A page opened ahead runs its scripts before anyone sees it, so:

- **Speedy’s own scripts wait** until it’s shown: the CSRF token refresh, [dynamic parts](/guides/templates) and Formie 3’s token refresh. A page that’s never opened makes no request to PHP.
- **Mark the site’s scripts that should wait** with `data-speedy-wait`, such as analytics that don’t know about prerendering, chat widgets, or anything that records a visit. They run once the page is shown, in their order:

```html
<script data-speedy-wait src="https://example.com/analytics.js"></script>
<script data-speedy-wait>
  trackPageView();
</script>
```

  Google Analytics 4 already waits by itself. Scripts that aren’t JavaScript, such as `application/ld+json`, are left as they are. Marked scripts aren’t held on pages whose Content-Security-Policy would block the inline script that runs them, so they still run there.
- Animations and carousels that start on load may have moved on by the time the page is shown. Start them on `prerenderingchange`, or mark their script.
- Images and styles of a page opened ahead are downloaded even if the visitor doesn’t click. Leave it off on a site where that matters, such as one with very large pages.

Turning it on or off rebuilds the cached pages, like instant links themselves.

## Links that are never fetched ahead

- Links to other sites, and `#` links on the same page.
- Links with a query string, such as `?view=map`.
- Links with a dot in the path, which are usually files, such as `brochure.pdf`.
- The control panel, action URLs, `index.php`, and signing in, out, setting a password or verifying an email.
- Pages in **Never cache**, sites in **Never cache sites**, and with **Only cache**, any page not in it.
- Links that open a new window (`target="_blank"`), downloads, and `rel="nofollow"` links.
- Links marked `data-no-prefetch`, or inside an element marked with it:

```twig
<a href="/account/delete" data-no-prefetch>Delete account</a>

<nav data-no-prefetch>
  {# None of these links are fetched ahead. #}
</nav>
```

Mark any link that does something when it’s opened, such as a page that adds to a cart or unsubscribes.

## Pages without instant links

- Pages that aren’t cached, including every page for logged-in visitors.
- Pages with rules of their own (`<script type="speculationrules">`).
- Pages whose Content-Security-Policy wouldn’t let the rules run, so the browser doesn’t report a violation: a `script-src` (or `script-src-elem`, or else `default-src`) without `'unsafe-inline'`, or with a nonce, hash or `'strict-dynamic'`, which turn `'unsafe-inline'` off. Add `'inline-speculation-rules'` to that directive to allow them. A policy in a `<meta>` tag always leaves them out, and one set in your web server’s configuration can’t be seen by Speedy: add the keyword there too.
