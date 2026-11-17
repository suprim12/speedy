<?php

namespace suprim\speedy\tests\Integration;

use Craft;
use craft\helpers\Json;
use suprim\speedy\tests\TestCase;

/**
 * A page built before its content was cleared isn’t saved, lock files don’t pile up, and older tokens still work.
 */
class StaleAndLocksTest extends TestCase
{
    protected function tearDown(): void
    {
        Craft::$app->getCache()->delete('speedy:cleared:' . md5('speedy-test-stale'));
        Craft::$app->getCache()->delete('speedy:cleared:' . md5('speedy-test-other'));
        parent::tearDown();
    }

    public function testKnowsWhenATagWasClearedDuringABuild(): void
    {
        $clearer = $this->plugin()->clearer;
        $started = microtime(true);
        usleep(2000);
        $clearer->clearTags(['speedy-test-stale'], 'test');

        $this->assertTrue($clearer->clearedSince($started, ['element::1', 'speedy-test-stale']), 'Built from what was there before the clear.');
        $this->assertFalse($clearer->clearedSince(microtime(true), ['speedy-test-stale']), 'Started after the clear: fine.');
        $this->assertFalse($clearer->clearedSince($started, ['speedy-test-other']), 'Another tag: fine.');
    }

    public function testOldUnusedLockFilesAreRemoved(): void
    {
        $storage = $this->plugin()->storage;
        $old = $storage->lockFile('example.com/old');
        $new = $storage->lockFile('example.com/new');
        touch($old, time() - 7200);
        touch($new);

        \Craft::$app->getCache()->delete('speedy:prunedLocks');
        $storage->pruneLocks();
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($new, 'Recent ones stay.');
    }

    public function testDynamicPartsOnPagesCachedBy10StillRender(): void
    {
        $security = Craft::$app->getSecurity();
        $dynamic = $this->plugin()->dynamic;
        $dir = Craft::getAlias('@templates/speedy-tests-' . bin2hex(random_bytes(3)));
        mkdir($dir, 0775, true);
        file_put_contents("$dir/part.twig", 'Hello {{ name }}');
        $template = basename($dir) . '/part';
        $view = Craft::$app->getView();
        $mode = $view->getTemplateMode();
        try {
            $old = $security->hashData(Json::encode(['t' => $template, 'v' => ['name' => 'Alex'], 's' => Craft::$app->getSites()->getPrimarySite()->id]));
            $this->assertStringContainsString('Hello Alex', (string)$dynamic->render($old), 'A token from a page cached by 1.0.');

            $other = $security->hashData(Json::encode(['t' => $template, 'v' => [], 'redirect' => '/x']));
            $this->assertNull($dynamic->render($other), 'Something else Craft signed isn’t a token.');
        } finally {
            $view->setTemplateMode($mode);
            unlink("$dir/part.twig");
            rmdir($dir);
        }
    }

    public function testDisabledHereButLiveElsewhereStillClears(): void
    {
        $method = new \ReflectionMethod($this->plugin()->clearer, 'enabledElsewhere');
        $entry = \craft\elements\Entry::find()->status(null)->site('*')->unique()->one();
        if (!$entry || !Craft::$app->getIsMultiSite()) {
            $this->markTestSkipped('Needs more than one site.');
        }
        $sites = (new \craft\db\Query())->from(\craft\db\Table::ELEMENTS_SITES)->select('siteId')->where(['elementId' => $entry->id, 'enabled' => true])->column();
        $other = array_values(array_diff(array_map('intval', $sites), [(int)$entry->siteId]));
        $this->assertSame($other !== [] && $entry->enabled, $method->invoke($this->plugin()->clearer, $entry));
    }

    public function testRebuildingWhenNothingIsCachedDoesNothing(): void
    {
        $plugin = $this->plugin();
        $this->assertSame(0, $plugin->clearer->rebuildAll('Rebuild everything'));
        $this->assertSame([], $plugin->log->recent(1), 'No “0 pages” entry in the log.');
    }
}
