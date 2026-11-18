<?php

namespace suprim\speedy\tests\Integration;

use Craft;
use craft\db\Query;
use suprim\speedy\jobs\WarmJob;
use suprim\speedy\tests\TestCase;

/**
 * Warm jobs stop starting pages before the queue’s time runs out, and leave the rest to a job of their own.
 */
class WarmTimeTest extends TestCase
{
    private int $lastJob = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lastJob = (int)(new Query())->from('{{%queue}}')->max('id');
    }

    protected function tearDown(): void
    {
        // Only the jobs these tests queued.
        Craft::$app->getDb()->createCommand()->delete('{{%queue}}', ['>', 'id', $this->lastJob])->execute();
        parent::tearDown();
    }

    public function testOnlyOnePageIsStartedAfterTheTime(): void
    {
        $urls = ['https://example.com/speedy-test-a', 'https://example.com/speedy-test-b', 'https://example.com/speedy-test-c'];
        $left = null;
        // The first is started anyway, so a job that starts late still makes progress. (It isn’t a site’s page,
        // so it fails without a request.)
        $result = $this->plugin()->warmer->warm($urls, false, null, [], microtime(true) - 1, $left);

        $this->assertSame(['built' => 0, 'failed' => 1], $result, 'Pages not started haven’t failed.');
        $this->assertSame(array_slice($urls, 1), $left);
    }

    public function testTheTimeComesFromTheQueue(): void
    {
        $queue = Craft::$app->getQueue();
        $ttr = $queue->ttr;
        $until = fn($queue) => $this->until($queue);
        try {
            $queue->ttr = 300;
            $this->assertEqualsWithDelta(microtime(true) + 240, $until->call(new WarmJob(), $queue), 2, 'A minute before the end.');
            $queue->ttr = 60;
            $this->assertEqualsWithDelta(microtime(true) + 30, $until->call(new WarmJob(), $queue), 2, 'Half way on a short one.');
        } finally {
            $queue->ttr = $ttr;
        }
    }

    public function testAJobQueuesThePagesItHadNoTimeFor(): void
    {
        $urls = ['https://example.com/speedy-test-a', 'https://example.com/speedy-test-b'];
        // A job whose time has run out: nothing is started.
        $job = new class(['urls' => $urls, 'rebuild' => true, 'keys' => ['https://example.com/speedy-test-b' => 'example.com/speedy-test-b']]) extends WarmJob {
            protected function until($queue): float
            {
                return microtime(true) - 1;
            }
        };
        $job->execute(Craft::$app->getQueue());

        $jobs = (new Query())->select(['job'])->from('{{%queue}}')->where(['>', 'id', $this->lastJob])->column();
        $this->assertCount(1, $jobs);
        $job = unserialize(is_resource($jobs[0]) ? stream_get_contents($jobs[0]) : $jobs[0]);
        $this->assertInstanceOf(WarmJob::class, $job);
        $this->assertSame(['https://example.com/speedy-test-b'], $job->urls, 'The pages it had no time for.');
        $this->assertTrue($job->rebuild, 'Still a rebuild, so pages that can’t be rebuilt are removed.');
        $this->assertSame(['https://example.com/speedy-test-b' => 'example.com/speedy-test-b'], $job->keys);
    }
}
