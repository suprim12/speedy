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

## SEOmatic

Meta tags, JSON-LD and tracking scripts are part of the cached page, and the `Link` and `X-Robots-Tag` headers are kept.

- Saving an entry clears its pages as usual, including its SEO fields.
- Saving SEOmatic’s settings, such as adding a tracking script or changing the site’s default meta tags, rebuilds every cached page in the background. Visitors keep getting the old copies until the new ones are ready.
- Warming from the sitemap reads SEOmatic’s sitemap index at `/sitemap.xml`.
- Its analytics cookies (`_ga` and others) don’t stop pages being cached.

## Formie and other forms

See [Forms and CSRF](/guides/forms).

## Uploads and private files

Only GET requests for HTML pages are cached. Form submissions and uploads (POST), downloads through Craft’s actions, PDFs and other files, and anything a logged-in visitor sees are never cached.
