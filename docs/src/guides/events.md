# Events

For plugins and modules that work with Speedy Cache.

## Before a page is cached

Skip a page, change its HTML or add your own tags:

```php
use suprim\speedy\events\CachePageEvent;
use suprim\speedy\services\Cacher;
use yii\base\Event;

Event::on(Cacher::class, Cacher::EVENT_BEFORE_CACHE, function(CachePageEvent $event) {
    // Don’t cache pages that show a live stock ticker.
    if (str_contains($event->html, 'data-live-ticker')) {
        $event->isValid = false;
        return;
    }

    // Tag pages that show rates, to clear them when rates change.
    if (str_contains($event->url, '/rates')) {
        $event->tags[] = 'my-module:rates';
    }
});
```

| Property | |
|---|---|
| `key` | The page’s key, such as `example.com/news` |
| `url` | The page’s URL |
| `html` | The HTML to save. Change it to save something else |
| `tags` | Craft cache tags the page depends on. Add your own |
| `expires` | When the page expires, as a Unix time, or `null` |
| `isValid` | Set to `false` to skip caching this page (`X-Speedy: SKIP event`) |

Then clear your own tags when your data changes:

```php
use suprim\speedy\Plugin;

Plugin::getInstance()->clearer->clearTags(['my-module:rates'], 'Rates updated');
```

## After pages are cleared

Fired whenever pages are cleared: by content changes, one URL, clearing everything or the API.

```php
use suprim\speedy\events\ClearEvent;
use suprim\speedy\services\Clearer;
use yii\base\Event;

Event::on(Clearer::class, Clearer::EVENT_AFTER_CLEAR, function(ClearEvent $event) {
    // $event->reason, $event->count, $event->urls, $event->all
    if ($event->all) {
        MySearchService::reindex();
    }
});
```

## Add a CDN

Implement `PurgerInterface` (or extend `BasePurger`) and register it:

```php
use craft\events\RegisterComponentTypesEvent;
use suprim\speedy\services\Cdn;
use yii\base\Event;

Event::on(Cdn::class, Cdn::EVENT_REGISTER_PURGERS, function(RegisterComponentTypesEvent $event) {
    $event->types[] = \modules\mycdn\MyCdnPurger::class;
});
```

```php
namespace modules\mycdn;

use suprim\speedy\purgers\BasePurger;

class MyCdnPurger extends BasePurger
{
    public static function handle(): string { return 'mycdn'; }
    public static function displayName(): string { return 'My CDN'; }
    public function isConfigured(): bool { return (bool)getenv('MYCDN_KEY'); }

    public function purgeUrls(array $urls): string
    {
        foreach ($urls as $url) {
            $this->check($this->client()->post('https://api.mycdn.example/purge', ['json' => ['url' => $url]]));
        }
        return $this->cleared(count($urls));
    }

    public function purgeAll(): string { /* … */ return 'Cleared everything'; }
    public function test(): string { /* … */ return 'Works'; }
}
```

Once `isConfigured()` is true, your CDN is cleared with the others, shown on the utility page with a **Test** button, and its failures appear in Recent clears.

## Services

| Service | Useful methods |
|---|---|
| `Plugin::getInstance()->clearer` | `clearTags($tags, $reason)`, `clearUrl($url)`, `clearAll($reason)`, `rebuildAll($reason)` |
| `Plugin::getInstance()->storage` | `exists($key)`, `meta($key)`, `stats()` |
| `Plugin::getInstance()->cdn` | `purgeUrls($urls)`, `purgeAll()`, `configured()` |
