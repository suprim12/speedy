<?php

namespace suprim\speedy\tests\Integration;

use Craft;
use craft\db\Query;
use craft\events\InvalidateElementCachesEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\Queue;
use suprim\speedy\events\BeforeClearEvent;
use suprim\speedy\events\InvalidateEvent;
use suprim\speedy\events\PurgeEvent;
use suprim\speedy\events\WarmEvent;
use suprim\speedy\jobs\ClearJob;
use suprim\speedy\services\BlitzImport;
use suprim\speedy\services\Cdn;
use suprim\speedy\services\Clearer;
use suprim\speedy\services\Warmer;
use suprim\speedy\tests\TestCase;
use yii\base\Event;

/**
 * Developer events, the queue check and the other page cache’s settings.
 */
class HooksTest extends TestCase
{
    /** @var int[] Queue rows a test added. */
    private array $jobIds = [];

    protected function tearDown(): void
    {
        foreach ($this->jobIds as $id) {
            Craft::$app->getDb()->createCommand()->delete('{{%queue}}', ['id' => $id])->execute();
        }
        Craft::$app->getCache()->delete('speedy:queueStalled');
        Craft::$app->getCache()->delete('speedy:cpVisit');
        parent::tearDown();
    }

    private function jobsPushed(callable $fn): int
    {
        $count = fn() => (int)(new Query())->from('{{%queue}}')->where(['like', 'description', 'Speedy Cache: clearing'])->count();
        $before = $count();
        $fn();

        return $count() - $before;
    }

    public function testBeforeInvalidateCanChangeOrStopAClear(): void
    {
        $this->settings()->clearInRequest = true;
        $storage = $this->plugin()->storage;
        $storage->write('example.com/a', 'a', 'https://example.com/a', ['element::1'], null);
        $storage->write('example.com/b', 'b', 'https://example.com/b', ['element::2'], null);
        $clearer = $this->plugin()->clearer;

        $handler = function(InvalidateEvent $e) {
            $e->tags = ['element::2'];
        };
        $clearer->on(Clearer::EVENT_BEFORE_INVALIDATE, $handler);
        try {
            $clearer->onInvalidate(new InvalidateElementCachesEvent(['tags' => ['element::1']]));
            $clearer->flush();
        } finally {
            $clearer->off(Clearer::EVENT_BEFORE_INVALIDATE, $handler);
        }

        $this->assertTrue($storage->exists('example.com/a'), 'Its tags were replaced.');
        $this->assertFalse($storage->exists('example.com/b'));
    }

    public function testBeforeClearCanKeepPages(): void
    {
        $storage = $this->plugin()->storage;
        $storage->write('example.com/a', 'a', 'https://example.com/a', ['t'], null);
        $clearer = $this->plugin()->clearer;
        $handler = fn(BeforeClearEvent $e) => $e->isValid = false;
        $clearer->on(Clearer::EVENT_BEFORE_CLEAR, $handler);
        try {
            $this->assertSame(0, $clearer->clearTags(['t'], 'test'));
        } finally {
            $clearer->off(Clearer::EVENT_BEFORE_CLEAR, $handler);
        }
        $this->assertTrue($storage->exists('example.com/a'));
    }

    public function testIgnoredElementTypesNeverClear(): void
    {
        $this->settings()->clearInRequest = true;
        $this->settings()->ignoredElementTypes = [\craft\elements\User::class];
        $storage = $this->plugin()->storage;
        $storage->write('example.com/team', 't', 'https://example.com/team', ['element::craft\elements\User'], null);

        $this->plugin()->clearer->onInvalidate(new InvalidateElementCachesEvent(['tags' => ['element::craft\elements\User']]));
        $this->plugin()->clearer->flush();

        $this->assertTrue($storage->exists('example.com/team'));
    }

    public function testBeforeWarmCanAddPages(): void
    {
        $handler = function(WarmEvent $e) {
            $e->urls[] = 'https://example.com/extra';
        };
        $warmer = $this->plugin()->warmer;
        $warmer->on(Warmer::EVENT_BEFORE_WARM, $handler);
        $pushed = [];
        $queue = function(\yii\queue\PushEvent $e) use (&$pushed) {
            $pushed = array_merge($pushed, $e->job->urls ?? []);
            $e->handled = true;
        };
        Craft::$app->getQueue()->on(\yii\queue\Queue::EVENT_BEFORE_PUSH, $queue);
        try {
            $warmer->queue(['https://example.com/a'], false);
        } finally {
            $warmer->off(Warmer::EVENT_BEFORE_WARM, $handler);
            Craft::$app->getQueue()->off(\yii\queue\Queue::EVENT_BEFORE_PUSH, $queue);
        }
        $this->assertSame(['https://example.com/a', 'https://example.com/extra'], $pushed);
    }

    public function testBeforePurgeCanAddUrlsAndAfterPurgeHasTheAnswers(): void
    {
        $register = fn(RegisterComponentTypesEvent $e) => $e->types[] = RecordingPurger::class;
        Event::on(Cdn::class, Cdn::EVENT_REGISTER_PURGERS, $register);
        $cdn = new Cdn();
        $results = [];
        $cdn->on(Cdn::EVENT_BEFORE_PURGE, fn(PurgeEvent $e) => $e->urls[] = 'https://other.example.com/a');
        $cdn->on(Cdn::EVENT_AFTER_PURGE, function(PurgeEvent $e) use (&$results) {
            $results = $e->results;
        });
        RecordingPurger::$purged = [];
        try {
            $cdn->purgeUrls(['https://example.com/a']);
        } finally {
            Event::off(Cdn::class, Cdn::EVENT_REGISTER_PURGERS, $register);
        }

        $this->assertSame(['https://example.com/a', 'https://other.example.com/a'], RecordingPurger::$purged);
        $this->assertSame('ok', $results['recording'] ?? null);
    }

    public function testClearsWithoutTheQueueWhileItIsStuck(): void
    {
        $queue = Craft::$app->getQueue();
        if (!$queue instanceof \craft\queue\Queue) {
            $this->markTestSkipped('Needs the database queue.');
        }
        $this->settings()->clearInRequest = false;
        $storage = $this->plugin()->storage;
        $storage->write('example.com/a', 'a', 'https://example.com/a', ['element::1'], null);
        $clearer = $this->plugin()->clearer;

        $this->assertSame(1, $this->jobsPushed(function() use ($clearer) {
            $clearer->onInvalidate(new InvalidateElementCachesEvent(['tags' => ['element::1']]));
            $clearer->flush();
        }), 'With the queue, clearing waits for it.');
        $this->jobIds[] = (int)(new Query())->from('{{%queue}}')->where(['like', 'description', 'Speedy Cache: clearing'])->max('id');
        Craft::$app->getDb()->createCommand()->update('{{%queue}}', ['timePushed' => time() - 3600], ['id' => end($this->jobIds)])->execute();
        Craft::$app->getCache()->delete('speedy:queueStalled');
        $this->assertGreaterThan(3000, $this->plugin()->insights->queueStalledFor());

        $this->settings()->clearInRequest = 'auto';

        $this->assertSame(0, $this->jobsPushed(function() use ($clearer) {
            $clearer->onInvalidate(new InvalidateElementCachesEvent(['tags' => ['element::1']]));
            $clearer->flush();
        }), 'A stuck queue doesn’t get more.');
        $this->assertFalse($storage->exists('example.com/a'), 'The page was cleared straight away.');
        $this->assertStringContainsString('isn’t running', implode(' ', array_column($this->plugin()->insights->checks(), 'message')));
    }

    public function testAQuietControlPanelIsntAStuckQueue(): void
    {
        $general = Craft::$app->getConfig()->getGeneral();
        $auto = $general->runQueueAutomatically;
        $consoleRequest = Craft::$app->getRequest();
        $cpRequest = new \craft\web\Request();
        $cpRequest->setIsCpRequest(true);
        Craft::$app->set('request', $cpRequest);
        try {
            // A Speedy job that has waited an hour.
            Queue::push(new ClearJob(['tags' => ['element::1']]));
            $this->jobIds[] = (int)(new Query())->from('{{%queue}}')->where(['like', 'description', 'Speedy Cache: clearing'])->max('id');
            Craft::$app->getDb()->createCommand()->update('{{%queue}}', ['timePushed' => time() - 3600], ['id' => end($this->jobIds)])->execute();
            Craft::$app->getCache()->delete('speedy:queueStalled');
            $insights = $this->plugin()->insights;

            $general->runQueueAutomatically = true;
            $this->assertGreaterThan(3000, $insights->queueStalledFor(), 'Pages still clear without the queue.');
            $this->assertSame(0, $insights->queueStuckFor(), 'The control panel has only just been opened, so it hasn’t had the chance to run the queue.');

            Craft::$app->getCache()->set('speedy:cpVisit', ['since' => time() - 900, 'last' => time() - 60], 86400);
            $this->assertGreaterThan(800, $insights->queueStuckFor(), 'Still waiting after fifteen minutes in the control panel.');

            Craft::$app->getCache()->set('speedy:cpVisit', ['since' => time() - 7200, 'last' => time() - 3000], 86400);
            $this->assertSame(0, $insights->queueStuckFor(), 'A visit after a quiet spell starts again.');

            $general->runQueueAutomatically = false;
            $this->assertGreaterThan(3000, $insights->queueStuckFor(), 'With a queue runner, the wait counts from the job.');
        } finally {
            $general->runQueueAutomatically = $auto;
            Craft::$app->set('request', $consoleRequest);
        }
    }

    public function testADeployRebuildsCachedPages(): void
    {
        $this->plugin()->storage->write('example.com/a', 'a', 'https://example.com/a', ['element::1'], null);
        $rebuilds = fn() => array_map('intval', (new Query())->from('{{%queue}}')->where(['like', 'description', 'Speedy Cache: rebuilding'])->select('id')->column());
        $before = $rebuilds();
        $deploy = function() {
            Craft::$app->getProjectConfig()->trigger(\craft\services\ProjectConfig::EVENT_AFTER_APPLY_CHANGES);
            $this->plugin()->clearer->flush();
        };

        $deploy();
        $new = array_diff($rebuilds(), $before);
        $this->jobIds = array_merge($this->jobIds, $new);
        $this->assertCount(1, $new, 'One rebuild job for the cached pages.');
        $this->assertSame('Project config changes applied', $this->plugin()->log->recent(1)[0]['reason']);

        $this->settings()->rebuildAfterDeploy = false;
        $deploy();
        $this->assertCount(0, array_diff($rebuilds(), $before, $new), 'Turned off, a deploy doesn’t rebuild.');
    }

    public function testCountsQueryVersionsOfAPage(): void
    {
        $storage = $this->plugin()->storage;
        foreach ([2, 3, 4] as $page) {
            $storage->write((string)$storage->key('example.com', '/news', ['page' => (string)$page]), 'p', "https://example.com/news?page=$page", [], null);
        }
        $this->assertSame(3, $storage->countQueryPages('example.com/news'));
        $storage->delete((string)$storage->key('example.com', '/news', ['page' => '2']));
        $this->assertSame(2, $storage->countQueryPages('example.com/news'));
    }

    public function testMapsTheOtherPageCachesSettings(): void
    {
        $mapped = (new BlitzImport())->map([
            'excludedUriPatterns' => [['siteId' => '', 'uriPattern' => 'checkout/.*'], ['siteId' => '', 'uriPattern' => '^(a|b)$']],
            'includedUriPatterns' => [['siteId' => '', 'uriPattern' => '^$'], ['siteId' => '', 'uriPattern' => 'news/.*']],
            'queryStringCaching' => 1,
            'includedQueryStringParams' => [['siteId' => '', 'queryStringParam' => 'page']],
            'refreshMode' => 1,
            'cachePurgerType' => 'putyourlightson\blitz\drivers\purgers\CloudflarePurger',
            'cachePurgerSettings' => ['apiToken' => '$CF', 'zoneIds' => ['x' => ['zoneId' => 'z1']]],
        ]);

        $this->assertSame(['checkout/*'], $mapped['settings']['neverCache']);
        $this->assertSame(['/', 'news/*'], $mapped['settings']['onlyCache'], 'Its included pages, with the homepage as /.');
        $this->assertSame(['page'], $mapped['settings']['cachedParams']);
        $this->assertFalse($mapped['settings']['rebuildCleared']);
        $this->assertSame('$CF', $mapped['settings']['cloudflareToken']);
        $this->assertSame('z1', $mapped['settings']['cloudflareZoneId']);
        $this->assertStringContainsString('^(a|b)$', implode(' ', $mapped['notes']), 'A pattern it can’t convert is listed to do by hand.');
    }
}
