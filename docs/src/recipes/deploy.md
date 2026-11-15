# Clear on Deploy

A deploy can change templates, and templates aren’t content, so Speedy can’t tell which pages they affect. Clear everything after each deploy, after project config is applied:

```bash
php craft up
php craft speedy/clear
```

`speedy/clear` also clears your CDN, and queues a warm job for your `warm` list. To warm during the deploy instead, so the site is fast the moment it goes live:

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
