<?php

namespace suprim\speedy\tests\Integration;

use suprim\speedy\helpers\InstantLinks;
use suprim\speedy\tests\TestCase;

/**
 * Opening pages ahead (prerendering): the rules, and the scripts that wait until the page is shown.
 */
class PrerenderTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function rules(bool $prerender): array
    {
        $script = InstantLinks::script([], [], '', $prerender);

        return json_decode(substr($script, strlen('<script type="speculationrules">'), -strlen('</script>')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testPrerenderRulesOnlyWhenAskedFor(): void
    {
        $this->assertSame(['prefetch'], array_keys($this->rules(false)));
        $rules = $this->rules(true);
        $this->assertSame(['prerender', 'prefetch'], array_keys($rules), 'Prefetch stays, for links past the browser’s limit of pages opened ahead.');
        $this->assertSame('moderate', $rules['prerender'][0]['eagerness'], 'Only once the visitor points at a link.');
        $this->assertSame($rules['prefetch'][0]['where'], $rules['prerender'][0]['where'], 'The same links are left out, such as signing out.');
        $this->assertFalse((new \suprim\speedy\models\Settings())->instantLinksPrerender, 'Off by default.');
    }

    public function testMarkedScriptsWaitAndRunInOrder(): void
    {
        $html = '<html><head><script data-speedy-wait src="/a.js"></script><script data-speedy-wait type="module">b()</script>'
            . '<script type="application/ld+json" data-speedy-wait>{"x":1}</script><script>c()</script><script data-speedy-waits>d()</script></head><body></body></html>';
        $held = InstantLinks::holdScripts($html, []);

        $this->assertStringContainsString('<script type="speedy/wait" data-speedy-wait src="/a.js">', $held);
        $this->assertStringContainsString('<script type="speedy/wait" data-speedy-type="module" data-speedy-wait>b()</script>', $held, 'Its own type comes back when it runs.');
        $this->assertStringContainsString('<script type="application/ld+json" data-speedy-wait>', $held, 'Data isn’t a script to hold.');
        $this->assertStringContainsString('<script>c()</script>', $held, 'Unmarked scripts are left alone.');
        $this->assertStringContainsString('<script data-speedy-waits>d()</script>', $held, 'Only the attribute itself.');
        $this->assertSame(1, substr_count($held, 'prerenderingchange'), 'One script runs them once the page is shown.');
        $this->assertMatchesRegularExpression('#prerenderingchange.*</script></body>#s', $held);
        $this->assertSame($held, InstantLinks::holdScripts($held, []), 'Held once, however often it’s asked.');
    }

    public function testNothingIsHeldWhereThePolicyWouldBlockTheScriptThatRunsThem(): void
    {
        $html = '<html><body><script data-speedy-wait>a()</script></body></html>';
        $this->assertSame($html, InstantLinks::holdScripts($html, ['Content-Security-Policy' => "script-src 'self' 'nonce-abc'"]), 'They’d never run.');
        $this->assertSame('<html><body></body></html>', InstantLinks::holdScripts('<html><body></body></html>', []), 'Nothing marked, nothing added.');
        $meta = '<html><head><meta http-equiv="Content-Security-Policy" content="script-src \'self\'"></head><body><script data-speedy-wait>a()</script></body></html>';
        $this->assertSame($meta, InstantLinks::holdScripts($meta, []));
    }

    public function testSpeedysOwnScriptsWaitUntilThePageIsShown(): void
    {
        $this->assertSame('(function(f){document.prerendering?document.addEventListener("prerenderingchange",f,{once:true}):f()})(function(){x()});', InstantLinks::whenShown('function(){x()}'));
        $dynamic = (new \ReflectionMethod($this->plugin()->dynamic, 'loaderScript'))->invoke($this->plugin()->dynamic);
        $this->assertStringStartsWith("(function(f){document.prerendering?document.addEventListener('prerenderingchange'", $dynamic, 'Dynamic parts.');
        $this->assertStringEndsWith('});', trim($dynamic));
    }

    public function testSpeculativeRequestsAreRecognised(): void
    {
        foreach (['prefetch' => true, 'prefetch;prerender' => true, 'Prefetch' => true, '' => false] as $purpose => $speculative) {
            $request = new \craft\web\Request();
            if ($purpose !== '') {
                $request->getHeaders()->set('Sec-Purpose', $purpose);
            }
            $this->assertSame($speculative, InstantLinks::isSpeculative($request), $purpose);
        }
    }

    public function testChangingItRebuildsThePages(): void
    {
        $plugin = $this->plugin();
        $plugin->storage->writeData('state', ['instantLinks' => true]);
        $this->assertFalse($plugin->instantLinksChanged(true, false), 'Cached before there was prerendering: built without it.');
        $this->assertTrue($plugin->instantLinksChanged(true, true));
        $this->assertFalse($plugin->instantLinksChanged(true, true), 'Once.');
        $this->assertTrue($plugin->instantLinksChanged(true, false));
        $this->assertTrue($plugin->instantLinksChanged(false, true));
        $this->assertFalse($plugin->instantLinksChanged(false, false), 'Without instant links, prerendering changes nothing.');
    }
}
