<?php

namespace suprim\speedy\tests\Integration;

use Craft;
use suprim\speedy\helpers\InstantLinks;
use suprim\speedy\tests\TestCase;

/**
 * Instant links: which links browsers may fetch ahead, and pages the rules are never added to.
 */
class InstantLinksTest extends TestCase
{
    private const PAGE = '<html><head></head><body><a href="/news">News</a></body></html>';

    /**
     * @return array<string, mixed>
     */
    private function rules(array $neverCache = [], array $onlyCache = [], string $sitePath = ''): array
    {
        $script = InstantLinks::script($neverCache, $onlyCache, $sitePath);
        $this->assertMatchesRegularExpression('#^<script type="speculationrules">\{.*\}</script>$#', $script);

        return json_decode(substr($script, strlen('<script type="speculationrules">'), -strlen('</script>')), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return string[]
     */
    private function skipped(array $rules): array
    {
        return $rules['prefetch'][0]['where']['and'][1]['not']['href_matches'];
    }

    public function testOnlyFetchesTheHtmlOfSameSiteLinksWhenPointedAt(): void
    {
        $rules = $this->rules();
        $this->assertSame(['prefetch'], array_keys($rules), 'Prefetch only: prerendering would run the page’s scripts, such as analytics.');
        $this->assertSame('moderate', $rules['prefetch'][0]['eagerness'], 'When pointed at, not every link on the page.');
        $this->assertSame('document', $rules['prefetch'][0]['source']);
        $this->assertSame(['href_matches' => '/*'], $rules['prefetch'][0]['where']['and'][0], 'The site’s own links only.');
    }

    public function testLinksThatDoSomethingAreNeverFetched(): void
    {
        $general = Craft::$app->getConfig()->getGeneral();
        $skipped = $this->skipped($this->rules());
        foreach (['/' . $general->actionTrigger . '/*', '/' . $general->cpTrigger, '/' . $general->cpTrigger . '/*', '/index.php', '/index.php/*', '/*.*'] as $path) {
            $this->assertContains($path, $skipped);
        }
        foreach (['loginPath', 'logoutPath', 'setPasswordPath', 'verifyEmailPath'] as $setting) {
            foreach ((array)$general->$setting as $path) {
                if (is_string($path) && $path !== '') {
                    $this->assertContains('/' . trim($path, '/'), $skipped, $setting);
                }
            }
        }

        $selector = $this->rules()['prefetch'][0]['where']['and'][2]['not']['selector_matches'];
        foreach (['[data-no-prefetch]', '[data-no-prefetch] [href]', '[download]', '[target]:not([target=""], [target="_self"], [target="_top"], [target="_parent"])', '[rel~="nofollow"]', '[href*="?"]', '[href^="#"]'] as $part) {
            $this->assertStringContainsString($part, $selector);
        }
    }

    public function testPagesThatArentCachedAreNotFetched(): void
    {
        $rules = $this->rules(['checkout/*', 'account'], [], '/de/');
        $skipped = $this->skipped($rules);
        foreach (['/checkout/*', '/de/checkout/*', '/account', '/account/', '/de/account', '/de/account/'] as $path) {
            $this->assertContains($path, $skipped);
        }
        $this->assertCount(3, $rules['prefetch'][0]['where']['and'], 'Every page may be fetched without Only cache.');

        $rules = $this->rules([], ['news*', '/']);
        $this->assertSame(['href_matches' => ['/news*', '/']], $rules['prefetch'][0]['where']['and'][3], 'With Only cache, only those pages.');
    }

    public function testPatternCharactersAreMatchedAsThemselves(): void
    {
        $this->assertSame(['/a\\(b\\)\\:c\\+d\\?e\\{f\\}', '/a\\(b\\)\\:c\\+d\\?e\\{f\\}/'], InstantLinks::paths(['a(b):c+d?e{f}']));
        $this->assertSame(['/news/*'], InstantLinks::paths(['/news/*']), 'A wildcard at the end needs no slash version.');

        $script = InstantLinks::script(['</script><script>alert(1)</script>'], []);
        $this->assertSame(1, substr_count(strtolower($script), '</script>'), 'A pattern can’t end the script early.');
    }

    public function testAddedBeforeTheEndOfTheBody(): void
    {
        $html = InstantLinks::add(self::PAGE, [], [], []);
        $this->assertStringContainsString('<script type="speculationrules">', $html);
        $this->assertStringEndsWith('</script></body></html>', $html);
        $this->assertSame(1, substr_count($html, 'speculationrules'));

        $this->assertSame('<p>Fragment</p>', InstantLinks::add('<p>Fragment</p>', [], [], []), 'Not added to a page without a body.');
        $own = '<html><body><script type=speculationrules>{"prefetch":[]}</script></body></html>';
        $this->assertSame($own, InstantLinks::add($own, [], [], []), 'Left to pages with rules of their own.');
    }

    public function testLeftOutWhenTheContentSecurityPolicyWouldBlockThem(): void
    {
        $blocked = [
            "script-src 'self'",
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'nonce-abc123'",
            "script-src 'unsafe-inline' 'sha256-abc='",
            "script-src 'unsafe-inline' 'strict-dynamic'",
            "script-src-elem 'self'; script-src 'unsafe-inline'",
            "img-src *, script-src 'self'",
        ];
        foreach ($blocked as $policy) {
            $this->assertSame(self::PAGE, InstantLinks::add(self::PAGE, ['content-security-policy' => [$policy]], [], []), $policy);
        }
        $this->assertSame(self::PAGE, InstantLinks::add(self::PAGE, ['Content-Security-Policy-Report-Only' => "default-src 'self'"], [], []), 'Report-only would report every page.');
        $meta = '<html><head><meta http-equiv="Content-Security-Policy" content="script-src \'self\'"></head><body></body></html>';
        $this->assertSame($meta, InstantLinks::add($meta, [], [], []), 'A policy in the page itself.');

        $allowed = [
            "script-src 'self' 'inline-speculation-rules'",
            "script-src 'self' 'nonce-abc' 'inline-speculation-rules'",
            "script-src 'self' 'unsafe-inline'",
            "default-src 'self' 'unsafe-inline'",
            'img-src *; frame-ancestors none',
        ];
        foreach ($allowed as $policy) {
            $this->assertNotSame(self::PAGE, InstantLinks::add(self::PAGE, ['content-security-policy' => [$policy]], [], []), $policy);
        }
    }

    public function testTurningThemOnOrOffRebuildsThePages(): void
    {
        $plugin = $this->plugin();
        $storage = $plugin->storage;
        $state = $storage->readData('state');
        $pending = new \ReflectionProperty($plugin->clearer, '_rebuildAll');
        $was = $pending->getValue($plugin->clearer);
        try {
            $without = $state;
            unset($without['instantLinks']);
            $storage->writeData('state', $without);
            $this->assertFalse($plugin->instantLinksChanged(false), 'Pages cached before instant links existed don’t have them.');
            $this->assertTrue($plugin->instantLinksChanged(true));
            $this->assertFalse($plugin->instantLinksChanged(true), 'Once.');
            $this->assertTrue($plugin->instantLinksChanged(false));

            // Saved in the control panel or applied by a deploy: here off, as this site has them.
            $pending->setValue($plugin->clearer, null);
            $plugin->checkInstantLinks();
            $this->assertNull($pending->getValue($plugin->clearer), 'Nothing changed.');
            $plugin->instantLinksChanged(true);
            $plugin->checkInstantLinks();
            $this->assertSame('Instant links turned off', $pending->getValue($plugin->clearer));
        } finally {
            $storage->writeData('state', $state);
            $pending->setValue($plugin->clearer, $was);
        }
    }

    public function testOffByDefault(): void
    {
        $this->assertFalse((new \suprim\speedy\models\Settings())->instantLinks);
        $settings = new \suprim\speedy\models\Settings();
        $settings->setAttributes(['instantLinks' => '1'], false);
        $this->assertTrue($settings->instantLinks, 'From the settings page’s switch.');
        $settings->setAttributes(['instantLinks' => ''], false);
        $this->assertFalse($settings->instantLinks);
    }
}
