<?php

namespace suprim\speedy\tests\Integration;

use Craft;
use craft\elements\Entry;
use craft\web\Request;
use suprim\speedy\tests\TestCase;

/**
 * Never cache sites: a whole site, such as a members’ area, is left out, and its pages cached before are removed.
 */
class NeverCacheSitesTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server = [];

    private string $host = '';
    private string $handle = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = $_SERVER;
        $site = Craft::$app->getSites()->getPrimarySite();
        $this->handle = $site->handle;
        $this->host = (string)parse_url((string)$site->getBaseUrl(), PHP_URL_HOST);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        parent::tearDown();
    }

    /**
     * The key Speedy would cache the primary site’s homepage under, or why not.
     */
    private function key(): string
    {
        $_SERVER = array_merge($this->server, [
            'REQUEST_URI' => '/', 'QUERY_STRING' => '', 'HTTP_HOST' => $this->host, 'SERVER_NAME' => $this->host, 'HTTPS' => 'on',
            'SERVER_PORT' => 443, 'REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => '/index.php', 'PHP_SELF' => '/index.php',
            'SCRIPT_FILENAME' => Craft::getAlias('@webroot') . '/index.php', 'REMOTE_ADDR' => '203.0.113.9',
        ]);
        $cacher = $this->plugin()->cacher;
        $key = $cacher->cacheKey(new Request(['cookieValidationKey' => 'test']));

        return $key ?? 'SKIP ' . (new \ReflectionProperty($cacher, '_skipReason'))->getValue($cacher);
    }

    public function testTheSitesPagesAreNotCached(): void
    {
        $this->assertStringNotContainsString('SKIP', $this->key());

        $this->settings()->neverCacheSites = [$this->handle];
        $this->assertSame('SKIP never cache site', $this->key());
        $this->assertSame([(int)Craft::$app->getSites()->getPrimarySite()->id], $this->settings()->getNeverCacheSiteIds());

        $this->settings()->neverCacheSites = ['no-such-site'];
        $this->assertSame([], $this->settings()->getNeverCacheSiteIds(), 'A site that doesn’t exist is left out.');
        $this->assertStringNotContainsString('SKIP', $this->key());
    }

    public function testItsCachedPagesAreRemoved(): void
    {
        $storage = $this->plugin()->storage;
        $mine = "$this->host/speedy-test-site-page";
        $storage->write($mine, '<html></html>', "https://$mine", [], null);
        $storage->write('speedy-other-host.test/page', '<html></html>', 'https://speedy-other-host.test/page', [], null);

        $this->settings()->neverCacheSites = [$this->handle];
        $this->assertTrue($this->plugin()->insights->leftOut("https://$mine", 'speedy-test-site-page'));
        $this->assertSame(1, $this->plugin()->clearer->clearNeverCached($this->settings()));
        $this->assertFalse($storage->exists($mine), 'The site’s page is gone.');
        $this->assertTrue($storage->exists('speedy-other-host.test/page'), 'A page of no site of these is kept.');
    }

    public function testTheEntryStatusSaysWhy(): void
    {
        $entry = Entry::find()->site($this->handle)->uri(':notempty:')->status(null)->one();
        if (!$entry) {
            $this->markTestSkipped('No entry with a page.');
        }
        $this->settings()->neverCacheSites = [$this->handle];
        $status = $this->plugin()->insights->forElement($entry, false);
        $this->assertSame('never', $status['state'] ?? null);
        $this->assertSame('Its site is in Never cache', $status['detail'] ?? null);
    }
}
