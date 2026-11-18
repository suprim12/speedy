# Installation

Speedy Cache requires Craft CMS 5.6 or later and PHP 8.2 or later.

## From the Plugin Store

In the control panel, go to **Plugin Store**, search for **Speedy Cache** and click **Install**.

## With Composer

```bash
composer require suprim/speedy
php craft plugin/install speedy
```

## Check it’s working

Pages are cached from the next visit by someone who isn’t logged in. Open a page in a private window, or use curl, and look at the `X-Speedy` header:

```bash
curl -sI https://example.com/ | grep -i x-speedy
# x-speedy: MISS   ← built by Craft and saved
curl -sI https://example.com/ | grep -i x-speedy
# x-speedy: HIT    ← sent from Speedy’s copy
```

| `X-Speedy` | Meaning |
|---|---|
| `MISS` | Craft built the page, and Speedy saved it |
| `HIT` | Sent from Speedy’s copy, by PHP |
| `STATIC` | Sent from Speedy’s copy by the web server, without PHP ([server rules](/getting-started/server-rules)) |
| `WARM` | Rebuilt by Speedy in the background |
| `SKIP …` | Not cached, and why, such as `SKIP logged in` ([What isn’t cached](/guides/not-cached)) |

Logged in to the control panel? You’ll always see `SKIP logged in`, as logged-in visitors are never cached.

## Make it faster

1. **[Serve pages without PHP.](/getting-started/server-rules)** Add the rules for Nginx or Apache. This is the biggest speed-up.
2. **[Connect your CDN](/guides/cloudflare),** if your site is behind Cloudflare or CloudFront.
3. **Set up a [cron job](/recipes/cron)** for scheduled content, so posts set to go live at 9am appear at 9am.

## Turn it off on dev

Caching usually gets in the way while building templates. Turn it off on your own machine in `config/speedy.php`, and keep it on for staging, so caching problems show there before they reach production:

```php
<?php

use craft\helpers\App;

return [
    'enabled' => App::env('CRAFT_ENVIRONMENT') !== 'dev',
];
```
