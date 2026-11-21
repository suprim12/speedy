<?php

namespace suprim\speedy\tests\Integration;

use suprim\speedy\tests\TestCase;

class StorageKeyTest extends TestCase
{
    public function testBuildsKeysFromHostAndPath(): void
    {
        $storage = $this->plugin()->storage;
        $this->assertSame('example.com', $storage->key('Example.COM', '/'));
        $this->assertSame('example.com/news/summer-sale', $storage->key('example.com', '/news/summer-sale/'));
        $this->assertSame('localhost:8080/a', $storage->key('localhost:8080', 'a'));
    }

    /**
     * Keys become folder names, so anything that could escape the cache folder is refused.
     */
    public function testRefusesUnsafeKeys(): void
    {
        $storage = $this->plugin()->storage;
        $this->assertNull($storage->key('example.com', '/../etc/passwd'));
        $this->assertNull($storage->key('example.com', '/a/./b'));
        $this->assertNull($storage->key('example.com', "/a\0b"));
        $this->assertNull($storage->key('example.com', '/a\\b'));
        $this->assertNull($storage->key('example.com', '/a//b'));
        $this->assertNull($storage->key('evil.com/../x', '/'));
        $this->assertNull($storage->key('example.com', '/' . str_repeat('a', 401)));
    }

    /**
     * Each part of a key is a folder, and the page’s data file is named after its last part: a part too long for the
     * file system would fail on every visit, so it isn’t cached.
     */
    public function testPartsTooLongForAFileNameAreRefused(): void
    {
        $storage = $this->plugin()->storage;
        $this->assertNotNull($storage->key('example.com', 'a/' . str_repeat('x', 200)));
        $this->assertNull($storage->key('example.com', 'a/' . str_repeat('x', 201)));
        $this->assertNull($storage->key('example.com', 'p', ['q' => str_repeat('%', 100)]), 'Its query folder, once encoded, too.');

        // The longest that's allowed can be saved, and found again.
        foreach ([(string)$storage->key('example.com', 'a/' . str_repeat('x', 200)), (string)$storage->key('example.com', 'p', ['q' => str_repeat('y', 190)])] as $key) {
            $storage->write($key, '<html></html>', 'https://example.com/', ['element::1'], null);
            $this->assertTrue($storage->exists($key), $key);
        }
    }

    public function testQueryStringsMakeTheirOwnPage(): void
    {
        $storage = $this->plugin()->storage;
        $this->assertSame('example.com/news/~query/page%3D2', $storage->key('example.com', '/news', ['page' => '2']));
        $this->assertSame(
            $storage->key('example.com', '/news', ['page' => '2', 'category' => 'a']),
            $storage->key('example.com', '/news', ['category' => 'a', 'page' => '2']),
            'The order of parameters doesn’t matter.'
        );
        $this->assertSame($storage->key('example.com', '/news', ['page' => '2']), $storage->keyForUrl('https://example.com/news?page=2&speedy-warm=ab12'));
        $this->assertNull($storage->key('example.com', '/news', ['q' => str_repeat('a', 201)]));
        $this->assertNull($storage->key('example.com', '/news/~query/page%3D2'), 'A real URL can’t reach a query page’s folder.');
    }
}
