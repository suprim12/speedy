<?php

namespace suprim\speedy\tests\Integration;

use Craft;
use suprim\speedy\services\Insights;
use suprim\speedy\tests\TestCase;

/**
 * Cached pages that link to CSS or JavaScript that’s gone, such as after a deploy replaced the built files.
 */
class MissingAssetsTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->folder = '/speedy-test-assets-' . bin2hex(random_bytes(3));
        mkdir(Craft::getAlias('@webroot') . $this->folder, 0775, true);
        file_put_contents(Craft::getAlias('@webroot') . $this->folder . '/main-new.css', 'body{}');
    }

    protected function tearDown(): void
    {
        $dir = Craft::getAlias('@webroot') . $this->folder;
        @unlink("$dir/main-new.css");
        @rmdir($dir);
        Craft::$app->getCache()->delete(Insights::MISSING_ASSETS_CACHE);
        parent::tearDown();
    }

    public function testFindsTheSitesOwnCssAndJavascript(): void
    {
        $html = '<link rel="stylesheet" href="/dist/a.css?v=2"><link rel="icon" href="/favicon.css">'
            . '<link rel="modulepreload" href="https://example.com/dist/b.js"><script src="//cdn.example.net/c.js"></script>'
            . '<script type="module" src="https://Example.com/cpresources/1a2b/js/formie.js?v=1"></script><script>inline()</script>'
            . '<script src="data:text/javascript,1"></script><script src="relative.js"></script><script src="/a/../b.js"></script>';

        $this->assertSame(['/dist/a.css', '/dist/b.js', '/cpresources/1a2b/js/formie.js'], Insights::assetPaths($html, 'example.com'),
            'Stylesheets and scripts on its own host, without the query string. Not icons, other hosts, inline scripts or odd paths.');
    }

    public function testCachedPagesLinkingToMissingFilesAreFound(): void
    {
        $storage = $this->plugin()->storage;
        $insights = $this->plugin()->insights;
        $storage->write('example.com/fine', '<link rel="stylesheet" href="' . $this->folder . '/main-new.css">', 'https://example.com/fine', [], null);
        $this->assertSame(['pages' => 0, 'checked' => 1, 'example' => null], $insights->missingAssets(true));

        // A page built before the deploy, linking to the old build.
        $storage->write('example.com/old', '<link rel="stylesheet" href="' . $this->folder . '/main-old.css">', 'https://example.com/old', [], null);
        $missing = $insights->missingAssets(true);
        $this->assertSame(1, $missing['pages']);
        $this->assertSame($this->folder . '/main-old.css', $missing['example']);
        $this->assertSame($missing, $insights->missingAssets(), 'Checked at most every few minutes.');

        $messages = implode("\n", array_column($insights->checks(), 'message'));
        $this->assertStringContainsString('CSS or JavaScript that no longer exists', $messages);

        $storage->clearAll();
        $this->assertSame(0, $insights->missingAssets()['pages'], 'Checked again once the pages are cleared.');
    }
}
