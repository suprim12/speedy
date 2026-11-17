<?php

namespace suprim\speedy\tests\Http;

use Craft;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use suprim\speedy\Plugin;

/**
 * Real requests to the site: what visitors get. Needs SPEEDY_TEST_URL (the site’s base URL) and file storage.
 * Each test uses its own templates, created here and removed afterwards, and clears only its own pages.
 */
class PagesTest extends TestCase
{
    private static string $base;
    private static string $dir;
    private static string $prefix;

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
        self::$prefix = 'speedy-tests-' . bin2hex(random_bytes(3));
        self::$dir = Craft::getAlias('@templates/' . self::$prefix);
        mkdir(self::$dir, 0775, true);

        $templates = [
            'plain' => '<html><body>Plain {{ now|date("U.u") }}</body></html>',
            'form' => '<html><body><form method="post">{{ csrfInput() }}<button>Go</button></form></body></html>',
            'skip' => '{% do craft.speedy.skip() %}<html><body>Skipped</body></html>',
            'ttl' => '{% do craft.speedy.ttl(120) %}<html><body>Short-lived</body></html>',
            'vary' => '{% header "Vary: User-Agent" %}<html><body>Varies</body></html>',
            'robots' => '{% header "Permissions-Policy: geolocation=()" %}<html><body>Robots</body></html>',
            'set-step' => '{% do craft.app.session.set("speedyTestStep", 2) %}{% redirect "/' . self::$prefix . '/step" %}',
            'step' => '<html><body>Step {{ craft.app.session.get("speedyTestStep", 1) }}</body></html>',
            'done' => '{% do craft.app.session.remove("speedyTestStep") %}{% redirect "/' . self::$prefix . '/step" %}',
            'dynamic' => '<html><body>Page {{ now|date("U.u") }} {{ craft.speedy.dynamic("' . self::$prefix . '/_part", { name: "Alex" }) }}</body></html>',
            '_part' => '<p>Part for {{ name }} at {{ now|date("U.u") }}</p>',
            'analytics' => '<html><body>Analytics</body></html>',
            'theme' => '<html><body>Theme {{ craft.app.request.rawCookies.getValue("theme") ?? "default" }}</body></html>',
            'raw-cookie' => '{% do craft.app.response.headers.add("Set-Cookie", "tracker=abc; Path=/") %}<html><body>Raw cookie</body></html>',
            'loop' => "<html><body>\n{% for i in 1..12 %}{{ craft.users().id(i).one() ? '' }}{% endfor %}\n</body></html>",
            'disk' => '<html><body>Disk</body></html>',
            'param' => '<html><body>Param</body></html>',
            'no-loop' => '<html><body>{{ craft.users().id([1, 2, 3]).all()|length }}</body></html>',
            'broad' => "<html><body>\n{{ craft.entries.relatedTo(1).limit(1).ids()|length }}\n{{ craft.entries.section('*').limit(1).ids()|length }}\n</body></html>",
        ];
        foreach ($templates as $name => $twig) {
            file_put_contents(self::$dir . "/$name.twig", $twig);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$dir) && is_dir(self::$dir)) {
            foreach (glob(self::$dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir(self::$dir);
            Plugin::getInstance()->clearer->clearTags([], 'tests');
            // This class’s pages in the repeated queries report.
            $storage = Plugin::getInstance()->storage;
            $report = $storage->readData('queries');
            $kept = array_filter($report, fn($url) => !str_contains((string)$url, self::$prefix), ARRAY_FILTER_USE_KEY);
            if (count($kept) !== count($report)) {
                $storage->writeData('queries', $kept);
            }
            Craft::$app->getCache()->delete('speedy:freeSpace');
            foreach (Plugin::getInstance()->storage->allKeys() as $key) {
                if (str_contains($key, self::$prefix)) {
                    Plugin::getInstance()->storage->delete($key);
                }
            }
        }
    }

    private function client(?CookieJar $jar = null): Client
    {
        return new Client(['base_uri' => self::$base, 'http_errors' => false, 'verify' => false, 'cookies' => $jar ?? false, 'allow_redirects' => true]);
    }

    private function get(string $page, array $options = [], ?CookieJar $jar = null): ResponseInterface
    {
        return $this->client($jar)->get('/' . self::$prefix . '/' . $page, $options);
    }

    private function speedy(ResponseInterface $response): string
    {
        return $response->getHeaderLine('X-Speedy');
    }

    public function testCachesThenServesTheSameCopy(): void
    {
        $first = $this->get('plain');
        $second = $this->get('plain');

        $this->assertSame('MISS', $this->speedy($first));
        $this->assertContains($this->speedy($second), ['HIT', 'STATIC']);
        $this->assertSame((string)$first->getBody(), preg_replace('/\n<!-- Cached by Speedy Cache on .* -->$/', '', (string)$second->getBody()));
        $this->assertStringContainsString('<!-- Cached by Speedy Cache on', (string)$second->getBody());
    }

    public function testSkipsRequestsThatMayBePersonal(): void
    {
        $this->assertSame('SKIP query string', $this->speedy($this->get('plain?q=shoes')));
        $this->assertSame('SKIP ajax', $this->speedy($this->get('plain', ['headers' => ['HX-Request' => 'true']])));
        $this->assertSame('SKIP ajax', $this->speedy($this->get('plain', ['headers' => ['X-Requested-With' => 'XMLHttpRequest']])));
        $this->assertSame('SKIP template', $this->speedy($this->get('skip')));
        $this->assertSame('SKIP vary', $this->speedy($this->get('vary')));
        $this->assertSame('SKIP status 404', $this->speedy($this->get('does-not-exist')));
        $this->assertSame('private, no-cache', $this->get('skip')->getHeaderLine('Cache-Control'), 'Pages Speedy skips tell CDNs not to keep them.');
    }

    public function testAMadeUpCookieNeverReachesTheSharedCopy(): void
    {
        $evil = CookieJar::fromArray(['theme' => 'evil'], parse_url(self::$base, PHP_URL_HOST));
        $this->assertSame('SKIP request cookies', $this->speedy($this->get('theme', [], $evil)));
        $this->assertStringContainsString('Theme evil', (string)$this->get('theme', [], $evil)->getBody(), 'The visitor still gets their own page.');
        Craft::$app->getQueue()->run();

        $clean = $this->get('theme');
        $this->assertStringContainsString('Theme default', (string)$clean->getBody());
        $this->assertContains($this->speedy($clean), ['HIT', 'STATIC'], 'The copy for everyone was built in the background, without the cookie.');
        $this->assertContains($this->speedy($this->get('theme', [], $evil)), ['HIT', 'STATIC'], 'Visitors with cookies are sent the cached copy.');

        $analytics = CookieJar::fromArray(['_ga' => 'GA1.1.1', '_ga_ABC' => 'GS1'], parse_url(self::$base, PHP_URL_HOST));
        $this->assertSame('MISS', $this->speedy($this->get('analytics', [], $analytics)), 'Analytics cookies don’t stop a visitor building pages.');
    }

    public function testPagesThatSetARawCookieAreNotCached(): void
    {
        $this->assertSame('SKIP sets a cookie', $this->speedy($this->get('raw-cookie')));
        $this->assertSame('SKIP sets a cookie', $this->speedy($this->get('raw-cookie')));
    }

    public function testTrackingParametersAreIgnored(): void
    {
        $this->get('plain');
        $this->assertContains($this->speedy($this->get('plain?utm_source=newsletter')), ['HIT', 'MISS']);
        $this->assertNotSame('SKIP query string', $this->speedy($this->get('plain?utm_source=newsletter&gclid=1')));
    }

    public function testFormsGetAFreshTokenOnCachedPages(): void
    {
        $this->get('form');
        $cached = (string)$this->get('form')->getBody();

        $this->assertStringContainsString('__SPEEDY_CSRF__', $cached, 'The cached copy has a placeholder, not a visitor’s token.');
        $this->assertMatchesRegularExpression('#speedy\\\\?/csrf#', $cached, 'A script fetches a fresh token.');
        $token = json_decode((string)$this->client()->get('/actions/speedy/csrf', ['headers' => ['Accept' => 'application/json']])->getBody(), true);
        $this->assertNotEmpty($token['token'] ?? null);
    }

    public function testKeepsHeadersAndExpiry(): void
    {
        $this->get('robots');
        $this->assertSame('geolocation=()', $this->get('robots')->getHeaderLine('Permissions-Policy'));

        $this->get('ttl');
        $key = Plugin::getInstance()->storage->key(parse_url(self::$base, PHP_URL_HOST), '/' . self::$prefix . '/ttl');
        $expires = Plugin::getInstance()->storage->meta((string)$key)['expires'] ?? 0;
        $this->assertEqualsWithDelta(time() + 120, $expires, 10);
        // The stored address is the one requested, which rebuilds and CDN clearing use.
        $this->assertSame(self::$base . '/' . self::$prefix . '/ttl', Plugin::getInstance()->storage->meta((string)$key)['url'] ?? null);
    }

    /**
     * A multi-step form keeps its step in the session. One visitor’s step must never reach another.
     */
    public function testSessionStateNeverLeaksBetweenVisitors(): void
    {
        $this->get('step');
        $jar = new CookieJar();
        $mine = $this->get('set-step', [], $jar);

        $this->assertStringContainsString('Step 2', (string)$mine->getBody());
        $this->assertNotNull($jar->getCookieByName('speedy_state'));
        $this->assertSame('SKIP session', $this->speedy($this->get('step', [], $jar)));
        $this->assertStringContainsString('Step 1', (string)$this->get('step')->getBody(), 'A new visitor sees step 1.');

        $this->get('done', [], $jar);
        $this->assertNull($jar->getCookieByName('speedy_state'), 'The cookie goes once the session is empty.');
    }

    public function testDynamicPartsAreBuiltForEachVisitor(): void
    {
        $this->get('dynamic');
        $page = (string)$this->get('dynamic')->getBody();
        preg_match('/data-speedy-dynamic="([^"]+)"/', $page, $m);
        $this->assertNotEmpty($m[1] ?? null, 'The cached page has a placeholder.');

        $token = html_entity_decode($m[1]);
        $render = fn(array $tokens) => json_decode((string)$this->client()->post('/actions/speedy/dynamic/render', ['json' => ['tokens' => $tokens]])->getBody(), true);
        $this->assertStringContainsString('Part for Alex', (string)$render([$token])['parts'][0]);
        $this->assertNull($render([$token . 'x'])['parts'][0], 'Tampered tokens render nothing.');
    }

    public function testRebuildsReportQueriesRunInALoop(): void
    {
        if (!Plugin::getInstance()->getSettings()->queryReport) {
            $this->markTestSkipped('queryReport is off on this site.');
        }
        $url = self::$base . '/' . self::$prefix . '/loop';
        $fine = self::$base . '/' . self::$prefix . '/no-loop';
        $this->assertSame(['built' => 2, 'failed' => 0], Plugin::getInstance()->warmer->warm([$url, $fine], false));

        $report = array_column(Plugin::getInstance()->queries->report(), null, 'url');
        $this->assertArrayHasKey($url, $report, 'The page with a loop is listed.');
        $this->assertArrayNotHasKey($fine, $report, 'One query for them all isn’t.');
        $repeat = $report[$url]['repeated'][0];
        $this->assertGreaterThanOrEqual(12, $repeat['count']);
        $this->assertSame(self::$prefix . '/loop, line 2', $repeat['at'], 'The template and line that ran it.');
        $this->assertStringContainsString('Users are fetched one item at a time', $repeat['tip']);
        $this->assertStringNotContainsString("'", $repeat['sql'], 'No values are kept.');

        // Visitors’ requests aren’t counted: take this page off the report, then visit it.
        $storage = Plugin::getInstance()->storage;
        $storage->writeData('queries', array_diff_key($storage->readData('queries'), [$url => true]));
        $storage->delete((string)$storage->keyForUrl($url));
        $this->assertSame('MISS', $this->speedy($this->get('loop')));
        $this->assertArrayNotHasKey($url, array_column(Plugin::getInstance()->queries->report(), null, 'url'));
    }

    public function testNewPagesArentSavedOnANearlyFullDisk(): void
    {
        Craft::$app->getCache()->set('speedy:freeSpace', 10 * 1024 * 1024, 60);
        try {
            $page = $this->get('disk');
            $this->assertSame('SKIP disk full', $this->speedy($page), 'Not saved, but the visitor still gets the page.');
            $this->assertStringContainsString('Disk', (string)$page->getBody());
            $this->assertSame('SKIP disk full', $this->speedy($this->get('disk')), 'Still not saved.');
        } finally {
            Craft::$app->getCache()->delete('speedy:freeSpace');
        }
    }

    public function testMadeUpHostsAndPortsArentCached(): void
    {
        $webroot = (string)\Craft::getAlias('@webroot');
        $before = is_file("$webroot/index.html");
        // Whether the server passes these on or not, nothing may be written outside the cache folder.
        foreach (['..', '.', 'attacker.example', parse_url(self::$base, PHP_URL_HOST) . ':1'] as $host) {
            $response = $this->get('plain', ['headers' => ['X-Forwarded-Host' => $host]]);
            $this->assertSame(200, $response->getStatusCode());
        }
        $this->assertSame($before, is_file("$webroot/index.html"), 'Nothing was written outside the cache folder.');
        $this->assertFileDoesNotExist(dirname(Plugin::getInstance()->storage->cacheRoot()) . '/index.html.gz');
    }

    public function testRebuildTokensAreForOnePage(): void
    {
        $warmer = Plugin::getInstance()->warmer;
        $other = $warmer->token(self::$base . '/' . self::$prefix . '/other', time() + 900);
        $this->assertNotSame('WARM', $this->speedy($this->get('plain?speedy-warm=1', ['headers' => ['X-Speedy-Warm' => $other]])), 'A token for another page doesn’t rebuild this one.');

        $expired = $warmer->token(self::$base . '/' . self::$prefix . '/plain', time() - 1);
        $this->assertNotSame('WARM', $this->speedy($this->get('plain?speedy-warm=1', ['headers' => ['X-Speedy-Warm' => $expired]])), 'An old token doesn’t either.');

        $good = $warmer->token(self::$base . '/' . self::$prefix . '/plain?speedy-warm=1', time() + 900);
        $this->assertSame('WARM', $this->speedy($this->get('plain?speedy-warm=1', ['headers' => ['X-Speedy-Warm' => $good]])));
    }

    public function testThePathParameterCantPointAPageAtTheWrongAddress(): void
    {
        $pathParam = \Craft::$app->getConfig()->getGeneral()->pathParam;
        if (!$pathParam) {
            $this->markTestSkipped('No path parameter on this site.');
        }
        $this->client()->get('/?' . $pathParam . '=' . self::$prefix . '/param');
        $storage = Plugin::getInstance()->storage;
        $key = $storage->key((string)parse_url(self::$base, PHP_URL_HOST), self::$prefix . '/param');
        $meta = $storage->meta((string)$key);
        if ($meta === null) {
            $this->markTestSkipped('This server doesn’t route the path parameter.');
        }
        $this->assertSame(self::$base . '/' . self::$prefix . '/param', $meta['url'], 'Stored with its own address, so it rebuilds itself.');
    }

    public function testVisitorsDontQueueForAPageThatIsntSaved(): void
    {
        $this->assertSame('SKIP template', $this->speedy($this->get('skip')));
        $key = Plugin::getInstance()->storage->key((string)parse_url(self::$base, PHP_URL_HOST), self::$prefix . '/skip');
        $this->assertTrue((bool)Craft::$app->getCache()->get('speedy:notSaved:' . md5((string)$key)), 'Remembered, so the next visitors build it side by side.');
        Craft::$app->getCache()->delete('speedy:notSaved:' . md5((string)$key));
    }

    public function testResponsesThatSetACookieArentPublic(): void
    {
        $response = $this->get('form');
        if ($response->getHeaderLine('Set-Cookie') !== '') {
            $this->assertStringContainsString('private', $response->getHeaderLine('Cache-Control'), 'A CDN mustn’t keep a response with someone’s cookie.');
        } else {
            $this->assertNotSame('', $response->getHeaderLine('Cache-Control'));
        }
    }

    public function testAPageThatCantBeRebuiltIsRemovedByItsOwnKey(): void
    {
        $storage = Plugin::getInstance()->storage;
        $host = (string)parse_url(self::$base, PHP_URL_HOST);
        // Its stored address doesn’t lead back to its key, as with Craft in a subfolder or an address 1.0 stored.
        $key = (string)$storage->key($host, self::$prefix . '/old-copy');
        $url = self::$base . '/' . self::$prefix . '/does-not-exist';
        $storage->write($key, '<html>Old</html>', $url, ['element::999999'], null);

        Plugin::getInstance()->warmer->warm([$url], true, null, [$url => $key]);
        $this->assertFalse($storage->exists($key), 'Gone, rather than sent forever.');
    }

    public function testRebuildsFindQueriesThatClearOnAnyChange(): void
    {
        if (!Plugin::getInstance()->getSettings()->queryReport) {
            $this->markTestSkipped('queryReport is off on this site.');
        }
        $url = self::$base . '/' . self::$prefix . '/broad';
        $fine = self::$base . '/' . self::$prefix . '/no-loop';
        $this->assertSame(['built' => 2, 'failed' => 0], Plugin::getInstance()->warmer->warm([$url, $fine], false));

        $pages = array_column(Plugin::getInstance()->queries->broad(), null, 'url');
        try {
            $this->assertArrayHasKey($url, $pages);
            $this->assertArrayNotHasKey($fine, $pages, 'Queries by ID are narrow already.');
            $this->assertSame([['type' => 'Entries', 'at' => self::$prefix . '/broad, line 2', 'count' => 1]], $pages[$url]['broad'], 'Only the query without a section, with its line.');
        } finally {
            $storage = Plugin::getInstance()->storage;
            $storage->writeData('queries', array_diff_key($storage->readData('queries'), [$url => true, $fine => true]));
            $storage->writeData('slow', array_diff_key($storage->readData('slow'), [$url => true, $fine => true]));
        }
    }

    public function testRebuildsThatFailSayWhy(): void
    {
        $plugin = Plugin::getInstance();
        $missing = self::$base . '/' . self::$prefix . '/not-a-page';
        $plugin->warmer->warm([$missing], true);

        $this->assertStringContainsString('(status 404)', $plugin->log->recent(1)[0]['reason'], 'The log says why.');
        $problem = (string)$plugin->insights->rebuildProblem();
        $this->assertStringContainsString('couldn’t be rebuilt in the background (status 404)', $problem);
        $this->assertContains($problem, array_column($plugin->insights->checks(), 'message'), 'Shown on the utility page.');

        // Once a rebuild works again, the problem goes away.
        sleep(1);
        $this->assertSame(['built' => 1, 'failed' => 0], $plugin->warmer->warm([self::$base . '/' . self::$prefix . '/plain'], true));
        $this->assertNull($plugin->insights->rebuildProblem());
    }

    public function testRebuildProblemsGiveAdvice(): void
    {
        $storage = Plugin::getInstance()->storage;
        $cases = [
            'status 401' => 'warmUsername',
            'status 503' => 'storage/logs',
            'cURL error 6: Could not resolve host: example.com' => 'couldn’t open example.com itself',
        ];
        foreach ($cases as $why => $advice) {
            $storage->writeData('state', ['rebuildFailed' => ['time' => time(), 'count' => 1, 'url' => 'https://example.com/', 'why' => $why]]);
            $this->assertStringContainsString($advice, (string)Plugin::getInstance()->insights->rebuildProblem(), $why);
        }
        $storage->writeData('state', ['rebuildFailed' => ['time' => time() - 90000, 'count' => 1, 'url' => 'https://example.com/', 'why' => 'status 401']]);
        $this->assertNull(Plugin::getInstance()->insights->rebuildProblem(), 'Not after a day.');
    }

    public function testQueryParametersThatStopCachingAreSuggested(): void
    {
        $param = 'speedyTest' . bin2hex(random_bytes(3));
        $requests = function() use ($param) {
            for ($i = 0; $i < 300; $i++) {
                yield new \GuzzleHttp\Psr7\Request('GET', '/' . self::$prefix . "/plain?$param=$i");
            }
        };
        (new \GuzzleHttp\Pool($this->client(), $requests(), ['concurrency' => 10]))->promise()->wait();

        try {
            $seen = Plugin::getInstance()->insights->skippedParams();
            $this->assertArrayHasKey($param, $seen, 'About 300 visits, sampled.');
            $this->assertSame('/' . self::$prefix . '/plain', $seen[$param]['path']);
            $messages = implode(' ', array_column(Plugin::getInstance()->insights->checks(), 'message'));
            $this->assertStringContainsString("add `$param` under Settings → Query strings → Cached parameters", $messages);

            // Once it’s listed, the suggestion goes.
            $settings = Plugin::getInstance()->getSettings();
            $before = $settings->cachedParams;
            $settings->cachedParams = [$param];
            $this->assertArrayNotHasKey($param, Plugin::getInstance()->insights->skippedParams());
            $settings->cachedParams = $before;
        } finally {
            // Only this test’s entry.
            $cache = Craft::$app->getCache();
            $all = $cache->get(\suprim\speedy\services\Cacher::SKIPPED_PARAMS);
            if (is_array($all)) {
                unset($all[$param]);
                $cache->set(\suprim\speedy\services\Cacher::SKIPPED_PARAMS, $all, 7 * 86400);
            }
        }
    }

    public function testApiIsOffWithoutAKey(): void
    {
        if (Plugin::getInstance()->getSettings()->getApiKey() !== '') {
            $this->markTestSkipped('An API key is set on this site.');
        }
        $this->assertSame(404, $this->client()->post('/actions/speedy/api/clear')->getStatusCode());
    }
}
