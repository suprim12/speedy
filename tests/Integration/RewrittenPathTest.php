<?php

namespace suprim\speedy\tests\Integration;

use craft\web\Request;
use suprim\speedy\tests\TestCase;

/**
 * Telling how a site’s index.php changed the address before Craft started, from the server’s own copy of it.
 */
class RewrittenPathTest extends TestCase
{
    private array $server = [];

    private string|false $env = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = $_SERVER;
        $this->env = getenv('REQUEST_URI');
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        putenv($this->env === false ? 'REQUEST_URI' : 'REQUEST_URI=' . $this->env);
        parent::tearDown();
    }

    private function rewritten(?string $asked, string $now, string $baseUrl = '', ?string $redirectUrl = null): string|false|null
    {
        putenv($asked === null ? 'REQUEST_URI' : "REQUEST_URI=$asked");
        $_SERVER['REQUEST_URI'] = $now;
        unset($_SERVER['REDIRECT_URL']);
        if ($redirectUrl !== null) {
            $_SERVER['REDIRECT_URL'] = $redirectUrl;
        }
        $request = new Request();
        $request->setBaseUrl($baseUrl);

        return $this->plugin()->cacher->rewrittenPath($request);
    }

    public function testUnchangedAddresses(): void
    {
        $this->assertNull($this->rewritten('/news/a?page=2', '/news/a?page=2'));
        $this->assertNull($this->rewritten('/news/a/', '/news/a'), 'A trailing slash is the same page.');
        $this->assertNull($this->rewritten('/caf%C3%A9', '/café'), 'Encoded or not.');
        $this->assertNull($this->rewritten(null, '/news'), 'Nothing to compare with.');
    }

    public function testTakingPartOffKeepsTheAddressAskedFor(): void
    {
        $this->assertSame('interactive/communities/aston-hills', $this->rewritten('/interactive/communities/aston-hills/', '/communities/aston-hills/'));
        $this->assertSame('interactive', $this->rewritten('/interactive', '/'), 'The home page.');
        $this->assertSame('interactive', $this->rewritten('/interactive?x=1', '/?x=1'));
        $this->assertSame('news/print', $this->rewritten('/news/print', '/news'), 'From the end.');
        $this->assertSame('a/a/b', $this->rewritten('/a/a/b', '/a/b'));
        $this->assertSame('de/interactive/news', $this->rewritten('/craft/de/interactive/news', '/craft/de/news', '/craft'), 'Within the site’s folder, as Craft’s own path.');
    }

    public function testOtherChangesAreLeftUncached(): void
    {
        $this->assertFalse($this->rewritten('/', '/en/'), 'Adding a language, which may come from the browser.');
        $this->assertFalse($this->rewritten('/about.html', '/about'));
        $this->assertFalse($this->rewritten('/swap/a', '/other/a'));
        $this->assertFalse($this->rewritten('/interactive/news', '/news', '/craft'), 'Outside the site’s folder.');
    }

    public function testApachesRedirectUrlWhenTheEnvironmentHasNone(): void
    {
        $this->assertSame('interactive/news', $this->rewritten(null, '/news?p=news', '', '/interactive/news'));
        $this->assertNull($this->rewritten(null, '/caf%C3%A9', '', '/café'), 'Apache’s copy is decoded.');
    }
}
