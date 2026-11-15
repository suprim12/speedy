<?php

namespace suprim\speedy\tests\Integration;

use Craft;
use suprim\speedy\models\Settings;
use suprim\speedy\services\Storage;
use suprim\speedy\tests\TestCase;
use yii\caching\ArrayCache;

/**
 * Storage in Craft’s cache, using an in-memory cache so the site’s real cache isn’t touched.
 */
class CacheStoreTest extends TestCase
{
    private mixed $realCache;
    private Storage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->realCache = Craft::$app->get('cache');
        Craft::$app->set('cache', new ArrayCache());
        $this->settings()->storage = Settings::STORAGE_CRAFT_CACHE;
        $this->storage = new Storage();
    }

    protected function tearDown(): void
    {
        Craft::$app->set('cache', $this->realCache);
        parent::tearDown();
    }

    public function testStoresPagesCompressedAndReadsBothWays(): void
    {
        $this->storage->write('example.com/news', '<html>News</html>', 'https://example.com/news', ['element::1'], null);

        $this->assertTrue($this->storage->exists('example.com/news'));
        $this->assertDirectoryDoesNotExist("$this->tmp/web", 'Nothing is written to disk.');
        $this->assertSame('<html>News</html>', $this->storage->read('example.com/news', false)['content']);
        $this->assertSame('<html>News</html>', gzdecode($this->storage->read('example.com/news', true)['content']));
        $this->assertSame(1, $this->storage->stats()['pages']);
        $this->assertSame(['example.com/news'], iterator_to_array($this->storage->allKeys(), false));
    }

    public function testTagsAndExpiry(): void
    {
        $now = time();
        $this->storage->write('example.com/a', 'a', 'https://example.com/a', ['section:news'], $now - 5);
        $this->storage->write('example.com/b', 'b', 'https://example.com/b', ['section:news'], null);

        $this->assertSame(2, $this->storage->countPagesForTag('section:news'));
        $this->assertSame(['example.com/a'], $this->storage->takeExpired($now));
        $this->assertEqualsCanonicalizing(['example.com/a', 'example.com/b'], $this->storage->takePagesForTags(['section:news']));
        $this->assertSame([], $this->storage->takePagesForTags(['section:news']));
    }

    public function testDeleteAndClearAll(): void
    {
        $this->storage->write('example.com/a', 'a', 'https://example.com/a', [], null);
        $this->storage->write('example.com/b', 'b', 'https://example.com/b', [], null);
        $this->storage->delete('example.com/a');
        $this->assertSame(1, $this->storage->stats()['pages']);

        $this->storage->clearAll();
        $this->assertFalse($this->storage->exists('example.com/b'));
        $this->assertSame(0, $this->storage->stats()['pages']);
    }

    public function testSharedDataAndLog(): void
    {
        $this->storage->writeData('cdn', ['detected' => 'cloudflare']);
        $this->assertSame('cloudflare', $this->storage->readData('cdn')['detected']);
        $this->assertSame([], $this->storage->readData('missing'));

        $this->storage->cache()->addLog(['time' => 1, 'reason' => 'first', 'count' => 1, 'urls' => [], 'cdn' => null]);
        $this->storage->cache()->addLog(['time' => 2, 'reason' => 'second', 'count' => 1, 'urls' => [], 'cdn' => null]);
        $this->assertSame(['second', 'first'], array_column($this->storage->cache()->recentLog(10), 'reason'));
    }
}
