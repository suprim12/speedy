<?php

namespace suprim\speedy\tests\Http;

use Craft;
use craft\elements\Entry;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use suprim\speedy\Plugin;

/**
 * A Craft install with several sites: one in a folder of another’s domain (such as /de), and one on a domain of its
 * own. Each site’s pages are cached, cleared and left out on their own.
 *
 * Needs SPEEDY_TEST_URL and SPEEDY_TEST_MULTISITE=1, on a test install only: it clears whole sites’ cached pages.
 * Uses the primary site, a site whose base URL is a folder of the primary site’s, and one on another domain, each with
 * an entry whose page loads.
 */
class MultiSiteTest extends TestCase
{
    use KeepsSettings;

    private static \craft\models\Site $main;
    private static \craft\models\Site $folder;
    private static \craft\models\Site $other;

    public static function setUpBeforeClass(): void
    {
        if (!getenv('SPEEDY_TEST_URL') || !getenv('SPEEDY_TEST_MULTISITE')) {
            self::markTestSkipped('Set SPEEDY_TEST_URL and SPEEDY_TEST_MULTISITE=1 on a multi-site test install to run these tests.');
        }
        $main = Craft::$app->getSites()->getPrimarySite();
        $mainUrl = rtrim((string)$main->getBaseUrl(), '/');
        $folder = $other = null;
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $url = rtrim((string)$site->getBaseUrl(), '/');
            if ($site->id === $main->id || !$site->hasUrls || !$url) {
                continue;
            }
            if (str_starts_with($url, $mainUrl . '/')) {
                $folder ??= $site;
            } elseif (parse_url($url, PHP_URL_HOST) !== parse_url($mainUrl, PHP_URL_HOST)) {
                $other ??= $site;
            }
        }
        if (!$folder || !$other) {
            self::markTestSkipped('Needs a site in a folder of the primary site’s domain, and one on another domain.');
        }
        [self::$main, self::$folder, self::$other] = [$main, $folder, $other];
        self::keepSettings();
        // Removed straight away rather than rebuilt, so the tests can see what was cleared.
        self::changeSettings(['rebuildCleared' => false, 'buildSavedPages' => false, 'neverCacheSites' => []]);
    }

    public static function tearDownAfterClass(): void
    {
        self::putSettingsBack();
    }

    private function get(string $url): ResponseInterface
    {
        return (new Client(['http_errors' => false, 'verify' => false, 'cookies' => false, 'allow_redirects' => false]))->get($url);
    }

    /**
     * Requests a page until it’s sent from the cache.
     */
    private function cache(string $url): ResponseInterface
    {
        $this->get($url);
        $response = $this->get($url);
        $this->assertContains($response->getHeaderLine('X-Speedy'), ['HIT', 'STATIC'], "$url is cached.");

        return $response;
    }

    private function home(\craft\models\Site $site): string
    {
        return rtrim((string)$site->getBaseUrl(), '/') . '/';
    }

    private function cached(string $url): bool
    {
        $storage = Plugin::getInstance()->storage;

        return $storage->exists((string)$storage->keyForUrl($url));
    }

    public function testEachSiteHasItsOwnCopies(): void
    {
        $pages = [];
        foreach ([self::$main, self::$folder, self::$other] as $site) {
            $pages[$site->handle] = (string)$this->cache($this->home($site))->getBody();
        }
        $this->assertCount(3, array_unique($pages), 'Three different pages, not one copy shared.');

        // A page of the primary site that starts like the folder, such as /delta for /de, is the primary site’s.
        $clearer = Plugin::getInstance()->clearer;
        $host = (string)parse_url($this->home(self::$main), PHP_URL_HOST);
        $folderPath = trim((string)parse_url($this->home(self::$folder), PHP_URL_PATH), '/');
        $this->assertSame(self::$main->id, $clearer->siteForKey("$host/{$folderPath}lta")['id'] ?? null);
        $this->assertSame(self::$folder->id, $clearer->siteForKey("$host/$folderPath/news")['id'] ?? null);
        $this->assertSame(self::$other->id, $clearer->siteForKey((string)parse_url($this->home(self::$other), PHP_URL_HOST) . '/news')['id'] ?? null);
    }

    public function testClearSiteClearsOnlyThatSite(): void
    {
        foreach ([self::$main, self::$folder, self::$other] as $site) {
            $this->cache($this->home($site));
        }
        Plugin::getInstance()->clearer->clearSites([self::$folder->id]);
        $this->assertFalse($this->cached($this->home(self::$folder)), 'The site in a folder is cleared.');
        $this->assertTrue($this->cached($this->home(self::$main)), 'Not the site whose domain it shares.');
        $this->assertTrue($this->cached($this->home(self::$other)));
    }

    public function testSavingAnEntryInOneSiteClearsItsPagesThere(): void
    {
        // An entry the site's homepage shows, by the homepage's own tags.
        $home = $this->home(self::$folder);
        $this->cache($home);
        $storage = Plugin::getInstance()->storage;
        $ids = array_map(fn($tag) => (int)substr($tag, 9), preg_grep('/^element::\d+$/', $storage->meta((string)$storage->keyForUrl($home))['tags'] ?? []));
        $entry = $ids ? Entry::find()->id($ids)->site(self::$folder)->uri(':notempty:')->status('live')->one() : null;
        if (!$entry) {
            $this->markTestSkipped('The homepage of the site in a folder shows no entry with a page.');
        }
        $url = (string)$entry->getUrl();
        $this->cache($url);
        $mainUrl = (string)Entry::find()->id($entry->id)->site(self::$main)->one()?->getUrl();
        if ($mainUrl !== '') {
            $this->cache($mainUrl);
        }
        $title = (string)$entry->title;
        $entry->title = "$title (changed)";
        Craft::$app->getElements()->saveElement($entry);
        Craft::$app->getQueue()->run();
        try {
            $this->assertFalse($this->cached($url), 'Its page.');
            $this->assertFalse($this->cached($home), 'And the page that lists it in that site.');
        } finally {
            $entry->title = $title;
            Craft::$app->getElements()->saveElement($entry);
            Craft::$app->getQueue()->run();
        }
    }

    public function testNeverCacheSitesLeavesOutOnlyThatSite(): void
    {
        foreach ([self::$main, self::$other] as $site) {
            $this->cache($this->home($site));
        }
        try {
            self::changeSettings(['neverCacheSites' => [self::$other->handle]]);
            $this->assertFalse($this->cached($this->home(self::$other)), 'Its cached pages are removed.');
            $this->assertTrue($this->cached($this->home(self::$main)), 'Other sites’ pages stay.');
            $this->assertSame('SKIP never cache site', $this->get($this->home(self::$other))->getHeaderLine('X-Speedy'));
            $this->assertFalse($this->cached($this->home(self::$other)));
            $this->assertContains($this->get($this->home(self::$main))->getHeaderLine('X-Speedy'), ['HIT', 'STATIC']);

            // The site in a folder, added too: links to it on the primary site’s pages aren’t fetched ahead.
            self::changeSettings(['neverCacheSites' => [self::$folder->handle], 'instantLinks' => true]);
            Plugin::getInstance()->clearer->clearSites([self::$main->id]);
            $html = (string)$this->cache($this->home(self::$main))->getBody();
            $folderPath = trim((string)parse_url($this->home(self::$folder), PHP_URL_PATH), '/');
            $this->assertStringContainsString("\"/$folderPath/*\"", $html, 'Instant links leave out the site that isn’t cached.');
        } finally {
            self::changeSettings(['neverCacheSites' => [], 'instantLinks' => false]);
        }
    }

    public function testCoverageCountsEverySite(): void
    {
        $total = (int)Entry::find()->site('*')->uri(':notempty:')->status(null)->count() + (int)\craft\elements\Category::find()->site('*')->uri(':notempty:')->status(null)->count();
        $coverage = Plugin::getInstance()->insights->coverage(true);
        $this->assertSame($total, $coverage['total'] + $coverage['never'] + $coverage['redirects'] + array_sum($coverage['noTemplate']), 'Every site’s entries and categories are counted.');
    }

    public function testCloudflareClearsEachSiteByItsOwnPart(): void
    {
        $clearer = Plugin::getInstance()->clearer;
        $parts = (new \ReflectionMethod($clearer, 'cloudflareSiteParts'))->invoke($clearer, [self::$folder, self::$other]);
        $folder = (string)parse_url($this->home(self::$folder), PHP_URL_HOST) . '/' . trim((string)parse_url($this->home(self::$folder), PHP_URL_PATH), '/');
        $this->assertSame(['prefixes' => [$folder], 'hosts' => [(string)parse_url($this->home(self::$other), PHP_URL_HOST)]], $parts);
    }
}
