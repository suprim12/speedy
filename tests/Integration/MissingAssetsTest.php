<?php

namespace suprim\speedy\tests\Integration;

use Craft;
use suprim\speedy\services\Insights;
use suprim\speedy\tests\TestCase;

/**
 * Cached pages that link to CSS or JavaScript that’s gone, such as after a deploy replaced the built files, checked
 * when someone clicks Check CSS and JavaScript.
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

    public function testOnlyFilesInFoldersOnDiskCount(): void
    {
        // Such as a script Craft or a plugin sends at the top, or from a folder that isn’t on disk.
        $this->plugin()->storage->write('example.com/routed', '<script src="/sw.js"></script><script src="/speedy-test-no-such-folder/app.js"></script>', 'https://example.com/routed', [], null);
        $this->assertSame(0, $this->plugin()->insights->missingAssets()['pages']);
    }

    public function testExamplesAreShownSafely(): void
    {
        $this->plugin()->storage->write('example.com/odd', '<script src="' . $this->folder . '/x`y&#96;z [a](b).js"></script>', 'https://example.com/odd', [], null);
        $this->assertSame($this->folder . '/xyzab.js', $this->plugin()->insights->missingAssets()['example']);
    }

    public function testCachedPagesLinkingToMissingFilesAreFound(): void
    {
        $storage = $this->plugin()->storage;
        $insights = $this->plugin()->insights;
        $storage->write('example.com/fine', '<link rel="stylesheet" href="' . $this->folder . '/main-new.css">', 'https://example.com/fine', [], null);
        $this->assertSame(['pages' => 0, 'checked' => 1, 'example' => null], $insights->missingAssets());

        // A page built before the deploy, linking to the old build.
        $storage->write('example.com/old', '<link rel="stylesheet" href="' . $this->folder . '/main-old.css">', 'https://example.com/old', [], null);
        $missing = $insights->missingAssets();
        $this->assertSame(1, $missing['pages']);
        $this->assertSame($this->folder . '/main-old.css', $missing['example']);

        $storage->clearAll();
        $this->assertSame(['pages' => 0, 'checked' => 0, 'example' => null], $insights->missingAssets(), 'Nothing to check once cleared.');
    }
}
