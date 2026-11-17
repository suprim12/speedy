<?php

namespace suprim\speedy\tests\Integration;

use Craft;
use craft\web\Request;
use suprim\speedy\services\Cacher;
use suprim\speedy\tests\TestCase;

/**
 * Which pages with a query string are cached, and under which key: the parameters listed, in any order, with
 * tracking parameters left out, and nothing that could fill the disk.
 */
class QueryStringsTest extends TestCase
{
    private const PAGE = 'speedy-qs-test/listing';

    /** @var array<string, mixed> */
    private array $server = [];

    private mixed $skipped = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = $_SERVER;
        $this->skipped = Craft::$app->getCache()->get(Cacher::SKIPPED_PARAMS);
        $this->settings()->queryStrings = 'skip';
        $this->settings()->cachedParams = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_GET = [];
        // The parameters these requests noted aren’t the site’s.
        $cache = Craft::$app->getCache();
        $this->skipped === false ? $cache->delete(Cacher::SKIPPED_PARAMS) : $cache->set(Cacher::SKIPPED_PARAMS, $this->skipped, 7 * 86400);
        parent::tearDown();
    }

    /**
     * The key Speedy would cache this address under, or the reason it wouldn’t.
     */
    private function key(string $query): string
    {
        $host = (string)parse_url((string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), PHP_URL_HOST);
        $uri = '/' . self::PAGE . ($query !== '' ? "?$query" : '');
        $_SERVER = array_merge($this->server, [
            'REQUEST_URI' => $uri, 'QUERY_STRING' => $query, 'HTTP_HOST' => $host, 'SERVER_NAME' => $host, 'HTTPS' => 'on',
            'SERVER_PORT' => 443, 'REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => '/index.php', 'PHP_SELF' => '/index.php',
            'SCRIPT_FILENAME' => Craft::getAlias('@webroot') . '/index.php',
        ]);
        parse_str($query, $params);
        $_GET = $params;
        $request = new Request(['cookieValidationKey' => 'test']);
        $request->setQueryParams($params);
        $cacher = $this->plugin()->cacher;
        $key = $cacher->cacheKey($request);

        return $key ?? 'SKIP ' . (new \ReflectionProperty($cacher, '_skipReason'))->getValue($cacher);
    }

    public function testQueryStringsArentCachedUnlessListed(): void
    {
        $this->assertStringNotContainsString('SKIP', $this->key(''));
        $this->assertSame('SKIP query string', $this->key('view=map'));
        $this->assertStringNotContainsString('SKIP', $this->key('utm_source=news&gclid=1'), 'Tracking parameters never stop a page being cached.');
        $this->assertSame($this->key(''), $this->key('utm_source=news'), 'And it’s the same page.');
    }

    public function testEachListedValueIsAPageOfItsOwn(): void
    {
        $this->settings()->cachedParams = ['view', 'page'];
        $map = $this->key('view=map');
        $list = $this->key('view=list');
        $this->assertStringNotContainsString('SKIP', $map);
        $this->assertNotSame($map, $list, 'A tab each.');
        $this->assertNotSame($this->key(''), $map);

        $this->assertSame($this->key('view=map&page=2'), $this->key('page=2&view=map'), 'In any order.');
        $this->assertSame($map, $this->key('view=map&utm_source=news&fbclid=1&_ga=2'), 'Tracking parameters left out.');
        $this->assertSame($this->key('view=a%20b'), $this->key('view=a+b'), 'The same value, however it’s written.');
        $this->assertSame('SKIP query string', $this->key('view=map&sort=price'), 'An unlisted parameter still means the page isn’t cached.');
    }

    public function testMadeUpValuesCantMakeEndlessCopies(): void
    {
        $this->settings()->cachedParams = ['view'];
        $this->assertSame('SKIP query string', $this->key('view[]=map'), 'One plain value each.');
        $this->assertSame('SKIP query string', $this->key('view=' . str_repeat('x', 101)), 'And a short one.');

        // Keys stay inside the cache folder, whatever the value.
        foreach (['view=../../etc', 'view=%2F..%2F', 'view=%00'] as $query) {
            $key = $this->key($query);
            $this->assertStringNotContainsString('/../', "/$key/", $query);
            $this->assertStringNotContainsString("\0", $key, $query);
        }

        // At most maxQueryPages versions of a page.
        $this->settings()->maxQueryPages = 2;
        $storage = $this->plugin()->storage;
        foreach (['view=a', 'view=b'] as $query) {
            $storage->write($this->key($query), '<html>Kept</html>', 'https://example.com/' . self::PAGE . "?$query", [], null);
        }
        $this->assertSame('SKIP too many query strings', $this->key('view=c'));
        $this->assertStringNotContainsString('SKIP', $this->key('view=a'), 'The versions already kept are still sent.');
    }

    public function testAnyParameterWithAWildcard(): void
    {
        $this->settings()->cachedParams = ['*'];
        $key = $this->key('anything=1&utm_source=news');
        $this->assertStringNotContainsString('SKIP', $key);
        $this->assertSame($this->key('anything=1'), $key, 'Tracking parameters are still left out.');
    }

    public function testIgnoringQueryStringsCachesThePageWithoutThem(): void
    {
        $this->settings()->queryStrings = 'ignore';
        $this->assertSame($this->key(''), $this->key('view=map'));
        $this->assertSame($this->key(''), $this->key('anything=1&more=2'));
    }

    public function testParametersOnlyScriptsReadCanBeIgnored(): void
    {
        $this->settings()->ignoredParams = array_merge($this->settings()->ignoredParams, ['ref']);
        $this->assertSame($this->key(''), $this->key('ref=homepage'));
    }
}
