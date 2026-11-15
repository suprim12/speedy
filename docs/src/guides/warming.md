# Warming

A page is built the first time someone visits it after it’s cleared. Warming builds pages ahead of visitors, so nobody waits.

## Changed pages are rebuilt for you

When content changes, Speedy rebuilds the affected pages in a queue job, then swaps them in. Until the new copy is ready, visitors keep getting the old one, so there’s never a slow first visit.

If a rebuild fails (the page now errors or is a 404), the old copy is removed instead.

Turn this off with `rebuildCleared`, and changed pages are simply removed:

```php
'rebuildCleared' => false,
```

## After clearing everything

When every page is cleared, from the utility page, the command line or a deploy, Speedy can rebuild your important pages straight away. Choose which in `config/speedy.php`:

```php
// Every page in each site’s sitemap (sitemap indexes are followed)
'warm' => 'sitemap',
'sitemap' => 'sitemap.xml',

// Or a list of URLs, relative to the primary site or in full
'warm' => ['/', '/shop', '/about', 'https://example.de/'],
```

Then:

- **Clear all pages** queues a warm job;
- **Warm pages** on the utility page warms the list without clearing;
- `php craft speedy/warm` warms the list and shows progress.

## How fast

Pages are built two at a time, so the site stays responsive for visitors. On a bigger server, raise it:

```php
'warmConcurrency' => 4,
```

Warming requests are signed, so they always build a fresh page, even when the web server serves cached files. Certificate errors are allowed in `devMode`, for local sites with self-signed certificates.
