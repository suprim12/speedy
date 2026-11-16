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
        $this->assertTrue($cacher->isSiteHost($site, 443));
        $this->assertTrue($cacher->isSiteHost(strtoupper($site), 80));
        $this->assertFalse($cacher->isSiteHost($site, 1), 'A made-up port would end up in the page’s links.');
        $this->assertFalse($cacher->isSiteHost('attacker.example', 443));

        // A site whose base URL is @web answers to any host.
        $any = new \suprim\speedy\services\Cacher();
        (fn() => $this->_siteHosts = false)->call($any);
        $this->assertTrue($any->isSiteHost('anything.example', 443), 'Any host, as in 1.0…');
        $this->assertFalse($any->isSiteHost('anything.example', 8080), '…but only on the usual ports.');
        $this->settings()->allowedHosts = "example.com, localhost:8080";
        $this->assertTrue($any->isSiteHost('example.com', 443));
        $this->assertTrue($any->isSiteHost('localhost', 8080));
        $this->assertFalse($any->isSiteHost('anything.example', 443), 'With allowedHosts, only those.');
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
        $token = $warmer->token('example.com/news', $expires);

        $this->assertMatchesRegularExpression('/^\d+\.[a-f0-9]{64}$/', $token);
        $this->assertSame($token, $warmer->token('example.com/news', $expires));
        $this->assertNotSame($token, $warmer->token('example.com/about', $expires), 'Another page needs its own token.');
        $this->assertNotSame($token, $warmer->token('example.com/news', $expires + 1), 'The expiry is part of it.');
    }

    public function testRebuildsOnlyGoToTheSitesHosts(): void
    {
        $result = $this->plugin()->warmer->warm(['https://attacker.example/news'], false);
        $this->assertSame(['built' => 0, 'failed' => 1], $result, 'No request is sent to a host that isn’t a site’s.');
    }
}
