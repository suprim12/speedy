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
}
