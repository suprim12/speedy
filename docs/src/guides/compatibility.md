# JavaScript and Other Plugins

A cached page is the HTML Craft built, saved as it was. Anything that runs in the browser runs as usual.

## Alpine, React and Vue

Components start on cached pages the same as on any other: Alpine reads its `x-data` attributes, React and Vue mount into their elements. CSRF tokens in forms are refreshed before the visitor can submit ([Forms](/guides/forms)).

[Dynamic parts](/guides/templates#personal-content-on-a-cached-page) are added to the page after it loads. Alpine starts components in them on its own. For React, Vue or your own scripts, mount them when Speedy says the parts are in place:

```js
document.addEventListener('speedy:dynamic', () => {
  createRoot(document.getElementById('cart')).render(<Cart />);
});
```

Scripts inside a dynamic part run in order once it’s added.

## htmx, Unpoly and fetch

Requests with `HX-Request`, `X-Up-Target` or `X-Requested-With` aren’t cached or served from the cache, as they often ask for part of a page at the same URL. Add `X-Requested-With: XMLHttpRequest` to your own `fetch()` calls if they do the same.

## SEOmatic and other SEO plugins

Meta tags, JSON-LD and tracking scripts are part of the cached page, and the `Link` and `X-Robots-Tag` headers are kept.

- Saving an entry clears its pages as usual, including its SEO fields.
- When SEOmatic’s site-wide settings are saved, such as a new tracking script or new default meta tags, Speedy rebuilds every cached page in the background. It does the same for other SEO plugins that say when their settings change. Visitors keep getting the old copies until the new ones are ready. Otherwise, rebuild them yourself with `php craft speedy/clear --rebuild`.
- Warming from the sitemap follows redirects and sitemap indexes, as SEO plugins usually serve them at `/sitemap.xml`.
- Analytics cookies (`_ga` and others) don’t stop pages being cached.

## Craft Commerce

Product pages are cleared when stock changes, from orders, control panel edits and transfers ([How clearing works](/guides/clearing#commerce-stock)), and when prices change, from pricing rules and from prices that start or end on a date ([Commerce prices](/guides/clearing#commerce-prices)). Visitors with a cart get pages built for them, so their cart is always their own.

## Formie and other form plugins

Formie forms send from cached pages with nothing to set up: their tokens are refreshed as the page loads, and forms with several pages keep each visitor on their own page. See [Forms and CSRF](/guides/forms).

## Feed Me and other imports

An import clears each page once, however many entries it saves, and listings are rebuilt once at the end. Feed Me runs Craft’s clean-up before each feed, which says every element changed; Speedy knows it only removes elements already in the trash, so the cache isn’t cleared.

## Uploads and private files

Only GET requests for HTML pages are cached. Form submissions and uploads (POST), downloads through Craft’s actions, PDFs and other files, and anything a logged-in visitor sees are never cached.
