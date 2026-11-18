<?php

namespace suprim\speedy\tests\Integration;

use suprim\speedy\tests\TestCase;

class FileStorageTest extends TestCase
{
    public function testWritesReadsAndDeletesAPage(): void
    {
        $storage = $this->plugin()->storage;
        $storage->write('example.com/news', '<html>News</html>', 'https://example.com/news', ['element::1'], null, ['X-Robots-Tag' => 'noindex']);

        $this->assertTrue($storage->exists('example.com/news'));
        $this->assertFileExists("$this->tmp/web/example.com/news/index.html");
        $this->assertFileExists("$this->tmp/web/example.com/news/index.html.gz");
        $this->assertSame('<html>News</html>', $storage->read('example.com/news', false)['content']);
        $gz = $storage->read('example.com/news', true);
        $this->assertTrue($gz['gzip']);
        $this->assertSame('<html>News</html>', gzdecode($gz['content']));
        $this->assertSame('noindex', $storage->meta('example.com/news')['headers']['X-Robots-Tag']);

        $this->assertSame('https://example.com/news', $storage->delete('example.com/news'));
        $this->assertFalse($storage->exists('example.com/news'));
        $this->assertFileDoesNotExist("$this->tmp/web/example.com/news/index.html.gz");
    }

    public function testDeletingAPageLeavesNoEmptyFolders(): void
    {
        $storage = $this->plugin()->storage;
        $storage->write('example.com/blog', 'blog', 'https://example.com/blog', [], null);
        $storage->write('example.com/blog/2026/hello', 'post', 'https://example.com/blog/2026/hello', [], null);

        $storage->delete('example.com/blog/2026/hello');
        $this->assertDirectoryDoesNotExist("$this->tmp/web/example.com/blog/2026");
        $this->assertDirectoryDoesNotExist("$this->tmp/data/meta/example.com/blog/2026");
        $this->assertTrue($storage->exists('example.com/blog'), 'A parent page is kept.');

        $storage->delete('example.com/blog');
        $this->assertDirectoryDoesNotExist("$this->tmp/web/example.com");
        $this->assertDirectoryExists("$this->tmp/web", 'The cache folder itself stays.');
    }

    public function testTheCacheFolderCantBeOpenedDirectlyOnApache(): void
    {
        $this->plugin()->storage->write('example.com/news', 'news', 'https://example.com/news', [], null);
        $htaccess = (string)file_get_contents("$this->tmp/web/.htaccess");

        $this->assertStringContainsString('RewriteCond %{ENV:REDIRECT_STATUS} ^$', $htaccess);
        $this->assertStringContainsString('SPEEDY_GZ', $htaccess, 'Compressed pages are still marked, as this file replaces the site’s rules here.');
    }

    public function testHomepageAndSubpagesDontClash(): void
    {
        $storage = $this->plugin()->storage;
        $storage->write('example.com', 'home', 'https://example.com/', [], null);
        $storage->write('example.com/about', 'about', 'https://example.com/about', [], null);

        $this->assertSame('home', $storage->read('example.com', false)['content']);
        $this->assertSame('about', $storage->read('example.com/about', false)['content']);
        $this->assertEqualsCanonicalizing(['example.com', 'example.com/about'], iterator_to_array($storage->allKeys(), false));
        $this->assertSame(2, $storage->stats()['pages']);
    }

    public function testFindsPagesByTagOnce(): void
    {
        $storage = $this->plugin()->storage;
        $storage->write('example.com/a', 'a', 'https://example.com/a', ['element::1', 'section:news'], null);
        $storage->write('example.com/b', 'b', 'https://example.com/b', ['section:news'], null);
        $storage->write('example.com/c', 'c', 'https://example.com/c', ['element::2'], null);

        $this->assertSame(2, $storage->countPagesForTag('section:news'));
        $this->assertEqualsCanonicalizing(['example.com/a', 'example.com/b'], $storage->takePagesForTags(['section:news', 'element::1']));
        $this->assertSame([], $storage->takePagesForTags(['section:news']), 'A tag’s index is used up once its pages are cleared.');
    }

    public function testFindsExpiredPages(): void
    {
        $storage = $this->plugin()->storage;
        $now = time();
        $storage->write('example.com/old', 'x', 'https://example.com/old', [], $now - 10);
        $storage->write('example.com/later', 'x', 'https://example.com/later', [], $now + 7200);

        $this->assertSame(['example.com/old'], $storage->takeExpired($now));
        $this->assertSame([], $storage->takeExpired($now));
        $this->assertNotNull($storage->nextExpiry());
    }

    public function testClearAllKeepsALinkedFolderLinked(): void
    {
        // As with releases that share web/speedy: the release’s folder is a link to the shared one.
        $shared = "$this->tmp/shared";
        mkdir($shared, 0775, true);
        symlink($shared, "$this->tmp/release-web");
        $this->settings()->cachePath = "$this->tmp/release-web";
        $storage = $this->plugin()->storage;
        $storage->write('example.com/news', 'news', 'https://example.com/news', ['element::1'], null);
        $this->assertFileExists("$shared/example.com/news/index.html", 'Pages land in the shared folder.');

        $this->assertTrue($storage->clearAll());
        $this->assertTrue(is_link("$this->tmp/release-web"), 'The link stays, so releases keep sharing the folder.');
        $this->assertFalse($storage->exists('example.com/news'));
        $this->assertSame([], glob("$shared/*") ?: [], 'The shared folder is emptied.');

        $storage->deleteTrash();
        $this->assertDirectoryExists($shared, 'Deleting the trash leaves the shared folder.');
        $storage->write('example.com/about', 'about', 'https://example.com/about', [], null);
        $this->assertFileExists("$shared/example.com/about/index.html", 'New pages still land in the shared folder.');
    }

    public function testClearAllNeverEmptiesAFolderLinkedByMistake(): void
    {
        // Such as web/speedy linked to persist/web instead of persist/web/speedy: it holds other files.
        $wrong = "$this->tmp/persist-web";
        mkdir("$wrong/assets", 0775, true);
        file_put_contents("$wrong/assets/photo.jpg", 'photo');
        symlink($wrong, "$this->tmp/release-web");
        $this->settings()->cachePath = "$this->tmp/release-web";

        $this->assertFalse($this->plugin()->storage->clearAll(), 'Says nothing was cleared.');
        $this->assertFileExists("$wrong/assets/photo.jpg", 'Nothing in it is touched.');
        $this->assertTrue(is_link("$this->tmp/release-web"));
    }

    public function testClearAllWithAnEmptyOrBrokenLink(): void
    {
        mkdir("$this->tmp/shared", 0775, true);
        symlink("$this->tmp/shared", "$this->tmp/release-web");
        $this->settings()->cachePath = "$this->tmp/release-web";
        $this->assertTrue($this->plugin()->storage->clearAll(), 'An empty shared folder, before its first page, clears fine.');

        symlink("$this->tmp/gone", "$this->tmp/broken");
        $this->settings()->cachePath = "$this->tmp/broken";
        $this->assertTrue($this->plugin()->storage->clearAll(), 'A link to nothing has nothing to clear.');
    }

    public function testClearAllMovesFilesAsideThenDeletesThem(): void
    {
        $storage = $this->plugin()->storage;
        $storage->write('example.com/a', 'a', 'https://example.com/a', ['t'], null);
        $storage->clearAll();

        $this->assertFalse($storage->exists('example.com/a'));
        $this->assertSame(0, $storage->stats()['pages']);
        $this->assertDirectoryExists("$this->tmp/data/trash");
        $storage->deleteTrash();
        $this->assertDirectoryDoesNotExist("$this->tmp/data/trash");
    }

    public function testThePageCountIsCachedUntilEverythingIsCleared(): void
    {
        $storage = $this->plugin()->storage;
        $storage->write('example.com/a', 'a', 'https://example.com/a', [], null);
        $this->assertSame(1, $storage->cachedStats()['pages']);
        $storage->write('example.com/b', 'b', 'https://example.com/b', [], null);
        $this->assertSame(1, $storage->cachedStats()['pages'], 'Counted at most every five minutes.');
        $this->assertSame(2, $storage->stats()['pages']);

        $storage->clearAll();
        $this->assertSame(0, $storage->cachedStats()['pages'], 'Counted again after clearing everything.');
    }

    public function testCachedPagesAreKeptOutOfGit(): void
    {
        $storage = $this->plugin()->storage;
        $storage->write('example.com/news', '<html>News</html>', 'https://example.com/news', [], null);
        $ignore = $storage->cacheRoot() . '/.gitignore';
        $this->assertFileExists($ignore);
        $this->assertContains('*', array_map('trim', file($ignore)), 'Everything in the folder, itself included.');

        // Back after clearing everything, with the next page.
        $storage->clearAll();
        $storage->write('example.com/news', '<html>News</html>', 'https://example.com/news', [], null);
        $this->assertFileExists($ignore);
    }

    public function testOldTrashIsDeletedWithoutTheQueue(): void
    {
        $storage = $this->plugin()->storage;
        $storage->write('example.com/news', '<html>News</html>', 'https://example.com/news', [], null);
        $storage->clearAll();
        $trash = glob($storage->dataRoot() . '/trash/*', GLOB_ONLYDIR) ?: [];
        $this->assertNotEmpty($trash, 'Clearing everything moves the pages to the trash.');

        // Recent trash is left for the queue job.
        foreach ($trash as $dir) {
            touch($dir);
        }
        $storage->deleteOldTrash();
        $this->assertSame(count($trash), count(glob($storage->dataRoot() . '/trash/*', GLOB_ONLYDIR) ?: []));

        // Trash the queue never got to is deleted by cron or garbage collection.
        foreach ($trash as $dir) {
            touch($dir, time() - 3600);
        }
        $storage->deleteOldTrash();
        $this->assertSame([], glob($storage->dataRoot() . '/trash/*', GLOB_ONLYDIR) ?: []);
    }

    public function testNewPagesStopAtTheSizeLimit(): void
    {
        $storage = $this->plugin()->storage;
        $this->assertFalse($storage->overSizeLimit(), 'No limit by default.');

        $this->settings()->maxCacheSize = 1;
        $storage->write('example.com/big', '<html>' . random_bytes(1200000) . '</html>', 'https://example.com/big', [], null);
        \Craft::$app->getCache()->delete('speedy:stats:' . md5($storage->cacheRoot()));
        $this->assertTrue($storage->overSizeLimit());
        $this->assertStringContainsString('1 MB allowed by maxCacheSize', implode(' ', array_column($this->plugin()->insights->checks(), 'message')));

        $this->settings()->maxCacheSize = 50;
        \Craft::$app->getCache()->delete('speedy:stats:' . md5($storage->cacheRoot()));
        $this->assertFalse($storage->overSizeLimit());
    }

    public function testCheckingWhetherTheCacheFolderCanBeOpened(): void
    {
        $storage = $this->plugin()->storage;
        $host = (string)parse_url((string)\Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), PHP_URL_HOST);
        $url = 'https://' . $host . '/';
        $rules = $this->plugin()->serverRules;
        $client = fn(int $status, string $body) => new \GuzzleHttp\Client(['handler' => \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([new \GuzzleHttp\Psr7\Response($status, [], $body)]))]);

        $this->assertNull($rules->folderIsOpen($client(200, '<html>'), $url), 'Unknown until the homepage is cached.');
        $storage->write((string)$storage->keyForUrl($url), '<html>Home</html>', $url, [], null);
        $this->settings()->cachePath = \Craft::getAlias('@webroot') . '/speedy-test-' . bin2hex(random_bytes(3));
        $storage->write((string)$storage->keyForUrl($url), '<html>Home</html>', $url, [], null);
        try {
            $this->assertTrue($rules->folderIsOpen($client(200, '<html>Home</html>'), $url), 'Sent at its own address: open.');
            $this->assertFalse($rules->folderIsOpen($client(403, 'Forbidden'), $url), 'Refused: closed.');
            $this->assertFalse($rules->folderIsOpen($client(404, ''), $url));
        } finally {
            \craft\helpers\FileHelper::removeDirectory($storage->cacheRoot());
        }
    }

    public function testOnlyAFolderOfItsOwn(): void
    {
        $this->assertTrue(\suprim\speedy\services\Storage::isOwnFolder(\Craft::getAlias('@webroot/speedy')));
        $this->assertTrue(\suprim\speedy\services\Storage::isOwnFolder(\Craft::getAlias('@storage/speedy')));
        foreach (['@webroot', '@root', '@storage', '@templates', '@vendor', '@config'] as $alias) {
            $this->assertFalse(\suprim\speedy\services\Storage::isOwnFolder(\Craft::getAlias($alias)), $alias);
        }
        $this->assertFalse(\suprim\speedy\services\Storage::isOwnFolder(dirname(\Craft::getAlias('@webroot'))), 'A folder holding the web root.');
        $this->assertFalse(\suprim\speedy\services\Storage::isOwnFolder('/'));

        $settings = new \suprim\speedy\models\Settings(['cachePath' => '@webroot']);
        $this->assertFalse($settings->validate(['cachePath']));
        $settings = new \suprim\speedy\models\Settings(['cachePath' => '@webroot/speedy']);
        $this->assertTrue($settings->validate(['cachePath']));
    }

    public function testAFolderHoldingOtherFilesIsNeverClearedOrIgnored(): void
    {
        // A pretend site in the temporary folder: its templates live inside the cache path.
        $site = "$this->tmp/site";
        \craft\helpers\FileHelper::createDirectory("$site/templates");
        file_put_contents("$site/templates/index.twig", 'Home');
        $templates = \Craft::getAlias('@templates');
        \Craft::setAlias('@templates', "$site/templates");
        $this->settings()->cachePath = $site;
        $storage = $this->plugin()->storage;
        try {
            $this->assertSame($site, $storage->unsafeFolder());
            $storage->write('example.com/news', '<html>News</html>', 'https://example.com/news', [], null);
            $this->assertFileDoesNotExist("$site/.gitignore", 'Not marked as ignored.');
            $this->assertFileDoesNotExist("$site/example.com/news/index.html", 'Nothing written.');
            $this->assertFalse($storage->clearAll(), 'Says so.');
            $this->assertFalse($this->plugin()->clearer->clearAll('test'), 'And so does clearing everything.');
            $this->assertFileExists("$site/templates/index.twig", 'Not moved away.');
            $this->assertStringContainsString('holds other files', implode(' ', array_column($this->plugin()->insights->checks(), 'message')));
        } finally {
            \Craft::setAlias('@templates', $templates);
        }
    }

    public function testUploadAndResourceFoldersAreNeverTheCacheFolder(): void
    {
        $resources = (string)\Craft::getAlias(\Craft::$app->getConfig()->getGeneral()->resourceBasePath);
        $this->assertFalse(\suprim\speedy\services\Storage::isOwnFolder($resources), 'Craft’s published resources.');
        $this->assertFalse(\suprim\speedy\services\Storage::isOwnFolder("$resources/speedy"), 'Nor a folder inside them.');
        foreach (\Craft::$app->getFs()->getAllFilesystems() as $fs) {
            if ($fs instanceof \craft\fs\Local) {
                $this->assertFalse(\suprim\speedy\services\Storage::isOwnFolder($fs->getRootPath()), "Uploads: {$fs->name}.");
            }
        }
        $this->assertTrue(\suprim\speedy\services\Storage::isOwnFolder((string)\Craft::getAlias('@webroot/speedy')), 'The usual folder still is.');
    }

    public function testOldTrashIsFound(): void
    {
        $storage = $this->plugin()->storage;
        $this->settings()->dataPath = "$this->tmp/data";
        \craft\helpers\FileHelper::createDirectory("$this->tmp/data/trash/1");
        $this->assertFalse($storage->hasOldTrash(), 'Recent: the queue job takes it.');
        $this->assertTrue($storage->hasOldTrash(-60), 'Older than the age given.');
    }

    public function testAFilesystemAtTheWebRootLeavesTheUsualFolderAlone(): void
    {
        $fs = \Craft::$app->getFs();
        $volumes = \Craft::$app->getVolumes();
        $fsCache = new \ReflectionProperty($fs, '_filesystems');
        $volumeCache = new \ReflectionProperty($volumes, '_volumes');
        $wasFs = $fsCache->getValue($fs);
        $wasVolumes = $volumeCache->getValue($volumes);
        $root = new \craft\fs\Local(['name' => 'Web root', 'handle' => 'speedyTestWebroot', 'path' => '@webroot']);
        $volume = new \craft\models\Volume(['name' => 'Uploads', 'handle' => 'speedyTestUploads', 'fsHandle' => 'speedyTestWebroot', 'subpath' => 'uploads']);
        $fsCache->setValue($fs, new \craft\base\MemoizableArray([$root]));
        $volumeCache->setValue($volumes, new \craft\base\MemoizableArray([$volume]));
        try {
            $webroot = (string)\Craft::getAlias('@webroot');
            $this->assertTrue(\suprim\speedy\services\Storage::isOwnFolder("$webroot/speedy"), 'Beside the uploads, as on many sites.');
            $this->assertFalse(\suprim\speedy\services\Storage::isOwnFolder("$webroot/uploads"), 'The uploads themselves.');
            $this->assertFalse(\suprim\speedy\services\Storage::isOwnFolder("$webroot/uploads/speedy"), 'Or a folder inside them.');
        } finally {
            $fsCache->setValue($fs, $wasFs);
            $volumeCache->setValue($volumes, $wasVolumes);
        }
    }
}
