<?php

namespace suprim\speedy\tests\Http;

use Craft;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use suprim\speedy\Plugin;

/**
 * The status endpoint for uptime monitors. Needs SPEEDY_TEST_URL. Sets an API key for the tests and puts the saved
 * settings back afterwards.
 */
class StatusTest extends TestCase
{
    use KeepsSettings;

    private static string $base;

    private const KEY = 'speedy-test-key-0123456789abcdef';

    public static function setUpBeforeClass(): void
    {
        $base = getenv('SPEEDY_TEST_URL');
        if (!$base) {
            self::markTestSkipped('Set SPEEDY_TEST_URL to run the http tests.');
        }
        self::$base = rtrim($base, '/');
        self::keepSettings();
        self::changeSettings(['apiKey' => self::KEY]);
    }

    public static function tearDownAfterClass(): void
    {
        self::putSettingsBack();
    }

    private function client(): Client
    {
        return new Client(['base_uri' => self::$base, 'http_errors' => false, 'verify' => false, 'cookies' => false]);
    }

    private function url(): string
    {
        return '/' . Craft::$app->getConfig()->getGeneral()->actionTrigger . '/speedy/api/status';
    }

    public function testMonitorsGetTheStatusWithTheTokenOrTheKey(): void
    {
        $token = Plugin::getInstance()->getSettings()->getStatusToken();
        $this->assertSame(32, strlen($token));
        $this->assertStringNotContainsString(self::KEY, $token, 'Not the key itself.');

        foreach ([['query' => ['statusToken' => $token]], ['headers' => ['Authorization' => 'Bearer ' . self::KEY]]] as $options) {
            $response = $this->client()->get($this->url(), $options);
            $status = json_decode((string)$response->getBody(), true);
            $this->assertIsArray($status);
            $this->assertSame(['ok', 'problems', 'pages', 'enabled', 'checked'], array_keys($status));
            $this->assertSame($status['ok'] ? 200 : 503, $response->getStatusCode(), 'Monitors can go by the status code.');
            $this->assertSame($status['ok'], $status['problems'] === []);
            $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        }
    }

    public function testNothingWithoutTheTokenOrKey(): void
    {
        $this->assertSame(403, $this->client()->get($this->url())->getStatusCode());
        $this->assertSame(403, $this->client()->get($this->url(), ['query' => ['statusToken' => 'wrong']])->getStatusCode());
        $this->assertSame(403, $this->client()->get($this->url(), ['query' => ['key' => self::KEY]])->getStatusCode(), 'Never the key in the URL.');
        $this->assertSame(400, $this->client()->post($this->url(), ['headers' => ['Authorization' => 'Bearer ' . self::KEY]])->getStatusCode());
    }

    public function testTheTokenCantClearAnything(): void
    {
        $token = Plugin::getInstance()->getSettings()->getStatusToken();
        $clear = '/' . Craft::$app->getConfig()->getGeneral()->actionTrigger . '/speedy/api/clear';
        $this->assertSame(403, $this->client()->post($clear, ['headers' => ['Authorization' => 'Bearer ' . $token]])->getStatusCode());
        $this->assertSame(403, $this->client()->post($clear, ['form_params' => ['key' => $token]])->getStatusCode());
    }

    public function testCachingOffIsAProblem(): void
    {
        self::changeSettings(['enabled' => false]);
        try {
            $response = $this->client()->get($this->url(), ['query' => ['statusToken' => Plugin::getInstance()->getSettings()->getStatusToken()]]);
            $this->assertSame(503, $response->getStatusCode());
            $status = json_decode((string)$response->getBody(), true);
            $this->assertFalse($status['enabled']);
            $this->assertContains('Caching is off, so pages are built for every visit.', $status['problems']);
        } finally {
            self::changeSettings(['enabled' => true]);
        }
    }

    public function testOffWithoutAnApiKey(): void
    {
        self::changeSettings(['apiKey' => '']);
        try {
            $this->assertSame(404, $this->client()->get($this->url(), ['query' => ['statusToken' => 'anything']])->getStatusCode());
        } finally {
            self::changeSettings(['apiKey' => self::KEY]);
        }
    }
}
