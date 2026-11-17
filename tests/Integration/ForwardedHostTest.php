<?php

namespace suprim\speedy\tests\Integration;

use Craft;
use craft\web\Request;
use suprim\speedy\tests\TestCase;

/**
 * A request can name its host in forwarding headers, which Craft trusts by default. A page built for a made-up
 * host must never be saved as a real host’s page.
 */
class ForwardedHostTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server = [];

    private string $host = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = $_SERVER;
        $this->host = (string)parse_url((string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), PHP_URL_HOST);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        parent::tearDown();
    }

    /**
     * The key Speedy would cache the homepage under with these headers, or why not.
     *
     * @param array<string, string> $headers As $_SERVER entries, such as HTTP_X_FORWARDED_HOST.
     */
    private function key(array $headers): string
    {
        $_SERVER = array_merge($this->server, [
            'REQUEST_URI' => '/', 'QUERY_STRING' => '', 'HTTP_HOST' => $this->host, 'SERVER_NAME' => $this->host, 'HTTPS' => 'on',
            'SERVER_PORT' => 443, 'REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => '/index.php', 'PHP_SELF' => '/index.php',
            'SCRIPT_FILENAME' => Craft::getAlias('@webroot') . '/index.php', 'REMOTE_ADDR' => '203.0.113.9',
        ], $headers);
        // Forwarding headers trusted from any client, as Craft does by default.
        $request = new Request(['cookieValidationKey' => 'test', 'trustedHosts' => ['any']]);
        $this->assertSame($headers['HTTP_X_FORWARDED_HOST'] ?? $this->host, explode('://', (string)$request->getHostInfo(), 2)[1] ?? '', 'The header is used, as on a real site.');
        $cacher = $this->plugin()->cacher;
        $key = $cacher->cacheKey($request);

        return $key ?? 'SKIP ' . (new \ReflectionProperty($cacher, '_skipReason'))->getValue($cacher);
    }

    public function testAHostWithMoreThanAHostIsNeverCached(): void
    {
        $this->assertStringNotContainsString('SKIP', $this->key([]), 'The site itself.');
        foreach (["$this->host?x", "$this->host/x#", "$this->host\"><script>"] as $forwarded) {
            $this->assertSame('SKIP unknown host', $this->key(['HTTP_X_FORWARDED_HOST' => $forwarded]), $forwarded);
        }
    }

    public function testWithAnyHostAnsweredOnlyTheRealHostIsCached(): void
    {
        // A base URL of @web: the site answers to any host.
        $cacher = $this->plugin()->cacher;
        $hosts = new \ReflectionProperty($cacher, '_siteHosts');
        $was = $hosts->getValue($cacher);
        $hosts->setValue($cacher, false);
        $this->settings()->allowedHosts = [];
        try {
            $this->assertStringNotContainsString('SKIP', $this->key([]), 'The host the request was sent to.');
            $this->assertSame('SKIP unknown host', $this->key(['HTTP_X_FORWARDED_HOST' => 'attacker.example']), 'Not one a header names.');
            $this->assertSame('SKIP unknown host', $this->key(['HTTP_X_FORWARDED_HOST' => "$this->host:1234", 'HTTP_X_FORWARDED_PORT' => '1234']), 'Nor a port a header names.');
            $this->assertNull($cacher->certainHosts(), 'So nothing is requested in the background for such a host.');

            $this->settings()->allowedHosts = [$this->host];
            $this->assertSame([$this->host], $cacher->certainHosts());
        } finally {
            $hosts->setValue($cacher, $was);
        }
    }
}
