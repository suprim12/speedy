<?php

namespace suprim\speedy\tests\Http;

use Craft;
use craft\elements\Entry;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use suprim\speedy\Plugin;

/**
 * An entry’s address with its letters in another case, such as `/News` for `/news`: MySQL finds the entry either way,
 * but only the URI’s own spelling is cached, so made-up spellings can’t fill the cache. Needs SPEEDY_TEST_URL and a
 * live entry whose page loads and isn’t cached yet; it removes only the copies it made.
 */
class LetterCaseTest extends TestCase
{
    private static string $base;

    private static ?Entry $entry = null;

    /** @var string[] The keys of the copies these tests made. */
    private static array $made = [];

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
        $client = new Client(['http_errors' => false, 'verify' => false, 'cookies' => false, 'allow_redirects' => false]);
        $storage = Plugin::getInstance()->storage;
        foreach (Entry::find()->uri(':notempty:')->status('live')->limit(30)->all() as $entry) {
            $url = $entry->getUrl();
            // A URI with letters, on this host, not cached yet, whose page loads.
            if (!$url || !preg_match('/[a-z]/', (string)$entry->uri) || !str_starts_with($url, self::$base . '/') || $storage->exists((string)$storage->keyForUrl($url))) {
                continue;
            }
            // Opened in the other spelling, so this doesn’t cache it.
            $upper = self::$base . '/' . strtoupper(substr($url, strlen(self::$base) + 1));
            try {
                if ($client->head($upper)->getStatusCode() === 200) {
                    self::$entry = $entry;
                    self::$made[] = (string)$storage->keyForUrl($url);
                    self::$made[] = (string)$storage->keyForUrl($upper);
                    break;
                }
            } catch (\Throwable) {
            }
        }
        if (!self::$entry) {
            self::markTestSkipped('No live entry whose page loads and isn’t cached yet.');
        }
    }

    public static function tearDownAfterClass(): void
    {
        $storage = Plugin::getInstance()->storage;
        foreach (self::$made as $key) {
            if ($key !== '' && $storage->exists($key)) {
                $storage->delete($key);
            }
        }
        self::$made = [];
        self::$entry = null;
    }

    private function get(string $url): ResponseInterface
    {
        return (new Client(['http_errors' => false, 'verify' => false, 'cookies' => false, 'allow_redirects' => false]))->get($url);
    }

    public function testOnlyTheUrisOwnSpellingIsCached(): void
    {
        $url = (string)self::$entry->getUrl();
        $upper = self::$base . '/' . strtoupper(substr($url, strlen(self::$base) + 1));
        $storage = Plugin::getInstance()->storage;

        // The other spelling first, before the URI’s own copy exists (some file systems would find that one for it).
        foreach ([1, 2] as $visit) {
            $response = $this->get($upper);
            $this->assertSame(200, $response->getStatusCode(), 'The page still loads.');
            $this->assertSame('SKIP url case', $response->getHeaderLine('X-Speedy'), "Visit $visit.");
        }
        $this->assertFalse($storage->exists((string)$storage->keyForUrl($upper)));

        $this->assertNotSame('', (string)$this->get($url)->getBody());
        $this->assertTrue($storage->exists((string)$storage->keyForUrl($url)), 'The URI’s own spelling is cached.');
    }

    public function testOldCopiesInOtherCaseAreRemovedQuietly(): void
    {
        $url = (string)self::$entry->getUrl();
        $other = self::$base . '/' . ucfirst(substr($url, strlen(self::$base) + 1));
        if ($other === $url) {
            $this->markTestSkipped('The URI starts with something other than a letter.');
        }
        $storage = Plugin::getInstance()->storage;
        $before = $storage->readData('state')['rebuildFailed'] ?? null;

        Plugin::getInstance()->warmer->warm([$other], true);

        $this->assertSame($before, $storage->readData('state')['rebuildFailed'] ?? null, 'Not reported as a rebuild that failed.');
        $this->assertArrayNotHasKey($other, Plugin::getInstance()->warmer->unbuilt(), 'Nor as a page that couldn’t be built.');
    }
}
