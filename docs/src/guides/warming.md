# Warming

A page is built the first time someone visits it after it’s cleared. Warming builds pages ahead of visitors, so nobody waits.

## Changed pages are rebuilt for you

When content changes, Speedy rebuilds the affected pages in a queue job, then swaps them in. Until the new copy is ready, visitors keep getting the old one, so there’s never a slow first visit.

If a rebuild fails (the page now errors or is a 404), the old copy is removed instead.

A page that clears whenever anything of its type changes, such as a listing of every entry, waits a minute before it’s rebuilt. An import or a run of saves then rebuilds it once, not after every entry. Change the wait, in seconds, with `broadRebuildDelay` (`0` rebuilds straight away). Clearing pages by hand, from the utility page, an element index, the command line or the API, rebuilds them at once, and so does an entry going live or a Commerce price starting or ending.

Turn this off with `rebuildCleared`, and changed pages are simply removed:

```php
'rebuildCleared' => false,
```

## New pages are built when they’re saved

When an entry, category or Commerce product is saved and its page isn’t cached, such as a new blog post, Speedy builds the page in the background straight after the save, so the first visitor, often a search engine, gets the cached page. Entries and products that go live at a set time are built when they do.

It’s skipped:

- for drafts, disabled entries and entries that aren’t live yet;
- for pages left out by **Never cache** or **Only cache**;
- for sections and category groups whose template doesn’t exist, as their pages would only be a 404;
- when resaving, such as a section’s entries after changing its URL format;
- when more than 20 are saved in one request or queue job, such as an import. Their pages are built as visitors arrive, or by **Build the pages not cached** under [Coverage](/guides/utility#coverage).

Pages that are already cached aren’t built again here: they’re rebuilt when they’re cleared, as above. Turn it off on the settings page (**Build new pages**) or in `config/speedy.php`:

```php
'buildSavedPages' => false,
```

## After clearing everything

When every page is cleared, from the utility page, the command line or a deploy, Speedy can rebuild your important pages straight away. Choose which in `config/speedy.php`:

```php
// Every page in each site’s sitemap (sitemap indexes are followed, on the sitemap’s own host or your sites’)
'warm' => 'sitemap',
'sitemap' => 'sitemap.xml',

// Or a list of URLs, relative to the primary site or in full
'warm' => ['/', '/shop', '/about', 'https://example.de/'],
```

The homepage is rebuilt first, then the pages the sitemap gives the highest priority, then the shortest paths, such as `/news` before `/news/a-story`. Visitors get the most visited pages fast while the rest are still building. A list in `config/speedy.php` is warmed in its own order.

Then:

- **Clear all pages** queues a warm job;
- **Warm pages** on the utility page warms the list without clearing;
- `php craft speedy/warm` warms the list and shows progress.

## How fast

Pages are built two at a time, so the site stays responsive for visitors. On a bigger server, raise it:

```php
'warmConcurrency' => 4,
```

On a site behind basic authentication, such as staging, give the rebuild requests a username and password:

```php
// config/speedy.php
'warmUsername' => '$STAGING_USER',
'warmPassword' => '$STAGING_PASSWORD',
```

Warming requests carry a one-off `speedy-warm` query string, so a CDN passes them to your server instead of answering with its old copy. They’re signed, so they always build a fresh page, even when the web server serves cached files. Certificate errors are allowed in `devMode`, for local sites with self-signed certificates.
