<?php

namespace suprim\speedy\tests\Http;

use Craft;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use suprim\speedy\Plugin;

/**
 * Sites whose index.php changes the address before Craft starts, such as taking a prefix off to show a page another
 * way: each address keeps its own copy. Needs SPEEDY_TEST_URL and a site that rewrites as these tests expect:
 * /speedy-rewrite-view/<path> shows /<path> with SPEEDY_TEST_VIEW set, and /speedy-rewrite-swap/<path> opens
 * /speedy-rewrite-other/<path>.
 */
class RewrittenTest extends TestCase
{
    private static string $base;
    private static string $prefix;

    /** @var string[] */
    private static array $dirs = [];

    public static function setUpBeforeClass(): void
    {
        $base = getenv('SPEEDY_TEST_URL');
        if (!$base) {
            self::markTestSkipped('Set SPEEDY_TEST_URL to run the http tests.');
        }
        if (Craft::$app->getPlugins()->getPlugin('blitz')) {
            self::markTestSkipped('Another page cache (Blitz) is enabled, so pages are marked uncacheable. Disable it to run these tests.');
        }
        self::$base = rtrim($base, '/');
        self::$prefix = 'speedy-tests-' . bin2hex(random_bytes(3));
        $page = '<html><body>{{ getenv("SPEEDY_TEST_VIEW") ? "Touchscreen" : "Normal" }} {{ now|date("U.u") }}</body></html>';
        foreach ([self::$prefix => ['view' => $page], 'speedy-rewrite-other/' . self::$prefix => ['swap' => $page]] as $folder => $templates) {
            $dir = Craft::getAlias('@templates/' . $folder);
            mkdir($dir, 0775, true);
            self::$dirs[] = $dir;
            foreach ($templates as $name => $twig) {
                file_put_contents("$dir/$name.twig", $twig);
            }
        }
        $probe = (new Client(['http_errors' => false, 'verify' => false]))->get(self::$base . '/speedy-rewrite-view/' . self::$prefix . '/view');
        if (!str_contains((string)$probe->getBody(), 'Touchscreen')) {
            self::tearDownAfterClass();
            self::markTestSkipped('The site doesn’t rewrite /speedy-rewrite-view/ addresses in its index.php.');
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$dirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
        $other = Craft::getAlias('@templates/speedy-rewrite-other');
        if (is_dir($other) && !(glob($other . '/*') ?: [])) {
            rmdir($other);
        }
        self::$dirs = [];
        $storage = Plugin::getInstance()->storage;
        foreach ($storage->allKeys() as $key) {
            if (isset(self::$prefix) && str_contains($key, self::$prefix)) {
                $storage->delete($key);
            }
        }
    }

    private function get(string $path, array $options = []): ResponseInterface
    {
        return (new Client(['base_uri' => self::$base, 'http_errors' => false, 'verify' => false, 'cookies' => false]))->get($path, $options);
    }

    private function key(string $path): string
    {
        return (string)Plugin::getInstance()->storage->key((string)parse_url(self::$base, PHP_URL_HOST), $path);
    }

    public function testEachAddressKeepsItsOwnCopy(): void
    {
        $normal = '/' . self::$prefix . '/view';
        $touch = '/speedy-rewrite-view' . $normal;

        // The rewritten address first, as on the site where they got mixed up.
        $this->assertStringContainsString('Touchscreen', (string)$this->get($touch)->getBody());
        $first = $this->get($normal);
        $this->assertStringContainsString('Normal', (string)$first->getBody(), 'The normal page isn’t the copy built for the other address.');
        $this->assertNotSame('HIT', $first->getHeaderLine('X-Speedy'));

        foreach ([$touch => 'Touchscreen', $normal => 'Normal'] as $path => $shows) {
            $again = $this->get($path);
            $this->assertContains($again->getHeaderLine('X-Speedy'), ['HIT', 'STATIC'], "$path is cached.");
            $this->assertStringContainsString($shows, (string)$again->getBody(), "$path keeps its own version.");
        }

        $storage = Plugin::getInstance()->storage;
        $meta = $storage->meta($this->key('speedy-rewrite-view' . $normal));
        $this->assertSame(self::$base . $touch, $meta['url'] ?? null, 'Stored with the address that was asked for, so it rebuilds itself.');
        $this->assertTrue($storage->exists($this->key(ltrim($normal, '/'))));
    }

    public function testTheRewrittenAddressRebuildsItself(): void
    {
        $touch = self::$base . '/speedy-rewrite-view/' . self::$prefix . '/view';
        $this->get(parse_url($touch, PHP_URL_PATH));
        $storage = Plugin::getInstance()->storage;
        $key = $this->key('speedy-rewrite-view/' . self::$prefix . '/view');
        $before = (string)($storage->read($key, false)['content'] ?? '');

        usleep(10000);
        $this->assertSame(['built' => 1, 'failed' => 0], Plugin::getInstance()->warmer->warm([$touch], true), 'Its rebuild token fits the address it was sent to.');
        $after = (string)($storage->read($key, false)['content'] ?? '');
        $this->assertNotSame($before, $after, 'A new copy.');
        $this->assertStringContainsString('Touchscreen', $after);
    }

    public function testOtherChangesAreNotCached(): void
    {
        $path = '/speedy-rewrite-swap/' . self::$prefix . '/swap';
        foreach ([1, 2] as $visit) {
            $response = $this->get($path);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('SKIP rewritten URL', $response->getHeaderLine('X-Speedy'), "Visit $visit.");
        }
        $this->assertFalse(Plugin::getInstance()->storage->exists($this->key(ltrim($path, '/'))));
        $this->assertFalse(Plugin::getInstance()->storage->exists($this->key('speedy-rewrite-other/' . self::$prefix . '/swap')));
    }
}
