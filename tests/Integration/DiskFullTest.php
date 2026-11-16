<?php

namespace suprim\speedy\tests\Integration;

use Craft;
use suprim\speedy\services\Storage;
use suprim\speedy\tests\TestCase;

/**
 * A full disk never leaves a half-saved page, or one that wouldn’t clear, and stops new pages being saved.
 */
class DiskFullTest extends TestCase
{
    protected function tearDown(): void
    {
        Craft::$app->getCache()->delete('speedy:freeSpace');
        if (is_dir("$this->tmp/data/tags")) {
            @chmod("$this->tmp/data/tags", 0775);
        }
        parent::tearDown();
    }

    public function testAPageThatCantBeFullySavedIsTakenOut(): void
    {
        $storage = $this->plugin()->storage;
        $storage->write('example.com/a', 'old', 'https://example.com/a', ['element::1'], null);
        $this->assertTrue($storage->exists('example.com/a'));

        // The tag list can’t be written, as on a full disk.
        $tags = "$this->tmp/data/tags";
        foreach (glob("$tags/*") ?: [] as $dir) {
            \craft\helpers\FileHelper::removeDirectory($dir);
        }
        chmod($tags, 0555);
        if (is_writable($tags)) {
            $this->markTestSkipped('Running as root, so a read-only folder can’t be simulated.');
        }

        try {
            $storage->write('example.com/a', 'new', 'https://example.com/a', ['element::1'], null);
            $this->fail('The failed save should be reported.');
        } catch (\Throwable) {
        }
        $this->assertFalse($storage->exists('example.com/a'), 'A page that wouldn’t clear when its content changes isn’t sent.');
        $this->assertFileDoesNotExist($storage->htmlFile('example.com/a') . '.gz');
    }

    public function testNewPagesArentSavedWhenTheDiskIsNearlyFull(): void
    {
        $storage = $this->plugin()->storage;
        $this->assertFalse($storage->diskFull(), 'This machine has room.');

        Craft::$app->getCache()->set('speedy:freeSpace', 50 * 1024 * 1024, 60);
        $this->assertTrue($storage->diskFull());
        $this->assertSame(50 * 1024 * 1024, $storage->freeSpace());
        $this->assertStringContainsString('disk is nearly full (50 MB free)', implode(' ', array_column($this->plugin()->insights->checks(), 'message')));

        Craft::$app->getCache()->set('speedy:freeSpace', Storage::MIN_FREE_BYTES + 1, 60);
        $this->assertFalse($storage->diskFull());
    }
}
