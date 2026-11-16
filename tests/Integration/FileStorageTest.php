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
}
