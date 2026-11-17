<?php

namespace suprim\speedy\tests\Integration;

use suprim\speedy\tests\TestCase;

class ClearerTest extends TestCase
{
    public function testClearsOnlyPagesThatUseTheTags(): void
    {
        $storage = $this->plugin()->storage;
        $storage->write('example.com/news', 'n', 'https://example.com/news', ['element::craft\elements\Entry::section:1'], null);
        $storage->write('example.com/news/a', 'a', 'https://example.com/news/a', ['element::42'], null);
        $storage->write('example.com/about', 'x', 'https://example.com/about', ['element::7'], null);

        $count = $this->plugin()->clearer->clearTags(['element::42', 'element::craft\elements\Entry::section:1'], 'Saved entry “A”');

        $this->assertSame(2, $count);
        $this->assertFalse($storage->exists('example.com/news'));
        $this->assertFalse($storage->exists('example.com/news/a'));
        $this->assertTrue($storage->exists('example.com/about'));
        $this->assertSame('Saved entry “A”', $this->plugin()->log->recent(1)[0]['reason']);
    }

    public function testClearsOneUrl(): void
    {
        $storage = $this->plugin()->storage;
        $storage->write('example.com/a', 'a', 'https://example.com/a', [], null);

        $this->assertSame(1, $this->plugin()->clearer->clearUrl('https://example.com/a'));
        $this->assertSame(0, $this->plugin()->clearer->clearUrl('https://example.com/a'));
    }

    public function testClearingAnAddressClearsItsQueryStringVersions(): void
    {
        foreach ([\suprim\speedy\models\Settings::STORAGE_FILES, \suprim\speedy\models\Settings::STORAGE_CRAFT_CACHE] as $mode) {
            $this->settings()->storage = $mode;
            $storage = $this->plugin()->storage;
            $base = 'example.com/homes';
            $map = (string)$storage->key('example.com', 'homes', ['view' => 'map']);
            $list = (string)$storage->key('example.com', 'homes', ['view' => 'list']);
            $other = 'example.com/homes-for-sale';
            foreach ([$base => '', $map => '?view=map', $list => '?view=list', $other => ''] as $key => $query) {
                $storage->write($key, '<html>Old</html>', 'https://example.com/' . explode('/', $key, 2)[1] . $query, [], null);
            }

            $this->assertSame(1, $this->plugin()->clearer->clearUrl('https://example.com/homes?view=map'), "$mode: one version on its own.");
            $this->assertFalse($storage->exists($map));
            $this->assertTrue($storage->exists($list));

            $storage->write($map, '<html>Old</html>', 'https://example.com/homes?view=map', [], null);
            $this->assertSame(3, $this->plugin()->clearer->clearUrl('https://example.com/homes'), "$mode: the page and its versions.");
            foreach ([$base, $map, $list] as $key) {
                $this->assertFalse($storage->exists($key), "$mode: $key");
            }
            $this->assertTrue($storage->exists($other), "$mode: a page whose address only starts the same is kept.");
            $storage->delete($other);
        }
    }

    public function testPagesClearedByAnyChangeAreRebuiltOnceAfterARunOfChanges(): void
    {
        $this->settings()->rebuildCleared = true;
        $storage = $this->plugin()->storage;
        $cache = \Craft::$app->getCache();
        $all = 'https://example.com/speedy-test-all-lots';
        $lot = 'https://example.com/speedy-test-lot';
        $cache->delete(\suprim\speedy\services\Clearer::REBUILD_LATER . md5($all));
        $pushed = [];
        $queue = function(\yii\queue\PushEvent $e) use (&$pushed) {
            foreach ($e->job->urls ?? [] as $url) {
                $pushed[] = [$url, (int)$e->delay];
            }
            $e->handled = true;
        };
        \Craft::$app->getQueue()->on(\yii\queue\Queue::EVENT_BEFORE_PUSH, $queue);
        try {
            foreach ([1, 2, 3] as $import) {
                $storage->write('example.com/speedy-test-all-lots', '<html>Old</html>', $all, ['element::craft\elements\Entry::*'], null);
                $storage->write('example.com/speedy-test-lot', '<html>Old</html>', $lot, ['element::42'], null);
                $this->plugin()->clearer->clearTags(['element::42', 'element::craft\elements\Entry::*'], "Import $import");
            }
            $this->assertSame([[$lot, 0], [$all, 60], [$lot, 0], [$lot, 0]], $pushed, 'The listing waits a minute, once; the entry’s own page doesn’t wait.');
            $this->assertTrue($storage->exists('example.com/speedy-test-all-lots'), 'Visitors get the old copy meanwhile.');

            // Once its rebuild starts, the next change needs another.
            $this->plugin()->warmer->warm([], true);
            $this->assertNotFalse($cache->get(\suprim\speedy\services\Clearer::REBUILD_LATER . md5($all)), 'Still waiting while its rebuild hasn’t started.');
            $this->plugin()->warmer->warm([$all], true);
            $this->assertFalse($cache->get(\suprim\speedy\services\Clearer::REBUILD_LATER . md5($all)));

            $pushed = [];
            $storage->write('example.com/speedy-test-all-lots', '<html>Old</html>', $all, ['element::craft\elements\Entry::*'], null);
            $this->plugin()->clearer->clearUrl($all);
            $this->assertSame([[$all, 0]], $pushed, 'Clearing it by hand rebuilds it straight away.');

            $cache->delete(\suprim\speedy\services\Clearer::REBUILD_LATER . md5($all));
            $this->settings()->broadRebuildDelay = 0;
            $pushed = [];
            $storage->write('example.com/speedy-test-all-lots', '<html>Old</html>', $all, ['element::craft\elements\Entry::*'], null);
            $this->plugin()->clearer->clearTags(['element::craft\elements\Entry::*'], 'Saved');
            $this->assertSame([[$all, 0]], $pushed, '0 turns the wait off.');
        } finally {
            \Craft::$app->getQueue()->off(\yii\queue\Queue::EVENT_BEFORE_PUSH, $queue);
            $cache->delete(\suprim\speedy\services\Clearer::REBUILD_LATER . md5($all));
            $storage->delete('example.com/speedy-test-all-lots');
            $storage->delete('example.com/speedy-test-lot');
        }
    }

    public function testClearingASectionClearsItsListingsAndEntries(): void
    {
        $entry = \craft\elements\Entry::find()->status(null)->site('*')->one();
        if (!$entry) {
            $this->markTestSkipped('Needs an entry.');
        }
        $section = $entry->getSection();
        $type = $section->getEntryTypes()[0];
        $class = \craft\elements\Entry::class;
        $storage = $this->plugin()->storage;
        $pages = [
            'example.com/speedy-test-listing' => ["element::$class::section:$section->id"],
            'example.com/speedy-test-type-listing' => ["element::$class::entryType:$type->id"],
            'example.com/speedy-test-entry' => ["element::$entry->id"],
            'example.com/speedy-test-other' => ['element::987654321'],
        ];
        try {
            foreach ($pages as $key => $tags) {
                $storage->write($key, '<html>Old</html>', 'https://' . $key, $tags, null);
            }
            $this->assertSame(3, $this->plugin()->clearer->clearSection($section->id));
            $this->assertFalse($storage->exists('example.com/speedy-test-listing'));
            $this->assertFalse($storage->exists('example.com/speedy-test-type-listing'), 'A listing of one of its entry types.');
            $this->assertFalse($storage->exists('example.com/speedy-test-entry'));
            $this->assertTrue($storage->exists('example.com/speedy-test-other'), 'Other pages are left.');
            $this->assertStringStartsWith("Cleared the $section->name section", $this->plugin()->log->recent(1)[0]['reason']);
            $this->assertNull($this->plugin()->clearer->clearSection(987654321), 'No such section.');
        } finally {
            foreach (array_keys($pages) as $key) {
                $storage->delete($key);
            }
        }
    }
}
