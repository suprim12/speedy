<?php

namespace suprim\speedy\tests\Http;

use Craft;
use craft\elements\Entry;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use suprim\speedy\Plugin;

/**
 * An entry type added to Never cache: the copy cached before is removed when the settings are saved, and its pages
 * aren’t cached again. Needs SPEEDY_TEST_URL and an entry type with none of its pages cached; it removes only the
 * copy it made, and puts the saved settings back.
 */
class NeverCacheEntryTypesTest extends TestCase
{
    use KeepsSettings;

    private static string $base;
    private static ?Entry $entry = null;
    private static string $key = '';

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
        foreach (Entry::find()->uri(':notempty:')->status('live')->limit(50)->all() as $entry) {
            $url = (string)$entry->getUrl();
            if (!str_starts_with($url, self::$base . '/')) {
                continue;
            }
            // None of its entry type's pages cached, so the only copy removed is this test's.
            $urls = array_filter(array_map(fn($e) => $e->getUrl(), Entry::find()->type($entry->getType()->handle)->site('*')->status(null)->all()));
            if (array_filter($urls, fn($u) => $storage->exists((string)$storage->keyForUrl((string)$u)))) {
                continue;
            }
            try {
                // Its copy, if this caches it, is the test's own.
                if ($client->head($url)->getStatusCode() === 200) {
                    self::$entry = $entry;
                    self::$key = (string)$storage->keyForUrl($url);
                    break;
                }
            } catch (\Throwable) {
            }
        }
        if (!self::$entry) {
            self::markTestSkipped('No live entry whose page loads, of an entry type with none of its pages cached.');
        }
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
}
