<?php

namespace suprim\speedy\tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use suprim\speedy\services\cdn\CloudflareRules;
use suprim\speedy\tests\TestCase;

/**
 * Set up Cloudflare: adds the cache rules to the zone, or updates its own, and leaves other rules alone.
 */
class CloudflareSetupTest extends TestCase
{
    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $sent = [];

    /**
     * @param Response[] $responses
     */
    private function answer(array $responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->sent));
        $this->plugin()->cdn->cloudflareClient = new Client(['handler' => $stack]);
        $this->settings()->cloudflareToken = 'cf-token';
        $this->settings()->cloudflareZoneId = 'zone-1';
        // The sites’ hosts, as if from their base URLs.
        (fn() => $this->_siteHosts = ['example.com', 'www.example.com:8443'])->call($this->plugin()->cacher);
    }

    protected function tearDown(): void
    {
        $this->plugin()->cdn->cloudflareClient = null;
        (fn() => $this->_siteHosts = null)->call($this->plugin()->cacher);
        parent::tearDown();
    }

    private static function ok(array $result = []): Response
    {
        return new Response(200, [], json_encode(['success' => true, 'result' => $result]));
    }

    /**
     * @return array<int, array{method: string, path: string, body: mixed}>
     */
    private function calls(): array
    {
        return array_map(fn($c) => [
            'method' => $c['request']->getMethod(),
            'path' => $c['request']->getUri()->getPath(),
            'body' => json_decode((string)$c['request']->getBody(), true),
        ], $this->sent);
    }

    public function testRulesKeepPagesOnlyWhenTheySayAndLeaveOutVisitorsWithTheirOwnPages(): void
    {
        $rules = CloudflareRules::rules(['example.com', 'www.example.com'], ['/admin', '/actions'], ['_identity', 'speedy_state']);
        $pages = $rules[CloudflareRules::PAGES_REF];
        $bypass = $rules[CloudflareRules::BYPASS_REF];

        $this->assertSame('(http.host in {"example.com" "www.example.com"})', $pages['expression']);
        $this->assertSame(['mode' => 'bypass_by_default'], $pages['action_parameters']['edge_ttl'], 'Responses without Cache-Control aren’t kept.');
        $this->assertTrue($pages['action_parameters']['cache']);
        $this->assertFalse($bypass['action_parameters']['cache']);
        $this->assertStringStartsWith('(http.host in {"example.com" "www.example.com"}) and (', $bypass['expression']);
        foreach (['http.cookie contains "_identity"', 'http.cookie contains "speedy_state"', 'starts_with(http.request.uri.path, "/admin")', 'starts_with(http.request.uri.path, "/actions")', '"hx-request"'] as $part) {
            $this->assertStringContainsString($part, $bypass['expression']);
        }
        $this->assertSame([CloudflareRules::PAGES_REF, CloudflareRules::BYPASS_REF], array_keys($rules), 'The bypass rule comes last, so it wins.');
    }

    public function testAZoneWithoutCacheRulesGetsBoth(): void
    {
        $this->answer([new Response(404, [], '{"success":false,"errors":[{"code":10003,"message":"not found"}]}'), self::ok()]);

        $message = $this->plugin()->cdn->setUpCloudflare();
        $calls = $this->calls();
        $this->assertSame(['GET', 'PUT'], array_column($calls, 'method'));
        $this->assertSame('/client/v4/zones/zone-1/rulesets/phases/http_request_cache_settings/entrypoint', $calls[1]['path']);
        $this->assertSame([CloudflareRules::PAGES_REF, CloudflareRules::BYPASS_REF], array_column($calls[1]['body']['rules'], 'ref'));
        $this->assertStringContainsString('"www.example.com"', $calls[1]['body']['rules'][0]['expression'], 'Ports are left off the host.');
        $this->assertStringContainsString('example.com, www.example.com', $message);
        $this->assertSame(['example.com', 'www.example.com'], $this->plugin()->cdn->cloudflareRules()['hosts']);
    }

    public function testExistingRulesAreKeptAndItsOwnAreUpdated(): void
    {
        $this->answer([
            self::ok(['id' => 'set-1', 'rules' => [
                ['id' => 'theirs', 'ref' => 'their_rule'],
                ['id' => 'r-bypass', 'ref' => CloudflareRules::BYPASS_REF],
            ]]),
            self::ok(),
            self::ok(),
        ]);

        $this->plugin()->cdn->setUpCloudflare();
        $calls = $this->calls();
        $this->assertSame(['GET', 'POST', 'PATCH'], array_column($calls, 'method'), 'Nothing is replaced or deleted.');
        $this->assertSame('/client/v4/zones/zone-1/rulesets/set-1/rules', $calls[1]['path']);
        $this->assertSame(['before' => 'r-bypass'], $calls[1]['body']['position'], 'A missing pages rule goes before the bypass rule.');
        $this->assertSame('/client/v4/zones/zone-1/rulesets/set-1/rules/r-bypass', $calls[2]['path']);
    }

    public function testRunningItAgainSaysTheRulesAreThere(): void
    {
        $this->answer([
            self::ok(['id' => 'set-1', 'rules' => [['id' => 'a', 'ref' => CloudflareRules::PAGES_REF], ['id' => 'b', 'ref' => CloudflareRules::BYPASS_REF]]]),
            self::ok(),
            self::ok(),
        ]);

        $this->assertStringContainsString('already there', $this->plugin()->cdn->setUpCloudflare());
        $this->assertSame(['GET', 'PATCH', 'PATCH'], array_column($this->calls(), 'method'));
    }

    public function testATokenWithoutThePermissionSaysWhatToAdd(): void
    {
        $this->answer([new Response(403, [], '{"success":false,"errors":[{"code":10000,"message":"Authentication error"}]}')]);

        $this->expectExceptionMessage('Zone → Cache Rules → Edit');
        $this->plugin()->cdn->setUpCloudflare();
    }

    public function testNeedsTheZoneAndTheSitesHosts(): void
    {
        $this->answer([]);
        $this->settings()->cloudflareZoneId = '';
        try {
            $this->plugin()->cdn->setUpCloudflare();
            $this->fail('Set up needs a zone.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Add the Cloudflare token and zone ID', $e->getMessage());
        }

        $this->settings()->cloudflareZoneId = 'zone-1';
        (fn() => $this->_siteHosts = false)->call($this->plugin()->cacher);
        $this->expectExceptionMessage('allowedHosts');
        $this->plugin()->cdn->setUpCloudflare();
    }
}
