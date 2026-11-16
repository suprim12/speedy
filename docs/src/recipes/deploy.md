# Clear on Deploy

Speedy can rebuild every cached page in the background on its own when `php craft up` applies project config changes, such as a new field or a changed section. Visitors keep getting the old pages until the new ones are ready. Turn it on in `config/speedy.php`:

```php
'rebuildAfterDeploy' => true,
```

It’s off by default: on a site with thousands of cached pages, rebuilding them all keeps the server busier for a while after each deploy. Pages are rebuilt a few at a time (`warmConcurrency`), so visitors aren’t slowed down.

A deploy can also change templates, and templates aren’t content, so Speedy can’t tell which pages they affect. If a deploy changes templates but not project config, clear everything after it:

```bash
php craft up
php craft speedy/clear
```

`speedy/clear` also clears your CDN, and queues a warm job for your `warm` list.

On a busy site, rebuild instead, so visitors keep getting the old pages until the new ones are ready:

```bash
php craft up
php craft speedy/clear --rebuild
```

Use `speedy/clear` when the old pages mustn’t be shown at all, such as after removing content for legal reasons. To warm during the deploy instead, so the site is fast the moment it goes live:

```bash
php craft up
php craft speedy/clear
php craft speedy/warm
```

## Zero-downtime deploys

With tools that switch a `current` symlink between releases, keep the cache out of the release folder, so each release doesn’t start empty and the web server reads the same files:

```php
// config/speedy.php
return [
    'cachePath' => '@webroot/speedy',      // web/ is usually the release’s own
    'dataPath' => '/var/www/shared/speedy-data',
];
```

and make `web/speedy` a shared folder, linked into each release like `storage`. Then run `speedy/clear` after the switch.

## Only in production

```php
// config/speedy.php
return [
    'enabled' => '$SPEEDY_ENABLED',
];
```

```bash
# .env in production
SPEEDY_ENABLED=true
```
