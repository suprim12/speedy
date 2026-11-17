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
use craft\elements\Category;
use craft\elements\Entry;
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

    /** Commerce’s catalog prices, each with the dates it applies between. */
    private const COMMERCE_PRICES = '{{%commerce_catalogpricing}}';

    /** What Commerce has still to price: rows of purchasable or pricing rule IDs, each taken by one price job. */
    private const COMMERCE_PRICE_QUEUE = '{{%commerce_catalogpricing_queue}}';

    /** @var array<int, array{type: string, ids: int[]|null, before: int[]}>|null Commerce’s rows waiting to be priced, before a price job. */
    private ?array $_priceRows = null;

    /** When the price job started, to find the prices it wrote. */
    private ?string $_priceJobStart = null;

    /** Marks a page waiting to be rebuilt after a run of changes, until its rebuild starts. */
    public const REBUILD_LATER = 'speedy:rebuildLater:';

    /** The most tags a section is cleared with while someone waits; a bigger one is cleared in the background. */
    public int $sectionInRequest = 500;

    /** Returned when pages are cleared in the background, so how many isn’t known yet. */
    public const QUEUED = -1;

    /** How long past its time a waiting rebuild is still expected, such as on a busy queue, before another may be queued. */
    public const REBUILD_LATER_GRACE = 600;

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

    /** @var array<string, string> Pages of elements saved or gone live, to build when they aren’t cached: URIs by URL. */
    private array $_build = [];

    /** More saved pages than this in one request or job is an import: none of them are built. */
    public const BUILD_SAVED_MOST = 20;

    /** The longest pattern to clear by, and the most `*` in it. */
    private const PATTERN_LONGEST = 500;
    private const PATTERN_MOST_STARS = 10;

    /** Why every page should be rebuilt at the end of the request, such as SEOmatic’s settings changing. */
    private ?string $_rebuildAll = null;
    private int $_bulkDepth = 0;

    /** Whether the pages being cleared are cleared by content changing, so pages that clear on any change wait to be rebuilt. */
    private bool $_fromChange = false;

    /** @var array<string, true> The tags being cleared now, to tell how each page was reached. */
    private array $_clearing = [];

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
        $this->noteSaved($element);
    }

    /**
     * Notes the page of a saved element, to build at the end of the request or queue job if it isn’t cached. Not
     * while resaving, nor for drafts, revisions, elements that aren’t live, or sections whose template doesn’t exist.
     * (Craft wraps every save in a bulk operation of its own, so that can’t tell an import apart; the number of saves
     * does, in buildNoted().)
     */
    private function noteSaved(ElementInterface $element): void
    {
        $settings = Plugin::getInstance()->getSettings();
        if (!$settings->buildSavedPages || $element->resaving || ElementHelper::isDraftOrRevision($element)
            || count($this->_build) > self::BUILD_SAVED_MOST || !in_array($element->getStatus(), [\craft\base\Element::STATUS_ENABLED, 'live'], true)) {
            return;
        }
        $this->noteBuild($element);
    }

    /**
     * Notes an element’s page to build in buildNoted(), unless it has no URL or its section’s template doesn’t exist.
     */
    private function noteBuild(ElementInterface $element): void
    {
        $url = $element->getUrl();
        $groupId = match (true) {
            $element instanceof Entry => (int)$element->sectionId,
            $element instanceof Category => (int)$element->groupId,
            default => 0,
        };
        if ($url !== null && $element->uri !== null && !Plugin::getInstance()->insights->templateMissing($element::class, $groupId, (int)$element->siteId)) {
            $this->_build[$url] = $element->uri;
        }
    }

    /**
     * Builds the noted pages that aren’t cached, in the background, at the end of a request or queue job. More than
     * BUILD_SAVED_MOST saves in one is an import, and builds none. Pages that are cached are rebuilt when they’re
     * cleared, and pages left out by Never cache or Only cache are skipped.
     */
    public function buildNoted(): void
    {
        $pages = $this->_build;
        $this->_build = [];
        $plugin = Plugin::getInstance();
        if (!$pages || count($pages) > self::BUILD_SAVED_MOST || !$plugin->getSettings()->isEnabled()) {
            return;
        }
        $urls = [];
        foreach ($pages as $url => $uri) {
            $key = $plugin->storage->keyForUrl($url);
            if ($key !== null && !$plugin->storage->exists($key) && !$plugin->insights->leftOut($url, $uri)) {
                $urls[] = $url;
            }
        }
        if ($urls) {
            $plugin->warmer->queue($urls, false);
        }
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

        return $tags ? $this->clearTags($tags, $reason, false) : 0;
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
     * @param bool $fromChange Whether content changing cleared them, so pages that clear on any change wait for a run
     * of changes to end. False for clearing by hand or at a set time, which rebuilds them straight away.
     * @return int How many pages were cleared.
     */
    public function clearTags(array $tags, string $reason, bool $fromChange = true): int
    {
        $this->markCleared($tags);
        $keys = Plugin::getInstance()->storage->takePagesForTags($tags);
        $this->_fromChange = $fromChange;
        $this->_clearing = array_fill_keys($tags, true);
        try {
            return $this->clearKeys($keys, $reason);
        } finally {
            $this->_fromChange = false;
            $this->_clearing = [];
        }
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
        $later = [];
        $purge = [];
        $delay = $this->_fromChange ? $plugin->getSettings()->broadRebuildDelay : 0;
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
                $rebuildKeys[$url] = $key;
                if ($delay > 0 && $this->onlyThroughAnyChange($meta['tags'] ?? [])) {
                    $later[] = $url;
                } else {
                    $rebuild[] = $url;
                }
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
        if ($later) {
            // A listing cleared by any entry is rebuilt once after a run of changes, such as an import, not after each.
            // The mark lasts until the rebuild starts, or ten minutes past it, so a lost job doesn’t hold the page back.
            $cache = Craft::$app->getCache();
            $later = array_values(array_filter($later, fn($url) => $cache->add(self::REBUILD_LATER . md5($url), true, $delay + self::REBUILD_LATER_GRACE)));
            $plugin->warmer->queue($later, true, $rebuildKeys, $delay);
            $rebuild = array_merge($rebuild, $later);
        }
        if ($count) {
            $plugin->log->add($reason, $count, array_merge($rebuild, $purge));
            $this->afterClear($reason, $count, array_merge($rebuild, $purge), false);
        }

        return $count;
    }

    /**
     * Whether the change reached this page only through a query of any element of a type, such as a listing of every
     * entry. A page showing the changed element itself, or its section, is rebuilt straight away. (Image and entry
     * fields query any element of their type, so most pages have such a tag as well.)
     *
     * @param array<mixed> $pageTags
     */
    private function onlyThroughAnyChange(array $pageTags): bool
    {
        $through = array_filter($pageTags, fn($tag) => is_string($tag) && isset($this->_clearing[$tag]));

        return $through !== [] && self::clearsOnAnyChange($through) && !array_filter($through, fn($tag) => !str_ends_with($tag, '::*'));
    }

    /**
     * Whether a page with these tags is cleared by any change to a type of element, such as a listing of every entry.
     *
     * @param array<mixed> $tags
     */
    public static function clearsOnAnyChange(array $tags): bool
    {
        foreach ($tags as $tag) {
            if (is_string($tag) && str_starts_with($tag, 'element::') && str_ends_with($tag, '::*')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clears every page that shows a section: its listings, including those of one entry type, and the pages that
     * show its entries. Rebuilt straight away, as someone asked for it.
     *
     * @return int|null How many pages were cleared, Clearer::QUEUED when a big section is cleared in the background,
     * or null when there’s no such section.
     */
    public function clearSection(int $sectionId): ?int
    {
        $section = Craft::$app->getEntries()->getSectionById($sectionId);
        if ($section === null) {
            return null;
        }
        $class = \craft\elements\Entry::class;
        $tags = ["element::$class::section:$section->id"];
        foreach ($section->getEntryTypes() as $type) {
            $tags[] = "element::$class::entryType:$type->id";
        }
        foreach (\craft\elements\Entry::find()->sectionId($section->id)->status(null)->site('*')->unique()->ids() as $id) {
            $tags[] = "element::$id";
        }
        $user = Craft::$app->getUser()->getIdentity();
        $reason = Craft::t('speedy', 'Cleared the {section} section{by, select, none{} other{ by {by}}}', [
            'section' => $section->name,
            'by' => $user ? (string)$user : 'none',
        ]);
        // Thousands of entries mean thousands of tags to look up: too many to wait for.
        if (count($tags) > $this->sectionInRequest) {
            Queue::push(new ClearJob(['tags' => $tags, 'reason' => $reason, 'byHand' => true]), 1024);
            return self::QUEUED;
        }
        $this->markCleared($tags);

        return $this->clearKeys(Plugin::getInstance()->storage->takePagesForTags($tags), $reason);
    }

    /**
     * Clears one URL, such as from the control panel, or every page matching a pattern with `*`, such as
     * `https://example.com/news/*` or `/news/*` on every domain.
     */
    public function clearUrl(string $url): int
    {
        if (str_contains($url, '*')) {
            return $this->clearMatching($url);
        }
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
     * Clears every page matching a pattern, with its query string versions: `https://example.com/news/*`, or a path
     * such as `/news/*` for every domain. `*` matches anything, including `/`.
     */
    public function clearMatching(string $pattern): int
    {
        $regex = self::patternRegex($pattern);
        if ($regex === null) {
            return 0;
        }
        $keys = [];
        foreach (Plugin::getInstance()->storage->allKeys() as $key) {
            [$host, $path] = explode('/', $key, 2) + [1 => ''];
            if (preg_match($regex, "$host/$path")) {
                $keys[] = $key;
            }
        }

        return $this->clearKeys($keys, Craft::t('speedy', 'Cleared {url}', ['url' => trim($pattern)]));
    }

    /**
     * A pattern as a regular expression for page keys (`host/path`), or null when it can’t be one. A pattern without a
     * domain matches every domain.
     */
    public static function patternRegex(string $pattern): ?string
    {
        $pattern = trim($pattern);
        // Each `*` multiplies the work of matching a page that doesn’t match.
        if ($pattern === '' || strlen($pattern) > self::PATTERN_LONGEST || substr_count((string)preg_replace('/\*+/', '*', $pattern), '*') > self::PATTERN_MOST_STARS) {
            return null;
        }
        // Only the address: no query string or fragment.
        $pattern = (string)preg_replace('/[?#].*$/s', '', $pattern);
        if (preg_match('#^https?://([^/]+)(.*)$#i', $pattern, $m)) {
            $host = strtolower($m[1]);
            $path = $m[2];
        } elseif (preg_match('#^[a-z][a-z0-9+.-]*:#i', $pattern)) {
            return null;
        } else {
            $host = '*';
            $path = $pattern;
        }
        $path = trim(rawurldecode($path), '/');
        // Several stars in a row match the same as one, and are slower.
        $wild = fn(string $part) => str_replace('\\*', '.*', preg_quote((string)preg_replace('/\*+/', '*', $part), '#'));
        $hostRegex = $host === '*' ? '[^/]+' : $wild($host);

        return '#^' . $hostRegex . '/' . $wild($path) . '$#i';
    }

    /**
     * Clears every page of a site, such as one site of several on the same domain.
     *
     * @return int|null How many pages were cleared, or null when there’s no such site with pages.
     */
    public function clearSite(int $siteId): ?int
    {
        $site = Craft::$app->getSites()->getSiteById($siteId);
        if ($site === null || !$site->hasUrls) {
            return null;
        }
        $keys = [];
        foreach (Plugin::getInstance()->storage->allKeys() as $key) {
            if (($this->siteForKey($key)['id'] ?? null) === $siteId) {
                $keys[] = $key;
            }
        }
        $user = Craft::$app->getUser()->getIdentity();

        return $this->clearKeys($keys, Craft::t('speedy', 'Cleared the {site} site{by, select, none{} other{ by {by}}}', [
            'site' => $site->getName(),
            'by' => $user ? (string)$user : 'none',
        ]));
    }

    /** @var array<int, array{id: int, host: ?string, path: string}>|null */
    private ?array $_siteBases = null;

    /**
     * The site a page belongs to, by the longest base URL that it starts with, and its path within that site, such as
     * `news/a` for `example.com/de/news/a` on a site at `/de/`. Null when no site’s base URL matches.
     *
     * @return array{id: int, path: string}|null
     */
    public function siteForKey(string $key): ?array
    {
        if ($this->_siteBases === null) {
            $this->_siteBases = [];
            foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
                $raw = (string)\craft\helpers\App::parseEnv((string)$site->getBaseUrl(false));
                if (!$site->hasUrls || $raw === '') {
                    continue;
                }
                // A base URL of `@web` answers to any domain, so only its path is known.
                $anyHost = str_contains($raw, '@web');
                $parts = parse_url($anyHost ? 'http://any' . str_replace('@web', '', $raw) : (string)Craft::getAlias($raw));
                $port = $parts['port'] ?? null;
                $this->_siteBases[] = [
                    'id' => (int)$site->id,
                    'host' => $anyHost ? null : strtolower((string)($parts['host'] ?? '')) . ($port !== null && !in_array($port, [80, 443], true) ? ":$port" : ''),
                    'path' => trim(rawurldecode((string)($parts['path'] ?? '')), '/'),
                ];
            }
            // The longest paths first, and a known domain before any domain.
            usort($this->_siteBases, fn($a, $b) => [strlen($b['path']), $b['host'] !== null] <=> [strlen($a['path']), $a['host'] !== null]);
        }
        [$host, $path] = explode('/', $key, 2) + [1 => ''];
        $path = explode('/' . Storage::QUERY_FOLDER . '/', "/$path/", 2)[0];
        $path = trim($path, '/');
        foreach ($this->_siteBases as $base) {
            if ($base['host'] !== null && $base['host'] !== strtolower($host)) {
                continue;
            }
            if ($base['path'] === '' || $path === $base['path'] || str_starts_with($path, $base['path'] . '/')) {
                return ['id' => $base['id'], 'path' => ltrim(substr($path, strlen($base['path'])), '/')];
            }
        }

        return null;
    }

    /**
     * Rebuilds the `dailyRebuild` pages once a day, at `dailyRebuildTime`, or as soon as the scheduled check runs
     * after it, such as when the queue was stopped. Visitors get the old copies until the new ones are ready.
     *
     * @return int How many pages are being rebuilt.
     */
    public function rebuildDaily(int $now): int
    {
        $plugin = Plugin::getInstance();
        $patterns = $plugin->getSettings()->getDailyRebuild();
        $due = $this->dailyRebuildDue($now);
        if (!$patterns || $due === null || !$plugin->getSettings()->isEnabled()) {
            return 0;
        }
        $mutex = Craft::$app->getMutex();
        if (!$mutex->acquire('speedy:dailyRebuild')) {
            return 0;
        }
        try {
            $state = $plugin->storage->readData('state');
            // Set up since the last time it was due: start from the next one, rather than rebuilding straight away.
            if (!isset($state['dailyRebuildAt'])) {
                $state['dailyRebuildAt'] = $now;
                $plugin->storage->writeData('state', $state);
                return 0;
            }
            if ((int)$state['dailyRebuildAt'] >= $due) {
                return 0;
            }
            $state['dailyRebuildAt'] = $now;
            $plugin->storage->writeData('state', $state);
        } finally {
            $mutex->release('speedy:dailyRebuild');
        }

        $urls = [];
        $keys = [];
        foreach ($plugin->storage->allKeys() as $key) {
            $url = $plugin->storage->meta($key)['url'] ?? null;
            if (!$url) {
                continue;
            }
            $path = trim(rawurldecode((string)parse_url($url, PHP_URL_PATH)), '/');
            $sitePath = $this->siteForKey($key)['path'] ?? $path;
            if (Cacher::matchingPattern($path, $patterns) !== null || Cacher::matchingPattern($sitePath, $patterns) !== null) {
                $urls[] = $url;
                $keys[$url] = $key;
            }
        }
        if ($urls) {
            $plugin->warmer->queue($plugin->warmer->importantFirst($urls), true, $keys);
            $plugin->log->add(Craft::t('speedy', 'Daily rebuild'), count($urls), $urls);
        }

        return count($urls);
    }

    /**
     * When the daily rebuild was last due, at or before $now, in the site’s time zone. Null without a valid time.
     */
    public function dailyRebuildDue(int $now): ?int
    {
        $time = Plugin::getInstance()->getSettings()->getDailyRebuildTime();
        if ($time === null) {
            return null;
        }
        $at = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone(Craft::$app->getTimeZone()))->setTime($time[0], $time[1]);
        if ($at->getTimestamp() > $now) {
            $at = $at->modify('-1 day')->setTime($time[0], $time[1]);
        }

        return $at->getTimestamp();
    }

    /**
     * When the daily rebuild is next due, after $now, or null when there isn’t one.
     */
    public function nextDailyRebuild(int $now): ?int
    {
        if (!Plugin::getInstance()->getSettings()->getDailyRebuild()) {
            return null;
        }
        $due = $this->dailyRebuildDue($now);
        if ($due === null) {
            return null;
        }
        $time = Plugin::getInstance()->getSettings()->getDailyRebuildTime() ?? [0, 0];
        $next = (new \DateTimeImmutable('@' . $due))->setTimezone(new \DateTimeZone(Craft::$app->getTimeZone()))->modify('+1 day')->setTime($time[0], $time[1]);

        return $next->getTimestamp();
    }

    /**
     * Makes sure the scheduled check runs at the next daily rebuild, such as after it’s set up. Cheap to call often.
     */
    public function scheduleDailyRebuild(): void
    {
        $now = time();
        $next = $this->nextDailyRebuild($now);
        if ($next === null) {
            return;
        }
        $storage = Plugin::getInstance()->storage;
        $state = $storage->readData('state');
        if (!isset($state['dailyRebuildAt'])) {
            // From now on: a time already passed today waits until tomorrow.
            $state['dailyRebuildAt'] = $now;
            $storage->writeData('state', $state);
        }
        $this->scheduleExpiry($next);
    }

    /**
     * Clears everything, including the CDN.
     */
    /**
     * @return bool False when nothing could be cleared, as the cache folder isn’t Speedy’s own.
     */
    public function clearAll(string $reason): bool
    {
        $plugin = Plugin::getInstance();
        if ($this->hasEventHandlers(self::EVENT_BEFORE_CLEAR)) {
            $before = new BeforeClearEvent(['reason' => $reason, 'all' => true]);
            $this->trigger(self::EVENT_BEFORE_CLEAR, $before);
            if (!$before->isValid) {
                return true;
            }
        }
        $count = $plugin->storage->stats()['pages'];
        Craft::$app->getCache()->set('speedy:cleared:all', microtime(true), 3600);
        if (!$plugin->storage->clearAll()) {
            $plugin->log->add(Craft::t('speedy', 'Couldn’t clear: the cache folder isn’t Speedy’s own'), 0, []);
            return false;
        }
        $plugin->cdn->purgeAll();
        $plugin->log->add($reason, $count, []);
        $this->afterClear($reason, $count, [], true);
        if ($plugin->getSettings()->warm) {
            $plugin->warmer->queue($plugin->warmer->warmList(), false);
        }

        return true;
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
        $count += $this->clearPricesChanged($since, $now);
        $count += $this->rebuildDaily($now);

        $data = $plugin->storage->readData('state');
        $data['lastExpire'] = $now;
        unset($data['expireJobAt']);
        $plugin->storage->writeData('state', $data);
        $next = array_filter([$plugin->storage->nextExpiry(), $this->nextPostDate(), $this->nextPriceChange(), $this->nextDailyRebuild($now)]);
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
                // Going live is a one-off at a set time, so its listings are rebuilt straight away.
                $count += $this->clearTags($tags, Craft::t('speedy', '{type} “{title}” went live', [
                    'type' => $element::displayName(),
                    'title' => (string)$element,
                ]), false);
            }
        }

        // Their own pages too, in each site, as nobody could have cached them before.
        if (Plugin::getInstance()->getSettings()->buildSavedPages) {
            foreach ($this->postDatedTypes() as $class) {
                foreach ($class::find()->site('*')->status('live')->postDate($range)->uri(':notempty:')->limit(self::BUILD_SAVED_MOST)->all() as $element) {
                    $this->noteBuild($element);
                }
            }
            $this->buildNoted();
        }

        return $count;
    }

    /**
     * Commerce worked out its catalog prices again, such as after a pricing rule was saved: clears the pages that
     * show those purchasables, or every page with a product when all of them were done. Commerce doesn’t say that
     * prices changed, so without this the pages would keep the old ones.
     *
     * @param int[]|null $purchasableIds
     */
    public function pricesRecalculated(?array $purchasableIds): void
    {
        if (count($this->_reasons) < 3) {
            $this->_reasons[] = Craft::t('speedy', 'Commerce prices changed');
        }
        $this->_changed['commerce-prices'] = true;
        if ($purchasableIds === null) {
            // Only called by Commerce’s own job, so its element types exist.
            foreach (['craft\\commerce\\elements\\Variant', 'craft\\commerce\\elements\\Product'] as $class) {
                Craft::$app->getElements()->invalidateCachesForElementType($class);
            }
        } else {
            foreach ($this->purchasableTags($purchasableIds) as $tag) {
                $this->_pending[$tag] = true;
            }
        }
        // Prices that start or end later are cleared when they do.
        if ($next = $this->nextPriceChange()) {
            $this->scheduleExpiry($next);
        }
    }

    /**
     * The purchasables with prices from Commerce pricing rules.
     *
     * @param int|int[] $ruleIds
     * @return int[]
     */
    public function purchasablesPricedBy(int|array $ruleIds): array
    {
        if (!$this->hasCatalogPricing() || $ruleIds === []) {
            return [];
        }

        return array_map('intval', (new \craft\db\Query())->select(['purchasableId'])->distinct()->from(self::COMMERCE_PRICES)->where(['catalogPricingRuleId' => $ruleIds])->column());
    }

    /**
     * Before a Commerce price job: notes what’s waiting to be priced, as the job takes one row of it and deletes it.
     */
    public function beforePriceJob(): void
    {
        $this->_priceRows = null;
        $this->_priceJobStart = \craft\helpers\Db::prepareDateForDb(new \DateTime('-1 second'));
        if (!$this->hasCatalogPricing() || !Craft::$app->getDb()->tableExists(self::COMMERCE_PRICE_QUEUE)) {
            return;
        }
        $rows = [];
        foreach ((new \craft\db\Query())->select(['id', 'type', 'ids'])->from(self::COMMERCE_PRICE_QUEUE)->where(['reserved' => false])->all() as $row) {
            $ids = $row['ids'] === null ? null : array_map('intval', (array)\craft\helpers\Json::decodeIfJson($row['ids']));
            $rows[(int)$row['id']] = [
                'type' => (string)$row['type'],
                'ids' => $ids,
                // A rule’s old prices are gone once it’s priced again, such as when it no longer applies.
                'before' => $row['type'] === 'rule' && $ids ? $this->purchasablesPricedBy($ids) : [],
            ];
        }
        $this->_priceRows = $rows;
    }

    /**
     * After a Commerce price job: clears the pages of the purchasables it priced, and only those.
     *
     * @param int[]|null $purchasableIds The job’s own IDs, in Commerce versions that gave them to the job.
     * @param int[]|null $ruleIds
     */
    public function afterPriceJob(?array $purchasableIds, ?array $ruleIds): void
    {
        $rows = $this->_priceRows ?? [];
        $this->_priceRows = null;
        $start = $this->_priceJobStart;
        $this->_priceJobStart = null;
        if ($purchasableIds === null && $ruleIds === null && !Craft::$app->getDb()->tableExists(self::COMMERCE_PRICE_QUEUE)) {
            // Before Commerce 5.7, a job without IDs priced everything.
            $this->pricesRecalculated(null);
            return;
        }
        if ($purchasableIds !== null || $ruleIds !== null) {
            $this->pricesRecalculated(array_values(array_unique(array_merge($purchasableIds ?? [], $this->purchasablesPricedBy($ruleIds ?? [])))));
            return;
        }
        // The job took one of the rows noted before it: the one that’s gone.
        $left = $rows ? array_map('intval', (new \craft\db\Query())->select(['id'])->from(self::COMMERCE_PRICE_QUEUE)->where(['id' => array_keys($rows)])->column()) : [];
        $ids = [];
        foreach (array_diff_key($rows, array_flip($left)) as $row) {
            if ($row['ids'] === null) {
                // Everything was priced again.
                $this->pricesRecalculated(null);
                return;
            }
            $ids = array_merge($ids, $row['type'] === 'rule' ? array_merge($row['before'], $this->purchasablesPricedBy($row['ids'])) : $row['ids']);
        }
        // IDs added to the job’s row after it was noted, such as by a save at that moment: the prices it wrote say.
        if ($start !== null && $this->hasCatalogPricing()) {
            $ids = array_merge($ids, array_map('intval', (new \craft\db\Query())->select(['purchasableId'])->distinct()->from(self::COMMERCE_PRICES)->where(['>=', 'dateCreated', $start])->column()));
        }
        if ($ids) {
            $this->pricesRecalculated(array_values(array_unique($ids)));
        }
    }

    /**
     * Clears the pages showing purchasables whose Commerce prices started or ended, such as a sale that began.
     */
    private function clearPricesChanged(int $since, int $now): int
    {
        if (!$this->hasCatalogPricing()) {
            return 0;
        }
        $from = \craft\helpers\Db::prepareDateForDb(new \DateTime('@' . $since));
        $to = \craft\helpers\Db::prepareDateForDb(new \DateTime('@' . $now));
        $ids = (new \craft\db\Query())
            ->select(['purchasableId'])
            ->distinct()
            ->from(self::COMMERCE_PRICES)
            ->where(['or', ['and', ['>', 'dateFrom', $from], ['<=', 'dateFrom', $to]], ['and', ['>', 'dateTo', $from], ['<=', 'dateTo', $to]]])
            ->column();
        if (!$ids) {
            return 0;
        }

        // A price starting or ending is a one-off at a set time, such as a sale at midnight, so its pages are rebuilt
        // straight away rather than after a run of changes.
        $tags = $this->purchasableTags(array_map('intval', $ids));
        $this->markCleared($tags);

        return $this->clearKeys(Plugin::getInstance()->storage->takePagesForTags($tags), Craft::t('speedy', 'Commerce prices changed'));
    }

    /**
     * When the next Commerce price starts or ends, or null.
     */
    private function nextPriceChange(): ?int
    {
        if (!$this->hasCatalogPricing()) {
            return null;
        }
        $now = \craft\helpers\Db::prepareDateForDb(new \DateTime());
        $next = null;
        foreach (['dateFrom', 'dateTo'] as $column) {
            $date = (new \craft\db\Query())->from(self::COMMERCE_PRICES)->where(['>', $column, $now])->min($column);
            $at = $date ? \craft\helpers\DateTimeHelper::toDateTime($date) : false;
            if ($at && ($next === null || $at->getTimestamp() < $next)) {
                $next = $at->getTimestamp();
            }
        }

        return $next;
    }

    private function hasCatalogPricing(): bool
    {
        return Craft::$app->getPlugins()->isPluginEnabled('commerce') && Craft::$app->getDb()->tableExists(self::COMMERCE_PRICES);
    }

    /**
     * The tags of pages that show these purchasables: theirs, and their products’.
     *
     * @param int[] $ids
     * @return string[]
     */
    private function purchasableTags(array $ids): array
    {
        $owners = Craft::$app->getDb()->tableExists('{{%commerce_variants}}') ? (new \craft\db\Query())
            ->select(['primaryOwnerId'])
            ->from('{{%commerce_variants}}')
            ->where(['id' => $ids])
            ->column() : [];

        return array_values(array_unique(array_map(fn($id) => "element::$id", array_merge($ids, array_filter($owners)))));
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
