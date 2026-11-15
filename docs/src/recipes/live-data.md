# Pages With Live Data

Speedy clears a page when Craft content on it changes. Data from outside Craft (a feed, an API, stock from another system, the weather) changes without Craft knowing.

## Rebuild on a timer

In the templates that show it:

```twig
{% set rates = craft.app.cache.getOrSet('rates', () => fetchRates(), 300) %}
{% do craft.speedy.ttl(300) %}
```

The page is rebuilt every 5 minutes, and still cleared straight away when its content changes.

For a site where many pages show outside data, give every page a maximum age:

```php
// config/speedy.php
'defaultTtl' => 3600,
```

## Load it after the page

For data that must be live to the second, keep it out of the cached page and load it with JavaScript from an uncached URL. See [Personal content on a cached page](/guides/templates#personal-content-on-a-cached-page).

## Clear after an import

If a script or cron job updates the data, clear the pages afterwards:

```bash
php craft my-module/import-rates
php craft speedy/clear --url=https://example.com/rates
```

Imports into Craft entries, such as from an import plugin, clear pages by themselves.

## Commerce stock

Stock is saved on variants, so a sale that changes stock clears the product’s pages like any other save.
