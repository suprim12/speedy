<?php

namespace suprim\speedy\tests\Integration;

use Craft;
use suprim\speedy\models\Settings;
use suprim\speedy\services\Storage;
use suprim\speedy\tests\TestCase;

/**
 * With Craft’s cache in Redis, tags are Redis sets: added and taken in one step, and never lost between servers.
 */
class RedisTagsTest extends TestCase
{
    private Storage $storage;

    /** @var string[] */
    private array $keys = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(\yii\redis\Cache::class) || !Craft::$app->getCache() instanceof \yii\redis\Cache) {
            $this->markTestSkipped('Craft’s cache isn’t Redis here.');
        }
        $this->settings()->storage = Settings::STORAGE_CRAFT_CACHE;
        $this->storage = new Storage();
    }

    protected function tearDown(): void
    {
        if (isset($this->storage)) {
            // Only what this test wrote.
            $this->storage->takePagesForTags(['speedy-test-tag', 'speedy-test-other']);
            foreach ($this->keys as $key) {
                $this->storage->delete($key);
            }
        }
        parent::tearDown();
    }

    private function write(string $key, array $tags): void
    {
        $this->keys[] = $key;
        $this->storage->write($key, "<html>$key</html>", "https://$key", $tags, null);
    }

    public function testTagsAreTakenWholeAndOnce(): void
    {
        $this->write('speedy-test.example/a', ['speedy-test-tag']);
        $this->write('speedy-test.example/b', ['speedy-test-tag', 'speedy-test-other']);
        $this->assertSame(2, $this->storage->countPagesForTag('speedy-test-tag'));

        $taken = $this->storage->takePagesForTags(['speedy-test-tag']);
        sort($taken);
        $this->assertSame(['speedy-test.example/a', 'speedy-test.example/b'], $taken);
        $this->assertSame([], $this->storage->takePagesForTags(['speedy-test-tag']), 'Taken once.');

        // A page added after the take is there for the next one.
        $this->write('speedy-test.example/c', ['speedy-test-tag']);
        $this->assertSame(['speedy-test.example/c'], $this->storage->takePagesForTags(['speedy-test-tag']));
        $this->assertSame(['speedy-test.example/b'], $this->storage->takePagesForTags(['speedy-test-other']));
    }

    public function testListsFromBeforeTheUpgradeStillClear(): void
    {
        // How 1.0 kept a tag’s pages: one array in the cache.
        $gen = (int)(Craft::$app->getCache()->get('speedy:gen') ?: 1);
        Craft::$app->getCache()->set("speedy:$gen:tag:" . md5('speedy-test-tag'), ['speedy-test.example/old' => true]);
        $this->write('speedy-test.example/new', ['speedy-test-tag']);

        $taken = $this->storage->takePagesForTags(['speedy-test-tag']);
        sort($taken);
        $this->assertSame(['speedy-test.example/new', 'speedy-test.example/old'], $taken);
        $this->assertFalse(Craft::$app->getCache()->exists("speedy:$gen:tag:" . md5('speedy-test-tag')), 'The old list is gone once taken.');
    }

    public function testSavingStaysFastWithManyPagesOnOneTag(): void
    {
        $start = microtime(true);
        for ($i = 0; $i < 300; $i++) {
            $this->write("speedy-test.example/p$i", ['speedy-test-tag']);
        }
        $first = microtime(true) - $start;
        $start = microtime(true);
        for ($i = 300; $i < 600; $i++) {
            $this->write("speedy-test.example/p$i", ['speedy-test-tag']);
        }
        $second = microtime(true) - $start;
        $this->assertSame(600, count($this->storage->takePagesForTags(['speedy-test-tag'])));
        $this->assertLessThan($first * 3, $second, 'The second 300 cost about the same as the first.');
    }
}
