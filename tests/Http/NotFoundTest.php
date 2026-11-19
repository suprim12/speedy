<?php

namespace suprim\speedy\tests\Http;

use Craft;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use suprim\speedy\Plugin;
use suprim\speedy\services\Cacher;

/**
 * “Not found” pages kept for a while with `cacheNotFound`, so bots asking for missing addresses don’t load the server.
 * Needs SPEEDY_TEST_URL. Turns the setting on for the tests and puts the saved settings back afterwards.
 */
class NotFoundTest extends TestCase
{
    use KeepsSettings;

    private static string $base;

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
        self::keepSettings();
        self::changeSettings(['cacheNotFound' => 300]);
    }

    public static function tearDownAfterClass(): void
    {
        Cacher::forgetNotFound();
        self::putSettingsBack();
    }

    private function get(string $path): ResponseInterface
    {
        return (new Client(['http_errors' => false, 'verify' => false, 'cookies' => false, 'allow_redirects' => false]))->get(self::$base . $path);
    }

    private function missing(): string
    {
        return '/speedy-test-missing-' . bin2hex(random_bytes(4));
    }

    public function testTheNotFoundPageIsKeptAndSentWithItsStatus(): void
    {
        $path = $this->missing();
        $first = $this->get($path);
        $this->assertSame(404, $first->getStatusCode());
        $this->assertSame('MISS 404', $first->getHeaderLine('X-Speedy'));

        $second = $this->get($path);
        $this->assertSame(404, $second->getStatusCode(), 'Still a 404, so search engines don’t take it for a page.');
        $this->assertSame('HIT 404', $second->getHeaderLine('X-Speedy'));
        $this->assertSame((string)$first->getBody(), (string)$second->getBody());
        $this->assertStringContainsString('no-cache', $second->getHeaderLine('Cache-Control'), 'CDNs don’t keep it.');

        $storage = Plugin::getInstance()->storage;
        $this->assertFalse($storage->exists((string)$storage->key((string)parse_url(self::$base, PHP_URL_HOST), $path)), 'Never in the cache folder, where the web server would send it with 200.');
    }

    public function testChangedContentForgetsThem(): void
    {
        $path = $this->missing();
        $this->get($path);
        $this->assertSame('HIT 404', $this->get($path)->getHeaderLine('X-Speedy'));

        Cacher::forgetNotFound();
        $this->assertSame('MISS 404', $this->get($path)->getHeaderLine('X-Speedy'), 'The address may have a page now.');

        // Saving content forgets them, through the save event.
        $entry = \craft\elements\Entry::find()->status(null)->one();
        if ($entry) {
            $set = Craft::$app->getCache()->get('speedy:notFoundSet');
            // Without noting the entry's page to build, which this test doesn't want.
            $settings = Plugin::getInstance()->getSettings();
            $build = $settings->buildSavedPages;
            $settings->buildSavedPages = false;
            try {
                Plugin::getInstance()->clearer->afterSave($entry);
            } finally {
                $settings->buildSavedPages = $build;
            }
            $this->assertNotSame($set, Craft::$app->getCache()->get('speedy:notFoundSet'));
        }
    }

    public function testOnlySoManyAreKept(): void
    {
        $cache = Craft::$app->getCache();
        $this->get($this->missing());
        $set = (string)$cache->get('speedy:notFoundSet');
        $cache->set("speedy:notFoundCount:$set", Cacher::NOT_FOUND_MOST, 300);

        $path = $this->missing();
        $this->assertSame('SKIP status 404', $this->get($path)->getHeaderLine('X-Speedy'), 'Made-up addresses can’t fill the cache.');
        $this->assertSame(404, $this->get($path)->getStatusCode());
    }

    public function testNotKeptWhenTurnedOff(): void
    {
        self::changeSettings(['cacheNotFound' => 0]);
        try {
            $path = $this->missing();
            $this->assertSame('SKIP status 404', $this->get($path)->getHeaderLine('X-Speedy'));
            $this->assertSame('SKIP status 404', $this->get($path)->getHeaderLine('X-Speedy'));
        } finally {
            self::changeSettings(['cacheNotFound' => 300]);
        }
    }
}
