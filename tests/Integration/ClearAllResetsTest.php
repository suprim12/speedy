<?php

namespace suprim\speedy\tests\Integration;

use Craft;
use craft\db\Query;
use craft\db\Table;
use suprim\speedy\jobs\WarmJob;
use suprim\speedy\tests\TestCase;

/**
 * Clearing everything also forgets what was about the old pages: the Performance report and Speedy’s failed jobs.
 */
class ClearAllResetsTest extends TestCase
{
    private int $lastJob = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lastJob = (int)(new Query())->from(Table::QUEUE)->max('id');
    }

    protected function tearDown(): void
    {
        // Only the jobs this test queued.
        Craft::$app->getDb()->createCommand()->delete(Table::QUEUE, ['>', 'id', $this->lastJob])->execute();
        parent::tearDown();
    }

    public function testClearAllForgetsTheReportAndSpeedysFailedJobs(): void
    {
        $this->skipIfTheSiteHasFailedSpeedyJobs();
        $storage = $this->plugin()->storage;
        $storage->writeData('queries', ['https://example.com/' => ['url' => 'https://example.com/', 'time' => time(), 'queries' => 900, 'repeated' => [['count' => 50, 'sql' => 'x', 'at' => null, 'tip' => 'x']], 'broad' => []]]);
        $storage->writeData('slow', ['https://example.com/' => ['url' => 'https://example.com/', 'ms' => 9000, 'queries' => 900, 'time' => time()]]);

        // A failed rebuild of Speedy’s, and another plugin’s failed job.
        $queue = Craft::$app->getQueue();
        $speedy = $queue->push(new WarmJob(['urls' => ['https://example.com/speedy-test-failed'], 'rebuild' => true]));
        $other = $queue->push(new \craft\queue\jobs\ResaveElements(['elementType' => \craft\elements\Entry::class, 'description' => 'Not Speedy’s']));
        Craft::$app->getDb()->createCommand()->update(Table::QUEUE, ['fail' => true, 'error' => 'test'], ['id' => [$speedy, $other]])->execute();

        $this->assertTrue($this->plugin()->clearer->clearAll('test'));

        $this->assertSame([], $this->plugin()->queries->report(), 'The Performance report starts again.');
        $this->assertSame([], $this->plugin()->queries->slowest());
        $ids = (new Query())->from(Table::QUEUE)->where(['id' => [$speedy, $other]])->column();
        $this->assertNotContains((string)$speedy, array_map('strval', $ids), 'Speedy’s failed job is gone.');
        $this->assertContains((string)$other, array_map('strval', $ids), 'Another plugin’s is left alone.');
    }
}
