<?php

namespace suprim\speedy\tests\Integration;

use Craft;
use craft\elements\Entry;
use DateTime;
use suprim\speedy\tests\TestCase;

/**
 * Pages that list a section are cleared when a scheduled entry in it goes live, as Craft only records expiry dates.
 */
class ScheduledTest extends TestCase
{
    private ?Entry $entry = null;

    protected function tearDown(): void
    {
        if ($this->entry) {
            Craft::$app->getElements()->deleteElement($this->entry, true);
        }
        parent::tearDown();
    }

    public function testClearsListingsWhenAnEntryGoesLive(): void
    {
        $section = Craft::$app->getEntries()->getAllSections()[0] ?? null;
        if (!$section) {
            $this->markTestSkipped('No section to create an entry in.');
        }
        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $section->getEntryTypes()[0]->id,
            'title' => 'Speedy test: goes live',
            'postDate' => new DateTime('-5 seconds'),
        ]);
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry), json_encode($entry->getErrors()));
        $this->entry = $entry;

        $storage = $this->plugin()->storage;
        $storage->write('example.com/listing', 'x', 'https://example.com/listing', ["element::craft\\elements\\Entry::section:$section->id"], null);
        $storage->write('example.com/other', 'x', 'https://example.com/other', ['element::999999'], null);
        $storage->writeData('state', ['lastExpire' => time() - 60]);

        $this->plugin()->clearer->clearExpired();

        $this->assertFalse($storage->exists('example.com/listing'), 'The listing that will now show the entry is cleared.');
        $this->assertTrue($storage->exists('example.com/other'));
        $this->assertStringContainsString('went live', $this->plugin()->log->recent(1)[0]['reason']);
    }
}
