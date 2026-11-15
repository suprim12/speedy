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
use craft\helpers\Queue;
use suprim\speedy\jobs\ClearJob;
use suprim\speedy\jobs\ExpireJob;
use suprim\speedy\Plugin;

/**
 * Clears pages when the content they show changes, using the cache tags Craft fires.
 */
class Clearer extends Component
{
    /** @var array<string, true> Tags waiting to be cleared at the end of the request or bulk operation. */
    private array $_pending = [];

    /** @var string[] What changed, for the log. */
    private array $_reasons = [];

    private bool $_clearAll = false;
    private int $_bulkDepth = 0;

    /**
     * Collects the tags from Craft’s invalidation event, without clearing yet.
     */
    public function onInvalidate(InvalidateElementCachesEvent $event): void
    {
        $tags = $event->tags;
        // Drafts and revisions never appear on the live site.
        $tags = array_filter($tags, fn($t) => !str_ends_with($t, '::drafts') && !str_ends_with($t, '::revisions'));
        if (!$tags) {
            return;
        }
        if (in_array('element', $tags, true)) {
            $this->_clearAll = true;
        }
        foreach ($tags as $tag) {
            $this->_pending[$tag] = true;
        }
        if ($event->element instanceof ElementInterface && count($this->_reasons) < 5) {
            $this->_reasons[] = $this->describe($event->element);
        } elseif (!$event->element && count($this->_reasons) < 5) {
            $this->_reasons[] = Craft::t('speedy', 'Caches cleared for {tags}', ['tags' => implode(', ', array_slice($tags, 0, 3))]);
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
        if ($this->_bulkDepth > 0 || (!$this->_pending && !$this->_clearAll)) {
            return;
        }
        $reason = $this->_reasons ? implode('; ', array_unique($this->_reasons)) : Craft::t('speedy', 'Content changed');
        $job = new ClearJob([
            'tags' => array_keys($this->_pending),
            'all' => $this->_clearAll,
            'reason' => $reason,
        ]);
        $this->_pending = [];
        $this->_reasons = [];
        $this->_clearAll = false;
        Queue::push($job, 1024);
    }

    /**
     * Clears the pages that use any of the tags: deletes them or rebuilds them, and clears the CDN.
     *
     * @param string[] $tags
     * @return int How many pages were cleared.
     */
    public function clearTags(array $tags, string $reason): int
    {
        $keys = Plugin::getInstance()->storage->takePagesForTags($tags);

        return $this->clearKeys($keys, $reason);
    }

    /**
     * Clears pages by key.
     *
     * @param string[] $keys
     */
    public function clearKeys(array $keys, string $reason): int
    {
        $plugin = Plugin::getInstance();
        $storage = $plugin->storage;
        $rebuild = [];
        $purge = [];
        $count = 0;

        foreach ($keys as $key) {
            $meta = $storage->meta($key);
            if (!$meta && !$storage->exists($key)) {
                continue;
            }
            $count++;
            $url = $meta['url'] ?? null;
            if ($url && $plugin->getSettings()->rebuildCleared) {
                // Keep the old copy until the new one is ready, so nobody waits for it.
                $rebuild[] = $url;
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
            $plugin->warmer->queue($rebuild, true);
        }
        if ($count) {
            $plugin->log->add($reason, $count, array_merge($rebuild, $purge));
        }

        return $count;
    }

    /**
     * Clears one URL, such as from the control panel.
     */
    public function clearUrl(string $url): int
    {
        $parts = parse_url($url);
        $key = Plugin::getInstance()->storage->key($parts['host'] ?? '', $parts['path'] ?? '');

        return $key ? $this->clearKeys([$key], Craft::t('speedy', 'Cleared {url}', ['url' => $url])) : 0;
    }

    /**
     * Clears everything, including the CDN.
     */
    public function clearAll(string $reason): void
    {
        $plugin = Plugin::getInstance();
        $count = $plugin->storage->stats()['pages'];
        $plugin->storage->clearAll();
        $plugin->cdn->purgeAll();
        $plugin->log->add($reason, $count, []);
        if ($plugin->getSettings()->warm) {
            $plugin->warmer->queue($plugin->warmer->warmList(), false);
        }
    }

    /**
     * Clears pages whose scheduled content has gone live or expired.
     */
    public function clearExpired(): int
    {
        $keys = Plugin::getInstance()->storage->takeExpired(time());
        $count = $keys ? $this->clearKeys($keys, Craft::t('speedy', 'Scheduled content changed')) : 0;
        $plugin = Plugin::getInstance();
        $data = $plugin->storage->readData('state');
        $data['lastExpire'] = time();
        unset($data['expireJobAt']);
        $plugin->storage->writeData('state', $data);
        if (($next = $plugin->storage->nextExpiry()) !== null) {
            $this->scheduleExpiry(max($next, time() + 60));
        }

        return $count;
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

    private function describe(ElementInterface $element): string
    {
        $type = strtolower($element::lowerDisplayName());
        $title = (string)$element ?: "#$element->id";

        return Craft::t('speedy', 'Saved {type} “{title}”', ['type' => $type, 'title' => mb_strimwidth($title, 0, 60, '…')]);
    }
}
