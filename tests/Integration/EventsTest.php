<?php

namespace suprim\speedy\tests\Integration;

use craft\events\RegisterComponentTypesEvent;
use suprim\speedy\events\ClearEvent;
use suprim\speedy\purgers\BasePurger;
use suprim\speedy\services\Cdn;
use suprim\speedy\services\Clearer;
use suprim\speedy\tests\TestCase;
use yii\base\Event;

class EventsTest extends TestCase
{
    public function testAfterClearReportsWhatWasCleared(): void
    {
        $seen = [];
        $handler = function(ClearEvent $e) use (&$seen) {
            $seen[] = [$e->reason, $e->count, $e->urls, $e->all];
        };
        $clearer = $this->plugin()->clearer;
        $clearer->on(Clearer::EVENT_AFTER_CLEAR, $handler);
        try {
            $this->plugin()->storage->write('example.com/a', 'a', 'https://example.com/a', ['t'], null);
            $clearer->clearTags(['t'], 'Saved entry “A”');
            $clearer->clearAll('Everything');
        } finally {
            $clearer->off(Clearer::EVENT_AFTER_CLEAR, $handler);
        }

        $this->assertSame(['Saved entry “A”', 1, ['https://example.com/a'], false], $seen[0]);
        $this->assertSame('Everything', $seen[1][0]);
        $this->assertTrue($seen[1][3]);
    }

    public function testOtherPluginsCanRegisterACdn(): void
    {
        $handler = function(RegisterComponentTypesEvent $e) {
            $e->types[] = TestPurger::class;
        };
        $cdn = new Cdn();
        $cdn->on(Cdn::EVENT_REGISTER_PURGERS, $handler);

        $this->assertArrayHasKey('test-cdn', $cdn->purgers());
        $this->assertSame('Test CDN', $cdn->names()['test-cdn']);
        $this->assertContains('test-cdn', $cdn->configured());
    }
}

class TestPurger extends BasePurger
{
    public static function handle(): string
    {
        return 'test-cdn';
    }

    public static function displayName(): string
    {
        return 'Test CDN';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function purgeUrls(array $urls): string
    {
        return 'ok';
    }

    public function purgeAll(): string
    {
        return 'ok';
    }

    public function test(): string
    {
        return 'ok';
    }
}
