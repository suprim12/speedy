<?php

namespace suprim\speedy\tests\Integration;

use Craft;
use craft\elements\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use suprim\speedy\tests\TestCase;

/**
 * “Why isn’t this page cached?”: what each answer from the site is explained as.
 */
class PageCheckTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $sent = [];

    private function url(string $path = '/speedy-test-check'): string
    {
        $base = (string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl();

        return parse_url($base, PHP_URL_SCHEME) . '://' . parse_url($base, PHP_URL_HOST) . (parse_url($base, PHP_URL_PORT) ? ':' . parse_url($base, PHP_URL_PORT) : '') . $path;
    }

    /**
     * @param Response[] $responses What the site sends, visit by visit.
     * @return array{url: string, cached: bool, answer: string, why: ?string, fix: ?string, notes: string[], visits: string[]}
     */
    private function check(string $url, array $responses): array
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->sent));

        return $this->plugin()->pageCheck->check($url, new Client(['handler' => $stack, 'http_errors' => false, 'allow_redirects' => false]));
    }

    public function testOnlyTheSitesOwnPagesAreOpened(): void
    {
        $result = $this->check('https://example.org/admin', []);
        $this->assertSame('That isn’t one of this site’s addresses.', $result['answer']);
        $this->assertSame([], $this->sent, 'Nothing was requested.');
        $this->assertFalse($this->check('file:///etc/passwd', [])['cached']);
        $this->assertSame([], $this->sent);
    }

    public function testWithAnyHostSitesOnlyKnownHostsAreOpened(): void
    {
        // A site whose base URL is @web answers to any host; the check still only opens hosts known for certain.
        $cacher = $this->plugin()->cacher;
        $hosts = new \ReflectionProperty($cacher, '_siteHosts');
        $was = $hosts->getValue($cacher);
        $hosts->setValue($cacher, false);
        try {
            $this->settings()->allowedHosts = [];
            $result = $this->check('http://169.254.169.254/latest/meta-data/', []);
            $this->assertSame('That isn’t one of this site’s addresses.', $result['answer']);
            $this->assertSame([], $this->sent, 'Nothing was requested, and no basic authentication sent.');
            $this->assertFalse($cacher->isKnownSiteHost('10.0.0.5'));

            $this->settings()->allowedHosts = ['example.com'];
            $this->assertTrue($cacher->isKnownSiteHost('example.com'));
            $this->assertFalse($cacher->isKnownSiteHost('example.com', 8080), 'Nor another port.');
        } finally {
            $hosts->setValue($cacher, $was);
        }
    }

    public function testAPathIsOnTheSite(): void
    {
        $result = $this->check('/speedy-test-check', [new Response(200, ['X-Speedy' => 'STATIC'])]);
        $this->assertSame($this->url(), $result['url']);
        $this->assertTrue($result['cached']);
        $this->assertStringContainsString('without PHP', implode(' ', $result['notes']));
        $this->assertCount(1, $this->sent, 'Cached already: one visit is enough.');
        $this->assertSame('', $this->sent[0]['request']->getHeaderLine('Cookie'), 'As a logged-out visitor, with no cookies.');
    }

    public function testCachedOnTheSecondVisit(): void
    {
        $result = $this->check($this->url(), [new Response(200, ['X-Speedy' => 'MISS']), new Response(200, ['X-Speedy' => 'HIT'])]);
        $this->assertTrue($result['cached']);
        $this->assertStringContainsString('built on the first visit', $result['answer']);
        $this->assertStringContainsString('server rules', implode(' ', $result['notes']), 'Sent by PHP: the rules would be faster.');
        $this->assertCount(2, $result['visits']);
    }

    public function testBuiltButNotSaved(): void
    {
        $result = $this->check($this->url(), [new Response(200, ['X-Speedy' => 'MISS']), new Response(200, ['X-Speedy' => 'MISS'])]);
        $this->assertFalse($result['cached']);
        $this->assertSame('The page was built twice without being saved.', $result['answer']);
    }

    public function testQueryStringsNameTheParameters(): void
    {
        $this->settings()->cachedParams = ['page'];
        $result = $this->check($this->url('/homes?view=split&page=2&utm_source=news'), [new Response(200, ['X-Speedy' => 'SKIP query string'])]);
        $this->assertSame('Not cached: query string.', $result['answer']);
        $this->assertStringContainsString('`view`', (string)$result['why']);
        $this->assertStringNotContainsString('`page`', (string)$result['why'], 'Listed already.');
        $this->assertStringNotContainsString('utm_source', (string)$result['why'], 'Tracking parameters never matter.');
        $this->assertStringContainsString('Cached parameters', (string)$result['fix']);
    }

    public function testNeverCacheNamesThePattern(): void
    {
        $this->settings()->neverCache = ['account/*'];
        $result = $this->check($this->url('/account/orders'), [new Response(200, ['X-Speedy' => 'SKIP never cache'])]);
        $this->assertStringContainsString('`account/*`', (string)$result['why']);
    }

    public function testCookiesAreNamed(): void
    {
        $result = $this->check($this->url(), [new Response(200, ['X-Speedy' => 'SKIP sets a cookie', 'Set-Cookie' => ['tracker=abc; Path=/', 'theme=dark; Path=/']])]);
        $this->assertStringContainsString('`tracker`, `theme`', (string)$result['why']);
        $this->assertStringContainsString('{% header %}', (string)$result['fix']);
    }

    public function testStatusesAreExplained(): void
    {
        $redirect = $this->check($this->url(), [new Response(301, ['X-Speedy' => 'SKIP status 301', 'Location' => '/new-page'])]);
        $this->assertStringContainsString('redirects to `/new-page`', (string)$redirect['why']);
        $missing = $this->check($this->url(), [new Response(404, ['X-Speedy' => 'SKIP status 404'])]);
        $this->assertStringContainsString('doesn’t exist', (string)$missing['why']);
        $broken = $this->check($this->url(), [new Response(500, ['X-Speedy' => 'SKIP status 500'])]);
        $this->assertStringContainsString('error (500)', (string)$broken['why']);
    }

    public function testEveryReasonGetsAnAnswer(): void
    {
        foreach (['template', 'session', 'private', 'vary', 'disabled', 'unknown host', 'not html', 'too large', 'changed while building', 'cache full', 'too many query strings', 'not in only cache'] as $reason) {
            $result = $this->check($this->url(), [new Response(200, ['X-Speedy' => "SKIP $reason", 'Vary' => 'User-Agent'])]);
            $this->assertSame("Not cached: $reason.", $result['answer']);
            $this->assertNotNull($result['why'], $reason);
        }
    }

    public function testWithoutSpeedyOrUnreachable(): void
    {
        $plain = $this->check($this->url(), [new Response(200)]);
        $this->assertSame('Speedy didn’t handle this address.', $plain['answer']);

        $down = $this->check($this->url(), [new \GuzzleHttp\Exception\ConnectException('Connection refused', new \GuzzleHttp\Psr7\Request('GET', $this->url()))]);
        $this->assertStringContainsString('Connection refused', $down['answer']);
        $this->assertNotNull($down['fix']);
    }

    public function testLoggedInPeopleAreToldWhyTheySeeFreshPages(): void
    {
        $identity = Craft::$app->getUser()->getIdentity();
        Craft::$app->getUser()->setIdentity(new User(['id' => 987654321, 'admin' => true]));
        try {
            $result = $this->check($this->url(), [new Response(200, ['X-Speedy' => 'STATIC'])]);
            $this->assertStringContainsString('You’re logged in', implode(' ', $result['notes']));
        } finally {
            Craft::$app->getUser()->setIdentity($identity);
        }
    }
}
