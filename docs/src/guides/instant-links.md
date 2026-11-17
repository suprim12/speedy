# Instant Links

With instant links on, a browser fetches a page as soon as a visitor points at its link, so by the time they click, the page is already there. Cached pages cost the server almost nothing to send, so fetching a few ahead is cheap.

Turn it on under **Settings → Plugins → Speedy Cache → General**, or in `config/speedy.php`:

```php
'instantLinks' => true,
```

Cached pages are rebuilt in the background when you turn it on or off in the control panel or apply the change with a deploy. Set in `config/speedy.php`, it applies to pages as they’re rebuilt; use **Rebuild all** on the utility page to apply it straight away.

## How it works

Speedy adds the browser’s speculation rules to each cached page. Browsers that support them (Chrome, Edge and other Chromium browsers) fetch a link’s page when the visitor’s pointer rests on it, or their finger touches it. Other browsers ignore the rules and work as before.

- **Only the HTML is fetched.** The page’s scripts don’t run until the visitor opens it, so analytics don’t count a visit that didn’t happen.
- **Only when pointed at**, not every link on the page.
- Browsers skip it themselves on data saver and battery saver.

## Links that are never fetched ahead

- Links to other sites, and `#` links on the same page.
- Links with a query string, such as `?view=map`.
- Links to files, such as `brochure.pdf`.
- The control panel, action URLs, `index.php`, and signing in, out, setting a password or verifying an email.
- Pages in **Never cache**, and with **Only cache**, any page not in it.
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
- Pages whose Content-Security-Policy wouldn’t let the rules run, so the browser doesn’t report a violation. Add `'inline-speculation-rules'` to `script-src` to allow them. A policy set in your web server’s configuration can’t be seen by Speedy: add the keyword there too.
