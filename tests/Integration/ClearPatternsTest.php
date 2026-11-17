<?php

namespace suprim\speedy\tests\Integration;

use Craft;
use suprim\speedy\services\Clearer;
use suprim\speedy\tests\TestCase;

/**
 * Clearing every page that matches a pattern, or every page of a site.
 */
class ClearPatternsTest extends TestCase
{
    /** @var string[] */
    private const PAGES = [
        'example.com' => 'https://example.com/',
        'example.com/news' => 'https://example.com/news',
        'example.com/news/a' => 'https://example.com/news/a',
        'example.com/news/a/~query/page%3D2' => 'https://example.com/news/a?page=2',
        'example.com/newsletter' => 'https://example.com/newsletter',
        'example.com/de/news/b' => 'https://example.com/de/news/b',
        'example.org/news/c' => 'https://example.org/news/c',
    ];

    private function write(): void
    {
        $storage = $this->plugin()->storage;
        foreach (self::PAGES as $key => $url) {
            $storage->write($key, '<html></html>', $url, [], null);
        }
    }

    /**
     * @return string[] The pages still cached.
     */
    private function left(): array
    {
        return array_values(array_filter(array_keys(self::PAGES), fn($key) => $this->plugin()->storage->exists($key)));
    }

    public function testPatterns(): void
    {
        $this->assertNull(Clearer::patternRegex(''));
        $this->assertNull(Clearer::patternRegex('javascript:*'), 'Only web addresses and paths.');
        $this->assertNull(Clearer::patternRegex('/' . str_repeat('a', 600) . '*'));
        $this->assertNull(Clearer::patternRegex('/' . str_repeat('a*', 11)), 'At most 10 stars.');
        $this->assertNotNull(Clearer::patternRegex('/a***b'), 'Stars in a row count as one.');
        $this->assertSame(1, preg_match((string)Clearer::patternRegex('/news/*'), 'any.host/news/x/y'));
        $this->assertSame(0, preg_match((string)Clearer::patternRegex('/news/*'), 'any.host/news'), 'news/* is what’s below /news.');
        $this->assertSame(1, preg_match((string)Clearer::patternRegex('https://*.example.com/*'), 'www.example.com/a'));
        $this->assertSame(0, preg_match((string)Clearer::patternRegex('/a.b*'), 'h/axb'), 'Only * is special.');
        $this->assertSame(1, preg_match((string)Clearer::patternRegex('/caf%C3%A9*'), 'h/café/x'), 'Encoded paths match.');
    }

    public function testClearsMatchingPagesWithTheirQueryVersions(): void
    {
        $this->write();
        $this->assertSame(2, $this->plugin()->clearer->clearUrl('https://example.com/news/*'));
        $this->assertSame(['example.com', 'example.com/news', 'example.com/newsletter', 'example.com/de/news/b', 'example.org/news/c'], $this->left());
        $this->assertSame('Cleared https://example.com/news/*', $this->plugin()->log->recent(1)[0]['reason']);
    }

    public function testAPathMatchesEveryDomain(): void
    {
        $this->write();
        $this->assertSame(5, $this->plugin()->clearer->clearMatching('/news*'), 'news, news/a and its page 2, newsletter, and news/c on example.org.');
        $this->assertSame(['example.com', 'example.com/de/news/b'], $this->left());
    }

    public function testEverythingOnADomain(): void
    {
        $this->write();
        $this->plugin()->clearer->clearMatching('https://example.org/*');
        $this->assertNotContains('example.org/news/c', $this->left());
        $this->assertContains('example.com/news', $this->left());
    }

    public function testSitesOwnTheirPathsAndTheLongestBaseWins(): void
    {
        $clearer = $this->plugin()->clearer;
        (fn() => $this->_siteBases = [
            ['id' => 2, 'host' => 'example.com', 'path' => 'de'],
            ['id' => 1, 'host' => 'example.com', 'path' => ''],
            ['id' => 3, 'host' => null, 'path' => 'shop'],
        ])->call($clearer);
        try {
            $this->assertSame(['id' => 2, 'path' => 'news/b'], $clearer->siteForKey('example.com/de/news/b'));
            $this->assertSame(['id' => 2, 'path' => ''], $clearer->siteForKey('example.com/de'));
            $this->assertSame(['id' => 1, 'path' => 'dealers'], $clearer->siteForKey('example.com/dealers'), 'Not the /de site.');
            $this->assertSame(['id' => 1, 'path' => ''], $clearer->siteForKey('example.com/~query/page%3D2'));
            $this->assertSame(['id' => 3, 'path' => 'a'], $clearer->siteForKey('anything.test/shop/a'), 'A site at @web/shop on any domain.');
            $this->assertNull($clearer->siteForKey('other.org/a'));
        } finally {
            (fn() => $this->_siteBases = null)->call($clearer);
        }
    }

    public function testClearsOneSite(): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $host = (string)parse_url((string)$site->getBaseUrl(), PHP_URL_HOST);
        $storage = $this->plugin()->storage;
        $storage->write("$host/speedy-site-test", '<html></html>', "https://$host/speedy-site-test", [], null);
        $storage->write('other.example/a', '<html></html>', 'https://other.example/a', [], null);

        $this->assertSame(1, $this->plugin()->clearer->clearSite((int)$site->id));
        $this->assertFalse($storage->exists("$host/speedy-site-test"));
        $this->assertTrue($storage->exists('other.example/a'), 'Pages of other domains are kept.');
        $this->assertNull($this->plugin()->clearer->clearSite(999999));
    }
}
