# Events

For plugins and modules that work with Speedy Cache. Each stage has one event, and each can change what happens, not only watch it.

| Stage | Event | Can |
|---|---|---|
| A request arrives | `Cacher::EVENT_IS_CACHEABLE` | Stop it being served from or saved to the cache |
| A page is built | `Cacher::EVENT_BEFORE_CACHE` | Change its HTML, tags or expiry, or not save it |
| A page is saved | `Cacher::EVENT_AFTER_CACHE` | React, such as for metrics |
| Content changes | `Clearer::EVENT_BEFORE_INVALIDATE` | Change which tags are cleared, or clear nothing |
| Pages are cleared | `Clearer::EVENT_BEFORE_CLEAR` | Change which pages, or keep them |
| Pages were cleared | `Clearer::EVENT_AFTER_CLEAR` | React, such as to reindex search |
| Pages are queued to build | `Warmer::EVENT_BEFORE_WARM` | Add or remove pages, or not build them |
| Pages were built | `Warmer::EVENT_AFTER_WARM` | See which were built and which failed |
| The CDN is cleared | `Cdn::EVENT_BEFORE_PURGE` | Add URLs, such as another domain, or not clear it |
| The CDN was cleared | `Cdn::EVENT_AFTER_PURGE` | See each CDN’s answer |
| CDNs are listed | `Cdn::EVENT_REGISTER_PURGERS` | Add your own CDN |

## Before a request is cached

Stop a request being served from the cache or saved to it, before the page is built. For A/B tests or pages that differ by region:

```php
use suprim\speedy\events\CacheableRequestEvent;
use suprim\speedy\services\Cacher;
use yii\base\Event;

Event::on(Cacher::class, Cacher::EVENT_IS_CACHEABLE, function(CacheableRequestEvent $event) {
    // $event->request, $event->key (such as example.com/pricing)
    if ($event->request->getCookies()->has('ab_variant')) {
        $event->isValid = false; // X-Speedy: SKIP event
    }
});
```

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

## After a page is saved

`Cacher::EVENT_AFTER_CACHE` gets the same `CachePageEvent`, once the page is stored. Changing it has no effect.

## When content changes

Craft says which content changed with cache tags. Change them before Speedy clears the pages that use them:

```php
use craft\elements\Entry;
use suprim\speedy\events\InvalidateEvent;
use suprim\speedy\services\Clearer;
use yii\base\Event;

Event::on(Clearer::class, Clearer::EVENT_BEFORE_INVALIDATE, function(InvalidateEvent $event) {
    // Stock levels synced every minute don’t change what pages show.
    if ($event->element instanceof Entry && $event->element->section?->handle === 'stockSync') {
        $event->isValid = false;
        return;
    }
    // A product’s brand page lists it too.
    $event->tags[] = 'my-module:brands';
});
```

To never clear for a whole element type, use the `ignoredElementTypes` setting instead, such as `[craft\elements\User::class]`.

## Before pages are cleared

```php
use suprim\speedy\events\BeforeClearEvent;

Event::on(Clearer::class, Clearer::EVENT_BEFORE_CLEAR, function(BeforeClearEvent $event) {
    // $event->reason, $event->keys (such as example.com/news), $event->all
    $event->keys = array_filter($event->keys, fn($key) => !str_contains($key, '/archive/'));
});
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

## Warming

```php
use suprim\speedy\events\WarmEvent;
use suprim\speedy\services\Warmer;

Event::on(Warmer::class, Warmer::EVENT_BEFORE_WARM, function(WarmEvent $event) {
    // $event->urls, $event->rebuild
    $event->urls[] = 'https://example.com/landing/summer';
});

Event::on(Warmer::class, Warmer::EVENT_AFTER_WARM, function(WarmEvent $event) {
    // $event->built, $event->failed
    if ($event->failed) {
        Craft::warning('Pages that wouldn’t build: ' . implode(', ', $event->failed));
    }
});
```

## Clearing the CDN

```php
use suprim\speedy\events\PurgeEvent;
use suprim\speedy\services\Cdn;

Event::on(Cdn::class, Cdn::EVENT_BEFORE_PURGE, function(PurgeEvent $event) {
    // The same pages are also served from another domain.
    foreach ($event->urls as $url) {
        $event->urls[] = str_replace('https://example.com', 'https://example.co.uk', $url);
    }
});

Event::on(Cdn::class, Cdn::EVENT_AFTER_PURGE, function(PurgeEvent $event) {
    // $event->results, such as ['cloudflare' => 'cleared 3 URLs']
});
```

`$event->all` is true when everything is being cleared, and `urls` is empty then.

## Services

| Service | Useful methods |
|---|---|
| `Plugin::getInstance()->clearer` | `clearTags($tags, $reason)`, `clearCustomTags($names, $reason)`, `clearUrl($url)`, `clearAll($reason)`, `rebuildAll($reason)` |
| `Plugin::getInstance()->warmer` | `queue($urls, $rebuild)`, `warmList()` |
| `Plugin::getInstance()->storage` | `exists($key)`, `meta($key)`, `stats()` |
| `Plugin::getInstance()->cdn` | `purgeUrls($urls)`, `purgeAll()`, `configured()` |
