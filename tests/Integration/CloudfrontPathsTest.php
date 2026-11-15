<?php

namespace suprim\speedy\tests\Integration;

use suprim\speedy\tests\TestCase;

class CloudfrontPathsTest extends TestCase
{
    public function testSendsOnePathPerPageUnderTheThreshold(): void
    {
        $paths = $this->plugin()->cdn->cloudfrontPaths(['https://example.com/news/a', 'https://example.com/news/a', 'https://example.com/']);
        $this->assertSame(['/news/a', '/'], $paths);
    }

    public function testGroupsIntoWildcardsOverTheThreshold(): void
    {
        $this->settings()->wildcardThreshold = 3;
        $urls = array_merge(
            array_map(fn($i) => "https://example.com/news/$i", range(1, 5)),
            ['https://example.com/about', 'https://example.com/'],
        );

        $this->assertEqualsCanonicalizing(['/news*', '/about', '/'], $this->plugin()->cdn->cloudfrontPaths($urls));
    }

    public function testFallsBackToEverythingWhenStillTooMany(): void
    {
        $this->settings()->wildcardThreshold = 2;
        $urls = ['https://example.com/a/1', 'https://example.com/b/1', 'https://example.com/c/1'];

        $this->assertSame(['/*'], $this->plugin()->cdn->cloudfrontPaths($urls));
    }
}
