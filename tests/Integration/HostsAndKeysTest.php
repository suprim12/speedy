<?php

namespace suprim\speedy\tests\Integration;

use suprim\speedy\tests\TestCase;

/**
 * Keys never leave the cache folder, addresses map back to their page, and rebuild tokens only fit one page.
 */
class HostsAndKeysTest extends TestCase
{
    public function testOnlyRealHostNamesMakeKeys(): void
    {
        $storage = $this->plugin()->storage;
        foreach (['.', '..', '...', '.example.com', 'example..com', '-a.com', 'a-.com', '', 'exa mple.com', 'ex/ample.com'] as $host) {
            $this->assertNull($storage->key($host, '/news'), "“{$host}” isn’t a host name.");
        }
        $this->assertSame('example.com/news', $storage->key('Example.com', '/news'));
        $this->assertSame('localhost:8080', $storage->key('localhost:8080', '/'));
        $this->assertSame('127.0.0.1/a', $storage->key('127.0.0.1', '/a'));
        $this->assertSame('my-site.co.uk', $storage->key('my-site.co.uk', ''));
    }

    public function testHostsAndPorts(): void
    {
        $cacher = $this->plugin()->cacher;
        $site = (string)parse_url((string)\Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), PHP_URL_HOST);
        $this->assertTrue($cacher->isSiteHost($site, null, 443), 'No port in the address.');
        $this->assertTrue($cacher->isSiteHost(strtoupper($site), 443, 443));
        $this->assertFalse($cacher->isSiteHost($site, 1, 443), 'A made-up port in the Host header would end up in the page’s links.');
        $this->assertFalse($cacher->isSiteHost('attacker.example', null, 443));

        // A site whose base URL is @web answers to any host.
        $any = new \suprim\speedy\services\Cacher();
        (fn() => $this->_siteHosts = false)->call($any);
        $this->assertTrue($any->isSiteHost('anything.example', null, 443), 'Any host, as in 1.0…');
        $this->assertTrue($any->isSiteHost('localhost', 8080, 8080), '…on the port the server is really on, such as a local site…');
        $this->assertFalse($any->isSiteHost('anything.example', 1, 443), '…but not a port only the Host header claims.');
        $this->settings()->allowedHosts = "example.com, localhost:8080";
        $this->assertTrue($any->isSiteHost('example.com', null, 443));
        $this->assertTrue($any->isSiteHost('localhost', 8080, 8080));
        $this->assertFalse($any->isSiteHost('anything.example', null, 443), 'With allowedHosts, only those.');
    }

    public function testAddressesWithEncodedCharactersMapToTheirPage(): void
    {
        $storage = $this->plugin()->storage;
        $this->assertSame('example.com/news/café au lait', $storage->keyForUrl('https://example.com/news/caf%C3%A9%20au%20lait'));
        $this->assertSame($storage->key('example.com', '/news/café'), $storage->keyForUrl('https://example.com/news/café'));
    }

    public function testQueryStringsTooLongForAFolderName(): void
    {
        $storage = $this->plugin()->storage;
        $this->assertNotNull($storage->key('example.com', '/news', ['page' => '2']));
        // Under 200 bytes, but each of these letters takes 6 bytes once encoded.
        $this->assertNull($storage->key('example.com', '/news', ['q' => str_repeat('é', 60)]));
    }

    public function testRebuildTokensOnlyFitOnePage(): void
    {
        $warmer = $this->plugin()->warmer;
        $expires = time() + 900;
        $token = $warmer->token('https://example.com/news', $expires);

        $this->assertMatchesRegularExpression('/^\d+\.[a-f0-9]{64}$/', $token);
        $this->assertSame($token, $warmer->token('http://Example.com/news/?speedy-warm=abc', $expires), 'The same page however it’s written: scheme, case, trailing slash, the one-off parameter.');
        $this->assertSame($warmer->token('https://example.com/caf%C3%A9', $expires), $warmer->token('https://example.com/café', $expires), 'Encoded or not.');
        $this->assertSame($warmer->token('https://example.com/news?b=2&a=1', $expires), $warmer->token('https://example.com/news?a=1&b=2', $expires), 'Query order.');
        $this->assertNotSame($token, $warmer->token('https://example.com/about', $expires), 'Another page needs its own token.');
        $this->assertNotSame($token, $warmer->token('https://example.com/news?page=2', $expires), 'Another version of the page too.');
        $this->assertNotSame($token, $warmer->token('https://example.com/news', $expires + 1), 'The expiry is part of it.');
        // Craft in a subfolder: the site sees the same address the rebuild was sent to.
        $this->assertSame($warmer->token('https://example.com/craft/de/news', $expires), $warmer->tokenFor('example.com', '/craft/de/news', [], $expires));
    }

    public function testRedirectsAreShownShort(): void
    {
        $short = fn(string $to) => \suprim\speedy\services\Warmer::shortUrl($to, 'https://example.com/now-selling');
        $this->assertSame('/now-selling/land', $short('https://Example.com/now-selling/land'));
        $this->assertSame('/now-selling/land?a=1', $short('/now-selling/land?a=1'));
        $this->assertSame('https://other.example/x', $short('https://other.example/x'), 'Another host is named in full.');
    }

    public function testRebuildsOnlyGoToTheSitesHosts(): void
    {
        $result = $this->plugin()->warmer->warm(['https://attacker.example/news'], false);
        $this->assertSame(['built' => 0, 'failed' => 1], $result, 'No request is sent to a host that isn’t a site’s.');
    }
}
