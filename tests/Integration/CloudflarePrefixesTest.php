<?php

namespace suprim\speedy\tests\Integration;

use Craft;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use suprim\speedy\jobs\PurgeJob;
use suprim\speedy\tests\TestCase;

/**
 * Clearing a whole part of a site clears Cloudflare by prefix or host: also the copies Speedy doesn’t know of, such as
 * with tracking parameters, in one request.
 */
class CloudflarePrefixesTest extends TestCase
{
    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $sent = [];

    private int $lastJob = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $stack = HandlerStack::create(new MockHandler(array_fill(0, 10, new Response(200, [], '{"success":true}'))));
        $stack->push(Middleware::history($this->sent));
        $this->plugin()->cdn->cloudflareClient = new Client(['handler' => $stack]);
        $this->settings()->cloudflareToken = 'cf-token';
        $this->settings()->cloudflareZones = ['example.com' => 'zone-com', 'example.de' => 'zone-de'];
        $this->lastJob = (int)(new \craft\db\Query())->from('{{%queue}}')->max('id');
    }

    protected function tearDown(): void
    {
        $this->plugin()->cdn->cloudflareClient = null;
        Craft::$app->getDb()->createCommand()->delete('{{%queue}}', ['>', 'id', $this->lastJob])->execute();
        parent::tearDown();
    }

    /**
     * @return array<int, array{zone: string, body: array<string, mixed>}>
     */
    private function sent(): array
    {
        return array_map(function($call) {
            preg_match('#/zones/([^/]+)/purge_cache#', (string)$call['request']->getUri(), $m);
            return ['zone' => $m[1], 'body' => json_decode((string)$call['request']->getBody(), true)];
        }, $this->sent);
    }

    public function testPatternsThatArePrefixes(): void
    {
        $clearer = $this->plugin()->clearer;
        $this->assertSame(['prefixes' => ['example.com/news/']], $clearer->cloudflareParts('https://example.com/news/*'));
        $this->assertSame(['prefixes' => ['example.com/news/']], $clearer->cloudflareParts('https://Example.com/news/**?utm=1'));
        $this->assertSame(['hosts' => ['example.com']], $clearer->cloudflareParts('https://example.com/*'));
        foreach (['https://example.com/news*', 'https://example.com/*/news/*', 'https://example.com/news', 'https://*.example.com/news/*', 'https://example.com:8080/news/*', 'ftp://example.com/news/*'] as $pattern) {
            $this->assertSame([], $clearer->cloudflareParts($pattern), "$pattern: cleared by URL, as a prefix would mean something else.");
        }
        $this->settings()->allowedHosts = ['example.com', 'example.de'];
        $hosts = $this->plugin()->cacher->certainHosts();
        $parts = $clearer->cloudflareParts('/news/*');
        $this->assertSame(array_map(fn($h) => "$h/news/", $hosts), $parts['prefixes'], 'A path, on each of the sites’ domains.');
    }

    public function testAPatternClearSendsThePrefixOnce(): void
    {
        $storage = $this->plugin()->storage;
        $storage->write('example.com/news/a', 'x', 'https://example.com/news/a', [], null);
        $storage->write('example.com/news/b', 'x', 'https://example.com/news/b', [], null);
        $storage->write('example.com/about', 'x', 'https://example.com/about', [], null);

        $this->assertSame(2, $this->plugin()->clearer->clearUrl('https://example.com/news/*'));
        $this->assertSame([['zone' => 'zone-com', 'body' => ['prefixes' => ['example.com/news/']]]], $this->sent(), 'One prefix, not each URL.');
        $this->assertTrue($storage->exists('example.com/about'));

        // Nothing cached under it: Cloudflare may still have copies.
        $this->sent = [];
        $this->assertSame(0, $this->plugin()->clearer->clearUrl('https://example.de/blog/*'));
        $this->assertSame([['zone' => 'zone-de', 'body' => ['prefixes' => ['example.de/blog/']]]], $this->sent());
    }

    public function testOtherPatternsStillClearByUrl(): void
    {
        $this->plugin()->storage->write('example.com/news-a', 'x', 'https://example.com/news-a', [], null);
        $this->plugin()->clearer->clearUrl('https://example.com/news*');
        $this->assertSame([['zone' => 'zone-com', 'body' => ['files' => ['https://example.com/news-a']]]], $this->sent());
    }

    public function testRebuiltPagesAreClearedFromCloudflareAfterTheRebuilds(): void
    {
        $this->settings()->rebuildCleared = true;
        $this->plugin()->storage->write('example.com/news/a', 'x', 'https://example.com/news/a', [], null);
        $this->plugin()->clearer->clearUrl('https://example.com/news/*');
        $this->assertSame([], $this->sent(), 'Not yet: Cloudflare would fetch the old copy again.');

        $jobs = (new \craft\db\Query())->select(['job', 'priority'])->from('{{%queue}}')->where(['>', 'id', $this->lastJob])->orderBy('id')->all();
        $purge = array_values(array_filter($jobs, fn($j) => str_contains((string)$j['job'], 'PurgeJob')));
        $warm = array_values(array_filter($jobs, fn($j) => str_contains((string)$j['job'], 'WarmJob')));
        $this->assertCount(1, $purge);
        $this->assertCount(1, $warm);
        $this->assertGreaterThan((int)$warm[0]['priority'], (int)$purge[0]['priority'], 'It runs after the rebuild.');

        (new PurgeJob(['cloudflare' => ['prefixes' => ['example.com/news/']]]))->execute(null);
        $this->assertSame([['zone' => 'zone-com', 'body' => ['prefixes' => ['example.com/news/']]]], $this->sent());
    }

    public function testASiteClearSendsItsHost(): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $host = strtolower((string)parse_url((string)$site->getBaseUrl(), PHP_URL_HOST));
        $path = trim((string)parse_url((string)$site->getBaseUrl(), PHP_URL_PATH), '/');
        $raw = (string)\craft\helpers\App::parseEnv((string)$site->getBaseUrl(false));
        if (str_contains($raw, '@web') || parse_url((string)$site->getBaseUrl(), PHP_URL_PORT)) {
            $this->markTestSkipped('The primary site’s domain isn’t known for certain.');
        }
        $this->settings()->cloudflareZoneId = 'zone-default';
        $this->plugin()->clearer->clearSites([(int)$site->id]);
        $this->assertSame([['zone' => 'zone-default', 'body' => $path === '' ? ['hosts' => [$host]] : ['prefixes' => ["$host/$path"]]]], $this->sent());
    }

    public function testOtherCdnsKeepTheirUrls(): void
    {
        // Without Cloudflare, prefixes alone clear nothing anywhere.
        $this->settings()->cloudflareToken = '';
        $this->plugin()->cdn->purgeUrls([], ['prefixes' => ['example.com/news/']]);
        $this->assertSame([], $this->sent());
    }
}
