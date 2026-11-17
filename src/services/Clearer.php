<?php
/**
 * Speedy Cache plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\speedy\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\events\InvalidateElementCachesEvent;
use craft\helpers\ElementHelper;
use craft\helpers\Json;
use craft\helpers\Queue;
use suprim\speedy\events\BeforeClearEvent;
use suprim\speedy\events\ClearEvent;
use suprim\speedy\events\InvalidateEvent;
use suprim\speedy\jobs\ClearJob;
use suprim\speedy\jobs\ExpireJob;
use suprim\speedy\Plugin;

/**
 * Clears pages when the content they show changes, using the cache tags Craft fires.
 */
class Clearer extends Component
{
    /** After pages are cleared, by content changes, a URL, clearing everything or the API. */
    public const EVENT_AFTER_CLEAR = 'afterClear';

    /** When Craft says content changed: change the tags whose pages are cleared, or clear nothing. */
    public const EVENT_BEFORE_INVALIDATE = 'beforeInvalidate';

    /** Before pages are cleared: change which, or keep them. */
    public const EVENT_BEFORE_CLEAR = 'beforeClear';

    /** Whether pages cleared now are rebuilt, overriding the setting, such as while clearing without the queue. */
    private ?bool $_rebuild = null;

    /** @var array<string, true> Tags waiting to be cleared at the end of the request or bulk operation. */
    private array $_pending = [];

    /** @var string[] What changed, for the log (the first few). */
    private array $_reasons = [];

    /** @var array<string, true> The elements that changed, once each (an entry saves once per site), for “and 12 more”. */
    private array $_changed = [];

    private bool $_clearAll = false;

    /**
     * @var array<int, array{title: ?string, fields: array<string, mixed>}|null> Elements being saved right now, by
     * object, with their stored title and field values, so a save that changes nothing can be told apart.
     */
    private array $_saving = [];

    /** Why every page should be rebuilt at the end of the request, such as SEOmatic’s settings changing. */
    private ?string $_rebuildAll = null;
    private int $_bulkDepth = 0;

    /**
     * Collects the tags from Craft’s invalidation event, without clearing yet.
     */
    public function onInvalidate(InvalidateElementCachesEvent $event): void
    {
        // Drafts, autosaves and revisions never appear on the live site.
        if ($event->element instanceof ElementInterface && ElementHelper::isDraftOrRevision($event->element)) {
            return;
        }
        if ($event->element instanceof ElementInterface && array_key_exists(spl_object_id($event->element), $this->_saving) && $this->isUnchanged($event->element)) {
            return;
        }
        if ($event->tags === ['element'] && $this->fromGarbageCollection()) {
            return;
        }
        $tags = $event->tags;
        // Drafts and revisions never appear on the live site.
        $tags = array_filter($tags, fn($t) => !str_ends_with($t, '::drafts') && !str_ends_with($t, '::revisions'));
        $ignored = Plugin::getInstance()->getSettings()->ignoredElementTypes;
        if ($ignored) {
            foreach ($ignored as $class) {
                if ($event->element instanceof $class) {
                    return;
                }
            }
            $tags = array_filter($tags, function($tag) use ($ignored) {
                foreach ($ignored as $class) {
                    if ($tag === "element::$class" || str_starts_with($tag, "element::$class::")) {
                        return false;
                    }
                }
                return true;
            });
        }
        if ($tags && $this->hasEventHandlers(self::EVENT_BEFORE_INVALIDATE)) {
            $before = new InvalidateEvent(['element' => $event->element instanceof ElementInterface ? $event->element : null, 'tags' => array_values($tags)]);
            $this->trigger(self::EVENT_BEFORE_INVALIDATE, $before);
            $tags = $before->isValid ? array_filter($before->tags, 'is_string') : [];
        }
        if (!$tags) {
            return;
        }
        if (in_array('element', $tags, true)) {
            $this->_clearAll = true;
        }
        foreach ($tags as $tag) {
            $this->_pending[$tag] = true;
        }
        // Saved with a future post date: check again when it goes live, to clear the pages that will list it.
        $postDate = $event->element instanceof ElementInterface && property_exists($event->element, 'postDate') ? $event->element->postDate : null;
        if ($postDate instanceof \DateTimeInterface && $postDate->getTimestamp() > time()) {
            $this->scheduleExpiry($postDate->getTimestamp());
        }
        $id = $event->element instanceof ElementInterface ? (string)($event->element->getCanonicalId() ?? $event->element->id) : implode(',', $tags);
        if (isset($this->_changed[$id])) {
            return;
        }
        $this->_changed[$id] = true;
        if (count($this->_reasons) < 3) {
            $this->_reasons[] = $event->element instanceof ElementInterface
                ? $this->describe($event->element)
                : $this->describeTags($tags);
        }
    }

    /**
     * Whether Craft’s garbage collection is announcing that every element changed, as it does each time it runs (and
     * Feed Me runs it before each import). It only hard-deletes elements that were already in the trash, and putting
     * them there cleared their pages, so nothing a visitor sees has changed.
     */
    private function fromGarbageCollection(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15) as $frame) {
            if (is_a((string)($frame['class'] ?? ''), \craft\services\Gc::class, true) && $frame['function'] === 'run') {
                return true;
            }
        }

        return false;
    }

    /**
     * Rebuilds every page in the background at the end of the request, once, however often it’s asked for.
     */
    public function rebuildAllLater(string $reason): void
    {
        $this->_rebuildAll = $reason;
    }

    /**
     * Called before an element is saved, so its cache tags can be ignored if the save turns out to change nothing.
     */
    public function beforeSave(ElementInterface $element): void
    {
        $stored = null;
        if (Plugin::getInstance()->getSettings()->ignoreUnchangedSaves && \craft\helpers\ElementHelper::shouldTrackChanges($element) && !ElementHelper::isDraftOrRevision($element)) {
            // The control panel sends every field, so Craft marks them all as changed; compare with what’s stored.
            $original = $element::find()->id($element->id)->siteId($element->siteId)->status(null)->one();
            $stored = $original ? ['title' => $original->title, 'fields' => $original->getSerializedFieldValues()] : null;
        }
        $this->_saving[spl_object_id($element)] = $stored;
    }

    public function afterSave(ElementInterface $element): void
    {
        unset($this->_saving[spl_object_id($element)]);
    }

    /**
     * Whether a save changed nothing a visitor could see: no attribute or field changed, or the element was disabled
     * and still is. Only for element types whose changes Craft records (entries, categories, products, users…);
     * anything else always clears its pages.
     */
    private function isUnchanged(ElementInterface $element): bool
    {
        if (!Plugin::getInstance()->getSettings()->ignoreUnchangedSaves || !$element::trackChanges()) {
            return false;
        }
        if ($element->firstSave || $element->isNewForSite || $element->dateDeleted !== null || $element->duplicateOf !== null || $element->propagateAll) {
            return false;
        }
        $stored = $this->_saving[spl_object_id($element)] ?? null;
        $attributes = $element->getDirtyAttributes();
        // Older Craft 5 versions count the title as changed on every save (an empty title is stored as null), so
        // compare it with what’s stored.
        if ($stored !== null && (string)$element->title === (string)$stored['title']) {
            $attributes = array_values(array_diff($attributes, ['title']));
        }
        $statusChanged = (bool)array_intersect($attributes, ['enabled', 'enabledForSite', 'status', 'postDate', 'expiryDate']);
        if (!$statusChanged && $element->getStatus() === \craft\base\Element::STATUS_DISABLED && !$this->enabledElsewhere($element)) {
            // Disabled before and after, in every site: it isn’t on any page.
            return true;
        }

        if ($attributes) {
            return false;
        }
        $dirty = $element->getDirtyFields();
        if (!$dirty) {
            return true;
        }
        if ($stored === null) {
            return false;
        }
        foreach ($element->getSerializedFieldValues($dirty) as $handle => $value) {
            if (!array_key_exists($handle, $stored['fields']) || Json::encode($stored['fields'][$handle]) !== Json::encode($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the element is enabled in another site, where its pages can still show what was just changed.
     */
    private function enabledElsewhere(ElementInterface $element): bool
    {
        if (!$element->enabled || !Craft::$app->getIsMultiSite()) {
            return false;
        }

        return (new \craft\db\Query())
            ->from(\craft\db\Table::ELEMENTS_SITES)
            ->where(['elementId' => $element->id, 'enabled' => true])
            ->andWhere(['not', ['siteId' => $element->siteId]])
            ->exists();
    }

    /**
     * Clears pages tagged in templates with `craft.speedy.tag()`.
     *
     * @param string[] $names
     */
    public function clearCustomTags(array $names, string $reason): int
    {
        $tags = array_values(array_filter(array_map(fn($name) => \suprim\speedy\services\Cacher::customTag((string)$name), $names)));

        return $tags ? $this->clearTags($tags, $reason) : 0;
    }

    /**
     * After stock changes, from an order, a control panel edit or a transfer: clears the pages that show the
     * product or variant, as Commerce doesn’t tell Craft its caches changed.
     */
    public function stockChanged(?ElementInterface $purchasable): void
    {
        if ($purchasable === null) {
            return;
        }
        $id = (string)$purchasable->id;
        if (!isset($this->_changed[$id]) && count($this->_reasons) < 3) {
            $this->_reasons[] = Craft::t('speedy', 'Stock changed for “{title}”', ['title' => mb_strimwidth((string)$purchasable, 0, 60, '…')]);
            $this->_changed[$id] = true;
        }
        Craft::$app->getElements()->invalidateCachesForElement($purchasable);
        $owner = method_exists($purchasable, 'getOwner') ? $purchasable->getOwner() : null;
        if ($owner instanceof ElementInterface) {
            $this->_pending["element::$owner->id"] = true;
        }
    }

    public function beginBulk(): void
    {
        $this->_bulkDepth++;
    }

    public function endBulk(): void
    {
        $this->_bulkDepth = max(0, $this->_bulkDepth - 1);
        $this->flush();
    }

    /**
     * Pushes one job for everything collected so far. Called at the end of each request, queue job and bulk operation.
     */
    public function flush(): void
    {
        if ($this->_rebuildAll !== null && $this->_bulkDepth === 0) {
            $this->rebuildAll($this->_rebuildAll);
            $this->_rebuildAll = null;
        }
        if ($this->_bulkDepth > 0 || (!$this->_pending && !$this->_clearAll)) {
            return;
        }
        $reasons = array_values(array_unique($this->_reasons));
        $reason = $reasons ? implode('; ', $reasons) : Craft::t('speedy', 'Content changed');
        if (count($this->_changed) > count($reasons)) {
            $reason .= ' ' . Craft::t('speedy', 'and {count} more', ['count' => count($this->_changed) - count($reasons)]);
        }
        $job = new ClearJob([
            'tags' => array_keys($this->_pending),
            'all' => $this->_clearAll,
            'reason' => $reason,
        ]);
        $this->_pending = [];
        $this->_reasons = [];
        $this->_changed = [];
        $this->_clearAll = false;
        if ($this->clearInRequest()) {
            // Without a running queue, clear now so changes still show; pages are rebuilt on their next visit.
            $this->_rebuild = false;
            try {
                $job->execute(null);
            } finally {
                $this->_rebuild = null;
            }
            return;
        }
        Queue::push($job, 1024);
    }

    private function clearInRequest(): bool
    {
        $mode = Plugin::getInstance()->getSettings()->clearInRequest;
        if ($mode === 'auto') {
            return Plugin::getInstance()->insights->queueStalledFor() > 0;
        }

        return filter_var($mode, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Clears the pages that use any of the tags: deletes them or rebuilds them, and clears the CDN.
     *
     * @param string[] $tags
     * @return int How many pages were cleared.
     */
    public function clearTags(array $tags, string $reason): int
    {
        $this->markCleared($tags);
        $keys = Plugin::getInstance()->storage->takePagesForTags($tags);

        return $this->clearKeys($keys, $reason);
    }

    /**
     * Notes when tags were cleared, so a page built from what was there before isn’t saved after the clear.
     *
     * @param string[] $tags
     */
    private function markCleared(array $tags): void
    {
        $now = microtime(true);
        $cache = Craft::$app->getCache();
        $cache->set('speedy:cleared:any', $now, 3600);
        foreach (array_unique($tags) as $tag) {
            $cache->set('speedy:cleared:' . md5($tag), $now, 3600);
        }
    }

    /**
     * Whether everything, or any of these tags, was cleared after a time, such as when a page started being built.
     *
     * @param string[] $tags
     */
    public function clearedSince(float $since, array $tags): bool
    {
        $cache = Craft::$app->getCache();
        $tags = array_unique($tags);
        // A page with hundreds of tags, such as a long listing, checks whether anything was cleared instead of each tag.
        $keys = count($tags) > 100
            ? ['speedy:cleared:all', 'speedy:cleared:any']
            : array_merge(['speedy:cleared:all'], array_map(fn($t) => 'speedy:cleared:' . md5((string)$t), $tags));
        foreach ($cache->multiGet($keys) as $at) {
            if ($at !== false && $at !== null && (float)$at > $since) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clears pages by key.
     *
     * @param string[] $keys
     */
    public function clearKeys(array $keys, string $reason): int
    {
        $plugin = Plugin::getInstance();
        if ($keys && $this->hasEventHandlers(self::EVENT_BEFORE_CLEAR)) {
            $before = new BeforeClearEvent(['reason' => $reason, 'keys' => array_values($keys)]);
            $this->trigger(self::EVENT_BEFORE_CLEAR, $before);
            if (!$before->isValid) {
                return 0;
            }
            $keys = array_filter($before->keys, 'is_string');
        }
        $storage = $plugin->storage;
        $rebuild = [];
        $rebuildKeys = [];
        $purge = [];
        $count = 0;

        foreach ($keys as $key) {
            $meta = $storage->meta($key);
            if (!$meta && !$storage->exists($key)) {
                continue;
            }
            $count++;
            $url = $meta['url'] ?? null;
            if ($url && ($this->_rebuild ?? $plugin->getSettings()->rebuildCleared)) {
                // Keep the old copy until the new one is ready, so nobody waits for it.
                $rebuild[] = $url;
                $rebuildKeys[$url] = $key;
            } else {
                $storage->delete($key);
                if ($url) {
                    $purge[] = $url;
                }
            }
        }

        if ($purge) {
            $plugin->cdn->purgeUrls($purge);
        }
        if ($rebuild) {
            $plugin->warmer->queue($rebuild, true, $rebuildKeys);
        }
        if ($count) {
            $plugin->log->add($reason, $count, array_merge($rebuild, $purge));
            $this->afterClear($reason, $count, array_merge($rebuild, $purge), false);
        }

        return $count;
    }

    /**
     * Clears one URL, such as from the control panel.
     */
    public function clearUrl(string $url): int
    {
        $storage = Plugin::getInstance()->storage;
        $key = $storage->keyForUrl($url);
        if (!$key) {
            return 0;
        }
        $keys = [$key];
        // A page’s address clears its query string versions too, such as `?view=map` and `?page=2`.
        if (!str_contains($key, '/' . Storage::QUERY_FOLDER . '/')) {
            array_push($keys, ...$storage->queryPageKeys($key));
        }

        return $this->clearKeys($keys, Craft::t('speedy', 'Cleared {url}', ['url' => $url]));
    }

    /**
     * Clears everything, including the CDN.
     */
    public function clearAll(string $reason): void
    {
        $plugin = Plugin::getInstance();
        if ($this->hasEventHandlers(self::EVENT_BEFORE_CLEAR)) {
            $before = new BeforeClearEvent(['reason' => $reason, 'all' => true]);
            $this->trigger(self::EVENT_BEFORE_CLEAR, $before);
            if (!$before->isValid) {
                return;
            }
        }
        $count = $plugin->storage->stats()['pages'];
        Craft::$app->getCache()->set('speedy:cleared:all', microtime(true), 3600);
        $plugin->storage->clearAll();
        $plugin->cdn->purgeAll();
        $plugin->log->add($reason, $count, []);
        $this->afterClear($reason, $count, [], true);
        if ($plugin->getSettings()->warm) {
            $plugin->warmer->queue($plugin->warmer->warmList(), false);
        }
    }

    /**
     * Rebuilds every cached page in the background. Visitors keep getting the old copies until the new ones are
     * ready, so it’s the gentle way to refresh a busy site, such as after a deploy.
     */
    public function rebuildAll(string $reason): int
    {
        $plugin = Plugin::getInstance();
        $urls = [];
        $keys = [];
        foreach ($plugin->storage->allKeys() as $key) {
            $url = $plugin->storage->meta($key)['url'] ?? null;
            if ($url) {
                $urls[] = $url;
                $keys[$url] = $key;
            }
        }
        if (!$urls) {
            return 0;
        }
        $urls = $plugin->warmer->importantFirst($urls);
        $plugin->warmer->queue($urls, true, $keys);
        $plugin->log->add($reason, count($urls), $urls);

        return count($urls);
    }

    /**
     * Clears pages whose scheduled content has gone live or expired.
     */
    public function clearExpired(): int
    {
        $plugin = Plugin::getInstance();
        $now = time();
        $data = $plugin->storage->readData('state');
        $since = (int)($data['lastExpire'] ?? $now - 3600);

        $plugin->storage->pruneLocks();
        $plugin->storage->deleteOldTrash();
        $keys = $plugin->storage->takeExpired($now);
        $count = $keys ? $this->clearKeys($keys, Craft::t('speedy', 'Scheduled content changed')) : 0;
        $count += $this->clearWentLive($since, $now);

        $data = $plugin->storage->readData('state');
        $data['lastExpire'] = $now;
        unset($data['expireJobAt']);
        $plugin->storage->writeData('state', $data);
        $next = array_filter([$plugin->storage->nextExpiry(), $this->nextPostDate()]);
        if ($next) {
            $this->scheduleExpiry(max(min($next), time() + 60));
        }

        return $count;
    }

    /**
     * Entries and products whose post date passed since the last check: clears the pages that list their section,
     * as those pages were cached before they went live. Craft only records expiry dates of what a page shows.
     */
    private function clearWentLive(int $since, int $now): int
    {
        $count = 0;
        $range = ['and', '> ' . date(DATE_ATOM, $since), '<= ' . date(DATE_ATOM, $now)];
        foreach ($this->postDatedTypes() as $class) {
            foreach ($class::find()->site('*')->unique()->status('live')->postDate($range)->all() as $element) {
                $tags = ["element::$element->id"];
                foreach ($element->getCacheTags() as $tag) {
                    $tags[] = str_starts_with($tag, 'element::') ? $tag : "element::$class::$tag";
                }
                $count += $this->clearTags($tags, Craft::t('speedy', '{type} “{title}” went live', [
                    'type' => $element::displayName(),
                    'title' => (string)$element,
                ]));
            }
        }

        return $count;
    }

    /**
     * When the next scheduled entry or product goes live, or null.
     */
    private function nextPostDate(): ?int
    {
        $next = null;
        foreach ($this->postDatedTypes() as $class) {
            $element = $class::find()->site('*')->unique()->status('pending')->orderBy(['postDate' => SORT_ASC])->one();
            $date = $element->postDate ?? null;
            if ($date instanceof \DateTimeInterface && ($next === null || $date->getTimestamp() < $next)) {
                $next = $date->getTimestamp();
            }
        }

        return $next;
    }

    /**
     * Element types with post dates, which go live on their own.
     *
     * @return array<class-string<\craft\elements\Entry>|class-string>
     */
    private function postDatedTypes(): array
    {
        $types = [\craft\elements\Entry::class];
        if (class_exists(\craft\commerce\elements\Product::class)) {
            $types[] = \craft\commerce\elements\Product::class;
        }

        return $types;
    }

    /**
     * Makes sure a job runs when a page expires, for sites without the cron command.
     */
    public function scheduleExpiry(int $at): void
    {
        $storage = Plugin::getInstance()->storage;
        $state = $storage->readData('state');
        $scheduled = $state['expireJobAt'] ?? null;
        if ($scheduled !== null && $scheduled <= $at && $scheduled > time()) {
            return;
        }
        $state['expireJobAt'] = $at;
        $storage->writeData('state', $state);
        Queue::push(new ExpireJob(), 1024, max(0, $at - time()));
    }

    /**
     * @param string[] $urls
     */
    private function afterClear(string $reason, int $count, array $urls, bool $all): void
    {
        if ($this->hasEventHandlers(self::EVENT_AFTER_CLEAR)) {
            $this->trigger(self::EVENT_AFTER_CLEAR, new ClearEvent(['reason' => $reason, 'count' => $count, 'urls' => $urls, 'all' => $all]));
        }
    }

    /**
     * Words for a change Craft announced by tag alone, such as an import finishing: “Entries changed”.
     *
     * @param string[] $tags
     */
    private function describeTags(array $tags): string
    {
        foreach ($tags as $tag) {
            if (preg_match('/^element::([^:]+(?:\\\\[^:]+)*)(?:::\*)?$/', $tag, $m) && is_subclass_of($m[1], ElementInterface::class)) {
                return Craft::t('speedy', '{type} changed', ['type' => $m[1]::pluralDisplayName()]);
            }
        }

        return Craft::t('speedy', 'Content changed');
    }

    private function describe(ElementInterface $element): string
    {
        $type = strtolower($element::lowerDisplayName());
        $title = (string)$element ?: "#$element->id";

        return Craft::t('speedy', 'Saved {type} “{title}”', ['type' => $type, 'title' => mb_strimwidth($title, 0, 60, '…')]);
    }
}
