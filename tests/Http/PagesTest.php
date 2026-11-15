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
            'robots' => '{% header "X-Robots-Tag: noindex" %}<html><body>Robots</body></html>',
            'set-step' => '{% do craft.app.session.set("speedyTestStep", 2) %}{% redirect "/' . self::$prefix . '/step" %}',
            'step' => '<html><body>Step {{ craft.app.session.get("speedyTestStep", 1) }}</body></html>',
            'done' => '{% do craft.app.session.remove("speedyTestStep") %}{% redirect "/' . self::$prefix . '/step" %}',
            'dynamic' => '<html><body>Page {{ now|date("U.u") }} {{ craft.speedy.dynamic("' . self::$prefix . '/_part", { name: "Alex" }) }}</body></html>',
            '_part' => '<p>Part for {{ name }} at {{ now|date("U.u") }}</p>',
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
        $this->assertSame('noindex', $this->get('robots')->getHeaderLine('X-Robots-Tag'));

        $this->get('ttl');
        $key = Plugin::getInstance()->storage->key(parse_url(self::$base, PHP_URL_HOST), '/' . self::$prefix . '/ttl');
        $expires = Plugin::getInstance()->storage->meta((string)$key)['expires'] ?? 0;
        $this->assertEqualsWithDelta(time() + 120, $expires, 10);
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

    public function testApiIsOffWithoutAKey(): void
    {
        if (Plugin::getInstance()->getSettings()->getApiKey() !== '') {
            $this->markTestSkipped('An API key is set on this site.');
        }
        $this->assertSame(404, $this->client()->post('/actions/speedy/api/clear')->getStatusCode());
    }
}
