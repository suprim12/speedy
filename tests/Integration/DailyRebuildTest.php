<?php

namespace suprim\speedy\tests\Integration;

use Craft;
use suprim\speedy\tests\TestCase;

/**
 * Rebuild every day: the listed pages are rebuilt once a day at a set time, in the site’s time zone.
 */
class DailyRebuildTest extends TestCase
{
    private string $timeZone;

    /** The last queue job before the test, so it removes only the jobs it queued, such as clearing after its saves. */
    private int $lastJob = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->timeZone = Craft::$app->getTimeZone();
        Craft::$app->setTimeZone('Australia/Adelaide');
        $this->settings()->dailyRebuild = ['events*'];
        $this->settings()->dailyRebuildTime = '00:05';
        $this->lastJob = (int)(new \craft\db\Query())->from('{{%queue}}')->max('id');
    }

    protected function tearDown(): void
    {
        Craft::$app->getDb()->createCommand()->delete('{{%queue}}', ['>', 'id', $this->lastJob])->execute();
        Craft::$app->setTimeZone($this->timeZone);
        Craft::$app->getDb()->createCommand()->delete('{{%queue}}', ['like', 'job', 'speedy-daily.test'])->execute();
        parent::tearDown();
    }

    private function at(string $time): int
    {
        return (new \DateTimeImmutable($time, new \DateTimeZone('Australia/Adelaide')))->getTimestamp();
    }

    public function testDueAtTheTimeInTheSitesTimeZone(): void
    {
        $clearer = $this->plugin()->clearer;
        $this->assertSame($this->at('2026-11-18 00:05'), $clearer->dailyRebuildDue($this->at('2026-11-18 09:00')));
        $this->assertSame($this->at('2026-11-17 00:05'), $clearer->dailyRebuildDue($this->at('2026-11-18 00:04')), 'Before the time: yesterday’s.');
        $this->assertSame($this->at('2026-11-19 00:05'), $clearer->nextDailyRebuild($this->at('2026-11-18 09:00')));
        // Daylight saving starts on 2026-10-04 in Adelaide: still 00:05 local time.
        $this->assertSame($this->at('2026-10-04 00:05'), $clearer->nextDailyRebuild($this->at('2026-10-03 12:00')));

        $this->settings()->dailyRebuildTime = '25:00';
        $this->assertNull($clearer->dailyRebuildDue(time()));
        $this->assertFalse($this->settings()->validate(['dailyRebuildTime']));
        $this->settings()->dailyRebuild = [];
        $this->assertNull($clearer->nextDailyRebuild(time()));
    }

    public function testRebuildsTheListedPagesOnceADay(): void
    {
        $storage = $this->plugin()->storage;
        $storage->write('speedy-daily.test/events', '<html></html>', 'https://speedy-daily.test/events', [], null);
        $storage->write('speedy-daily.test/events/today', '<html></html>', 'https://speedy-daily.test/events/today', [], null);
        $storage->write('speedy-daily.test/about', '<html></html>', 'https://speedy-daily.test/about', [], null);
        $clearer = $this->plugin()->clearer;
        $now = $this->at('2026-11-18 09:00');

        $this->assertSame(0, $clearer->rebuildDaily($now), 'Just set up: from the next time on, not straight away.');
        $storage->writeData('state', ['dailyRebuildAt' => $this->at('2026-11-17 00:05')]);
        $this->assertSame(2, $clearer->rebuildDaily($now), 'Missed this morning, as the queue was stopped: done now.');
        $this->assertSame('Daily rebuild', $this->plugin()->log->recent(1)[0]['reason']);
        $this->assertSame(0, $clearer->rebuildDaily($now + 3600), 'Once a day.');
        $this->assertSame(2, $clearer->rebuildDaily($this->at('2026-11-19 00:06')));
        $this->assertTrue($storage->exists('speedy-daily.test/events'), 'The old copies stay until the new ones are ready.');

        $this->settings()->enabled = false;
        $this->assertSame(0, $clearer->rebuildDaily($this->at('2026-11-21 00:06')), 'Not with caching off.');
    }

    public function testScheduledCheckIsQueuedForTheNextRebuild(): void
    {
        $storage = $this->plugin()->storage;
        $this->plugin()->clearer->scheduleDailyRebuild();
        $state = $storage->readData('state');
        $this->assertGreaterThan(time(), $state['expireJobAt']);
        $this->assertLessThanOrEqual(time() + 86400 + 3600, $state['expireJobAt']);
        $this->assertArrayHasKey('dailyRebuildAt', $state);
    }
}
