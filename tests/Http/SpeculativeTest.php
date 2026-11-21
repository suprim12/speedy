<?php

namespace suprim\speedy\tests\Http;

use Craft;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;

/**
 * A browser fetching or opening a page ahead for a visitor with something in their session, such as a flash message,
 * is refused, so it isn’t used up on a page they may never open. Needs SPEEDY_TEST_URL.
 */
class SpeculativeTest extends TestCase
{
    private static string $base;

    public static function setUpBeforeClass(): void
    {
        $base = getenv('SPEEDY_TEST_URL');
        if (!$base) {
            self::markTestSkipped('Set SPEEDY_TEST_URL to run the http tests.');
        }
        if (Craft::$app->getPlugins()->getPlugin('blitz')) {
            self::markTestSkipped('Another page cache (Blitz) is enabled. Disable it to run these tests.');
        }
        self::$base = rtrim($base, '/');
    }

    /**
     * @param array<string, string> $headers
     */
    private function get(array $headers): \Psr\Http\Message\ResponseInterface
    {
        // An address no page has: nothing is built or kept.
        return (new Client(['http_errors' => false, 'verify' => false, 'allow_redirects' => false]))
            ->get(self::$base . '/speedy-test-speculative-' . bin2hex(random_bytes(4)), ['headers' => $headers]);
    }

    public function testRefusedForAVisitorWithSessionState(): void
    {
        foreach (['prefetch', 'prefetch;prerender'] as $purpose) {
            $response = $this->get(['Sec-Purpose' => $purpose, 'Cookie' => 'speedy_state=1']);
            $this->assertSame(425, $response->getStatusCode(), $purpose);
            $this->assertSame('SKIP speculative', $response->getHeaderLine('X-Speedy'));
            $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        }
    }

    public function testOtherwiseAnsweredAsUsual(): void
    {
        $this->assertNotSame(425, $this->get(['Sec-Purpose' => 'prefetch'])->getStatusCode(), 'Without session state.');
        $this->assertNotSame(425, $this->get(['Cookie' => 'speedy_state=1'])->getStatusCode(), 'A visit, not a fetch ahead.');
    }
}
