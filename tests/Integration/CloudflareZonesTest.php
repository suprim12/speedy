<?php

namespace suprim\speedy\tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use suprim\speedy\tests\TestCase;

/**
 * Sites on several domains, with a Cloudflare zone for each.
 */
class CloudflareZonesTest extends TestCase
{
    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $stack = HandlerStack::create(new MockHandler(array_fill(0, 10, new Response(200, [], '{"success":true}'))));
        $stack->push(Middleware::history($this->sent));
        $this->plugin()->cdn->cloudflareClient = new Client(['handler' => $stack]);
        $this->settings()->cloudflareToken = 'cf-token';
    }

    protected function tearDown(): void
    {
        $this->plugin()->cdn->cloudflareClient = null;
        parent::tearDown();
    }

    /**
     * @return array<string, string[]> The URLs sent to each zone.
     */
    private function sentByZone(): array
    {
        $byZone = [];
        foreach ($this->sent as $call) {
            preg_match('#/zones/([^/]+)/purge_cache#', (string)$call['request']->getUri(), $m);
            $body = json_decode((string)$call['request']->getBody(), true);
            $byZone[$m[1]] = array_merge($byZone[$m[1]] ?? [], $body['files'] ?? ['everything']);
        }

        return $byZone;
    }

    public function testEachDomainIsClearedFromItsOwnZone(): void
    {
        $this->settings()->cloudflareZones = ['example.com' => 'zone-com', 'example.de' => 'zone-de'];
        $this->settings()->cloudflareZoneId = 'zone-default';
        $cdn = $this->plugin()->cdn;

        $this->assertSame('zone-com', $cdn->cloudflareZoneFor('https://www.example.com/a'), 'A domain covers its subdomains.');
        $this->assertSame('zone-default', $cdn->cloudflareZoneFor('https://other.org/'));

        $cdn->purgeUrls(['https://example.com/a', 'https://www.example.com/b', 'https://example.de/c', 'https://other.org/d']);
        $this->assertSame([
            'zone-com' => ['https://example.com/a', 'https://www.example.com/b'],
            'zone-de' => ['https://example.de/c'],
            'zone-default' => ['https://other.org/d'],
        ], $this->sentByZone());

        $this->sent = [];
        $cdn->purgeAll();
        $this->assertSame(['zone-com', 'zone-de', 'zone-default'], array_keys($this->sentByZone()), 'Clearing everything clears every zone.');
    }

    public function testUrlsWithoutAZoneAreSkipped(): void
    {
        $this->settings()->cloudflareZones = ['example.com' => 'zone-com'];
        $cdn = $this->plugin()->cdn;

        $this->assertContains('cloudflare', $cdn->configured(), 'Zones alone are enough.');
        $cdn->purgeUrls(['https://example.com/a', 'https://other.org/b']);
        $this->assertSame(['zone-com' => ['https://example.com/a']], $this->sentByZone());
        $this->assertStringContainsString('1 URL has no zone', $cdn->lastResults()['cloudflare']['result']);
    }

    public function testOneZoneStillWorks(): void
    {
        $this->settings()->cloudflareZoneId = 'zone-only';
        $this->plugin()->cdn->purgeUrls(['https://example.com/a']);
        $this->assertSame(['zone-only' => ['https://example.com/a']], $this->sentByZone());
    }
}
