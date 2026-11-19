<?php

namespace suprim\speedy\tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use suprim\speedy\purgers\BasePurger;
use suprim\speedy\purgers\Bunny;
use suprim\speedy\purgers\Fastly;
use suprim\speedy\purgers\KeyCdn;
use suprim\speedy\purgers\Varnish;
use suprim\speedy\tests\TestCase;

/**
 * The requests each CDN is sent, checked against its API, without calling the real service.
 */
class PurgersTest extends TestCase
{
    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $sent = [];

    private function fake(BasePurger $purger, int $status = 200, int $count = 10): BasePurger
    {
        $this->sent = [];
        $stack = HandlerStack::create(new MockHandler(array_fill(0, $count, new Response($status, [], '{"status":"ok"}'))));
        $stack->push(Middleware::history($this->sent));
        $purger->client = new Client(['handler' => $stack, 'http_errors' => false]);

        return $purger;
    }

    public function testFastly(): void
    {
        $this->settings()->fastlyToken = 'fastly-token';
        $this->settings()->fastlyServiceId = 'SVC123';
        $fastly = $this->fake(new Fastly());

        $this->assertTrue($fastly->isConfigured());
        $fastly->purgeUrls(['https://example.com/news?page=2']);
        $request = $this->sent[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.fastly.com/purge/example.com/news?page=2', (string)$request->getUri());
        $this->assertSame('fastly-token', $request->getHeaderLine('Fastly-Key'));

        $fastly->purgeAll();
        $this->assertSame('https://api.fastly.com/service/SVC123/purge_all', (string)$this->sent[1]['request']->getUri());
    }

    public function testBunny(): void
    {
        $this->settings()->bunnyApiKey = 'bunny-key';
        $this->settings()->bunnyPullZoneId = '42';
        $bunny = $this->fake(new Bunny());

        $bunny->purgeUrls(['https://example.com/a b']);
        $this->assertSame('https://api.bunny.net/purge?url=https%3A%2F%2Fexample.com%2Fa%20b', (string)$this->sent[0]['request']->getUri());
        $this->assertSame('bunny-key', $this->sent[0]['request']->getHeaderLine('AccessKey'));

        $bunny->purgeAll();
        $this->assertSame('https://api.bunny.net/pullzone/42/purgeCache', (string)$this->sent[1]['request']->getUri());
    }

    public function testKeyCdn(): void
    {
        $this->settings()->keycdnApiKey = 'sk_test';
        $this->settings()->keycdnZoneId = '1000';
        $this->settings()->keycdnZoneUrl = '';
        $keycdn = $this->fake(new KeyCdn());

        $this->assertTrue($keycdn->isConfigured());
        $keycdn->purgeUrls(['https://www.example.com/news?page=2', 'https://www.example.com/']);
        $request = $this->sent[0]['request'];
        $this->assertSame('DELETE', $request->getMethod());
        $this->assertSame('https://api.keycdn.com/zones/purgeurl/1000.json', (string)$request->getUri());
        $this->assertSame('Basic ' . base64_encode('sk_test:'), $request->getHeaderLine('Authorization'), 'The key as the user name.');
        $this->assertSame(['urls' => ['www.example.com/news?page=2', 'www.example.com/']], json_decode((string)$request->getBody(), true), 'Without the scheme.');

        $this->settings()->keycdnZoneUrl = 'https://example-1a2b.kxcdn.com/';
        $this->assertSame('example-1a2b.kxcdn.com/news', $keycdn->zoneUrl('https://www.example.com/news'), 'On the zone URL when one is set.');

        $keycdn->purgeAll();
        $this->assertSame('GET', $this->sent[1]['request']->getMethod());
        $this->assertSame('https://api.keycdn.com/zones/purge/1000.json', (string)$this->sent[1]['request']->getUri());

        $keycdn->test();
        $this->assertSame('https://api.keycdn.com/zones/1000.json', (string)$this->sent[2]['request']->getUri());
    }

    public function testVarnish(): void
    {
        $this->settings()->varnishUrls = "http://127.0.0.1:6081\nhttp://10.0.0.2:6081";
        $varnish = $this->fake(new Varnish());

        $this->assertSame(['http://127.0.0.1:6081', 'http://10.0.0.2:6081'], $this->settings()->getVarnishUrls());
        $varnish->purgeUrls(['https://example.com/news']);
        $this->assertCount(2, $this->sent, 'Every Varnish server is cleared.');
        $this->assertSame('PURGE', $this->sent[0]['request']->getMethod());
        $this->assertSame('http://127.0.0.1:6081/news', (string)$this->sent[0]['request']->getUri());
        $this->assertSame('example.com', $this->sent[0]['request']->getHeaderLine('Host'));
    }

    public function testFailuresCarryTheServiceMessage(): void
    {
        $this->settings()->fastlyToken = 't';
        $this->settings()->fastlyServiceId = 's';
        $stackPurger = new Fastly();
        $stack = HandlerStack::create(new MockHandler([new Response(401, [], '{"msg":"Provided credentials are missing or invalid"}')]));
        $stackPurger->client = new Client(['handler' => $stack, 'http_errors' => false]);

        $this->expectExceptionMessage('Provided credentials are missing or invalid');
        $stackPurger->purgeAll();
    }

    public function testConfiguredPurgersChangeCacheControl(): void
    {
        $cdn = $this->plugin()->cdn;
        $this->assertSame('no-cache, s-maxage=0', $cdn->cacheControl());
        $this->settings()->bunnyApiKey = 'k';
        $this->settings()->bunnyPullZoneId = '1';
        $this->assertContains('bunny', $cdn->configured());
        $this->assertStringContainsString('s-maxage=', $cdn->cacheControl());
        $this->assertStringContainsString('stale-while-revalidate=86400, stale-if-error=86400', $cdn->cacheControl(), 'The CDN sends the old copy while it refreshes, and while the site is down.');
        $this->settings()->cdnMaxAge = 600;
        $this->assertSame('public, s-maxage=600, max-age=0, stale-while-revalidate=600, stale-if-error=86400', $cdn->cacheControl(), 'For no longer than the max age.');
        $this->assertSame('Bunny', $cdn->names()['bunny']);
    }
}
