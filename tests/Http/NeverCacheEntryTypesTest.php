<?php

namespace suprim\speedy\tests\Http;

use Craft;
use craft\elements\Entry;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use suprim\speedy\Plugin;

/**
 * An entry type added to Never cache: the copy cached before is removed when the settings are saved, and its pages
 * aren’t cached again, and the same for a Never cache pattern. Needs SPEEDY_TEST_URL and a live entry whose page isn’t
 * cached; the entry type test also needs none of its type’s pages cached. It removes only the copy it made, and puts
 * the saved settings back.
 */
class NeverCacheEntryTypesTest extends TestCase
{
    use KeepsSettings;

    private static string $base;
    private static ?Entry $entry = null;
    private static string $key = '';

    /** Whether none of the entry's type's other pages are cached, so the entry type can be added safely. */
    private static bool $typeClean = false;

    public static function setUpBeforeClass(): void
    {
        $base = getenv('SPEEDY_TEST_URL');
        if (!$base) {
            self::markTestSkipped('Set SPEEDY_TEST_URL to run the http tests.');
        }
        if (Craft::$app->getPlugins()->getPlugin('blitz')) {
            self::markTestSkipped('Another page cache (Blitz) is enabled, so pages are marked uncacheable. Disable it to run these tests.');
        }
        self::$base = rtrim($base, '/');
        $storage = Plugin::getInstance()->storage;
        $client = new Client(['http_errors' => false, 'verify' => false, 'cookies' => false, 'allow_redirects' => false]);
        $fallback = null;
        foreach (Entry::find()->uri(':notempty:')->status('live')->limit(50)->all() as $entry) {
            $url = (string)$entry->getUrl();
            // A page this test can cache itself, with no request until the test’s own.
            if (!str_starts_with($url, self::$base . '/') || $storage->exists((string)$storage->keyForUrl($url))
                || Plugin::getInstance()->insights->templateMissing(Entry::class, (int)$entry->sectionId, (int)$entry->siteId)) {
                continue;
            }
            // Whether its page loads: asked as an Ajax request, which Speedy never caches, so nothing is saved.
            try {
                if ($client->get($url, ['headers' => ['X-Requested-With' => 'XMLHttpRequest']])->getStatusCode() !== 200) {
                    continue;
                }
            } catch (\Throwable) {
                continue;
            }
            // Best with none of its entry type's pages cached, so adding the type removes only this test's copy.
            $urls = array_filter(array_map(fn($e) => $e->getUrl(), Entry::find()->type($entry->getType()->handle)->site('*')->status(null)->all()));
            if (!array_filter($urls, fn($u) => $u !== $url && $storage->exists((string)$storage->keyForUrl((string)$u)))) {
                self::$entry = $entry;
                self::$typeClean = true;
                break;
            }
            $fallback ??= $entry;
        }
        self::$entry ??= $fallback;
        if (!self::$entry) {
            self::markTestSkipped('No live entry whose page loads and isn’t cached yet.');
        }
        self::$key = (string)$storage->keyForUrl((string)self::$entry->getUrl());
        self::keepSettings();
    }

    public static function tearDownAfterClass(): void
    {
        self::putSettingsBack();
        if (self::$entry) {
            // Back to what the cached pages were built with.
            Plugin::getInstance()->checkNeverCache();
            $storage = Plugin::getInstance()->storage;
            if (self::$key !== '' && $storage->exists(self::$key)) {
                $storage->delete(self::$key);
            }
        }
        self::$entry = null;
    }

    public function testAddingAnEntryTypeRemovesItsPagesAndStopsCachingThem(): void
    {
        if (!self::$typeClean) {
            $this->markTestSkipped('Every entry type has pages cached already, which adding one would remove.');
        }
        $client = new Client(['http_errors' => false, 'verify' => false, 'cookies' => false, 'allow_redirects' => false]);
        $url = (string)self::$entry->getUrl();
        $storage = Plugin::getInstance()->storage;
        $client->get($url);
        $this->assertTrue($storage->exists(self::$key), 'Cached first.');

        self::changeSettings(['neverCacheEntryTypes' => [self::$entry->getType()->handle]]);
        $this->assertFalse($storage->exists(self::$key), 'Removed when the settings are saved, as the web server would keep sending it.');

        foreach ([1, 2] as $visit) {
            $response = $client->get($url);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('SKIP never cache entry type', $response->getHeaderLine('X-Speedy'), "Visit $visit.");
        }
        $this->assertFalse($storage->exists(self::$key));
    }

    public function testAddingANeverCachePatternRemovesThePage(): void
    {
        $client = new Client(['http_errors' => false, 'verify' => false, 'cookies' => false, 'allow_redirects' => false]);
        $url = (string)self::$entry->getUrl();
        $storage = Plugin::getInstance()->storage;
        self::changeSettings(['neverCacheEntryTypes' => []]);
        $client->get($url);
        $this->assertTrue($storage->exists(self::$key), 'Cached first.');

        $uri = self::$entry->uri === '__home__' ? '/' : (string)self::$entry->uri;
        self::changeSettings(['neverCache' => [$uri]]);
        $this->assertFalse($storage->exists(self::$key), 'Removed when the pattern is saved, as the web server would keep sending it.');
        $this->assertSame('SKIP never cache', $client->get($url)->getHeaderLine('X-Speedy'));
    }
}
