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
}
