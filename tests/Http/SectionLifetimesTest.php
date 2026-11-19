<?php

namespace suprim\speedy\tests\Http;

use Craft;
use craft\elements\Entry;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use suprim\speedy\Plugin;

/**
 * Lifetimes by section: the page of an entry in a section with a lifetime expires after it. Needs SPEEDY_TEST_URL and
 * a live entry whose page loads and isn’t cached yet. Puts the setting back and removes only the copy it made.
 */
class SectionLifetimesTest extends TestCase
{
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
        $client = new Client(['http_errors' => false, 'verify' => false, 'cookies' => false, 'allow_redirects' => false]);
        $storage = Plugin::getInstance()->storage;
        foreach (Entry::find()->uri(':notempty:')->status('live')->limit(30)->all() as $entry) {
            $url = (string)$entry->getUrl();
            if (!str_starts_with($url, self::$base . '/') || $storage->exists((string)$storage->keyForUrl($url))) {
                continue;
            }
            // Opened with a query string Speedy doesn’t cache, so finding one doesn’t cache it.
            try {
                if ($client->get($url . '?speedy-test-probe=1')->getStatusCode() === 200) {
                    self::$entry = $entry;
                    self::$key = (string)$storage->keyForUrl($url);
                    break;
                }
            } catch (\Throwable) {
            }
        }
        if (!self::$entry) {
            self::markTestSkipped('No live entry whose page loads and isn’t cached yet.');
        }
    }

    public static function tearDownAfterClass(): void
    {
        $storage = Plugin::getInstance()->storage;
        if (self::$key !== '' && $storage->exists(self::$key)) {
            $storage->delete(self::$key);
        }
        self::$key = '';
        self::$entry = null;
    }

    public function testAnEntrysPageExpiresAfterItsSectionsLifetime(): void
    {
        $plugin = Plugin::getInstance();
        $before = $plugin->getSettings()->sectionLifetimes;
        $handle = (string)self::$entry->getSection()->handle;
        $this->assertTrue(Craft::$app->getPlugins()->savePluginSettings($plugin, ['sectionLifetimes' => [$handle => 300]]));
        // Craft saves project config changes at the end of a request: now, so the site sees it.
        Craft::$app->getProjectConfig()->saveModifiedConfigData();
        try {
            $client = new Client(['http_errors' => false, 'verify' => false, 'cookies' => false, 'allow_redirects' => false]);
            $start = time();
            $client->get((string)self::$entry->getUrl());
            $expires = $plugin->storage->meta(self::$key)['expires'] ?? null;
            $this->assertNotNull($expires, 'Cached with an expiry.');
            $this->assertGreaterThanOrEqual($start + 300 - 2, $expires);
            $this->assertLessThanOrEqual(time() + 300, $expires, 'No later than the lifetime.');
        } finally {
            Craft::$app->getPlugins()->savePluginSettings($plugin, ['sectionLifetimes' => $before]);
            Craft::$app->getProjectConfig()->saveModifiedConfigData();
        }
        $this->assertSame($before, $plugin->getSettings()->sectionLifetimes, 'Put back as it was.');
    }
}
