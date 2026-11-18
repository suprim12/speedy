<?php

namespace suprim\speedy\tests\Http;

use Craft;
use craft\elements\GlobalSet;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use suprim\speedy\Plugin;

/**
 * Pages that show a global set, such as a footer’s contact details, are cleared when it’s saved. Craft gives every
 * template every global set, so that’s usually every page. Needs SPEEDY_TEST_URL.
 * Makes its own global set and template, and removes them afterwards.
 */
class GlobalSetsTest extends TestCase
{
    private static string $base;
    private static string $prefix;
    private static string $dir;
    private static ?GlobalSet $set = null;

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
        $handle = 'speedyTest' . bin2hex(random_bytes(3));
        self::$set = new GlobalSet(['name' => 'Speedy test ' . $handle, 'handle' => $handle]);
        if (!Craft::$app->getGlobals()->saveSet(self::$set)) {
            self::$set = null;
            self::markTestSkipped('Couldn’t make a global set.');
        }
        self::$dir = Craft::getAlias('@templates/' . self::$prefix);
        mkdir(self::$dir, 0775, true);
        file_put_contents(self::$dir . '/footer.twig', '<html><body>Footer {{ ' . $handle . '.name }} {{ now|date("U.u") }}</body></html>');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$set) {
            Craft::$app->getGlobals()->deleteSet(self::$set);
            self::$set = null;
        }
        if (isset(self::$dir) && is_dir(self::$dir)) {
            foreach (glob(self::$dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir(self::$dir);
        }
        $storage = Plugin::getInstance()->storage;
        foreach ($storage->allKeys() as $key) {
            if (isset(self::$prefix) && str_contains($key, self::$prefix)) {
                $storage->delete($key);
            }
        }
    }

    private function key(string $page): string
    {
        return (string)Plugin::getInstance()->storage->key((string)parse_url(self::$base, PHP_URL_HOST), self::$prefix . '/' . $page);
    }

    public function testSavingAGlobalSetClearsThePagesShowingIt(): void
    {
        $client = new Client(['base_uri' => self::$base, 'http_errors' => false, 'verify' => false, 'cookies' => false]);
        $client->get('/' . self::$prefix . '/footer');
        $this->assertContains($client->get('/' . self::$prefix . '/footer')->getHeaderLine('X-Speedy'), ['HIT', 'STATIC'], 'Cached.');

        $settings = Plugin::getInstance()->getSettings();
        [$rebuild, $inRequest] = [$settings->rebuildCleared, $settings->clearInRequest];
        $settings->rebuildCleared = false;
        $settings->clearInRequest = true;
        try {
            $set = GlobalSet::find()->id(self::$set->id)->one();
            $set->name .= ' renamed';
            $this->assertTrue(Craft::$app->getGlobals()->saveSet($set));
            Plugin::getInstance()->clearer->flush();
        } finally {
            [$settings->rebuildCleared, $settings->clearInRequest] = [$rebuild, $inRequest];
        }

        $storage = Plugin::getInstance()->storage;
        $this->assertFalse($storage->exists($this->key('footer')), 'The page showing the global set is cleared.');
        $this->assertStringContainsString(' renamed', (string)$client->get('/' . self::$prefix . '/footer')->getBody());
    }
}
