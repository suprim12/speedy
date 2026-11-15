<?php

namespace suprim\speedy\tests\Integration;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use suprim\speedy\tests\TestCase;

/**
 * Saving without changes, or saving a disabled entry that stays disabled, doesn’t clear pages.
 */
class SavesTest extends TestCase
{
    private ?Entry $entry = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Count queued clears, whatever state the queue is in.
        $this->settings()->clearInRequest = false;
        $section = Craft::$app->getEntries()->getAllSections()[0] ?? null;
        if (!$section) {
            $this->markTestSkipped('No section to create an entry in.');
        }
        $this->entry = new Entry(['sectionId' => $section->id, 'typeId' => $section->getEntryTypes()[0]->id, 'title' => 'Speedy test: saves']);
        $this->assertTrue(Craft::$app->getElements()->saveElement($this->entry));
    }

    protected function tearDown(): void
    {
        if ($this->entry) {
            Craft::$app->getElements()->deleteElement($this->entry, true);
        }
        parent::tearDown();
    }

    private function clears(callable $change): bool
    {
        $jobs = fn() => (int)(new Query())->from('{{%queue}}')->where(['like', 'description', 'Speedy Cache: clearing'])->count();
        $before = $jobs();
        $entry = Entry::find()->id($this->entry->id)->status(null)->one();
        $change($entry);
        Craft::$app->getElements()->saveElement($entry);
        $this->plugin()->clearer->flush();

        return $jobs() > $before;
    }

    public function testOnlyRealChangesClearPages(): void
    {
        $this->assertFalse($this->clears(fn() => null), 'Saved without changes.');
        $this->assertTrue($this->clears(fn(Entry $e) => $e->title = 'Speedy test: saves, renamed'));
        $this->assertTrue($this->clears(fn(Entry $e) => $e->enabled = false), 'Disabling takes it off pages.');
        $this->assertFalse($this->clears(fn(Entry $e) => $e->title = 'Speedy test: saves, while disabled'), 'A disabled entry isn’t on any page.');
        $this->assertTrue($this->clears(fn(Entry $e) => $e->enabled = true));

        $this->settings()->ignoreUnchangedSaves = false;
        $this->assertTrue($this->clears(fn() => null), 'Every save clears with the setting off.');
    }

    public function testCustomTagsClearOnlyTheirPages(): void
    {
        $storage = $this->plugin()->storage;
        $storage->write('example.com/weather', 'w', 'https://example.com/weather', ['speedy:weather'], null);
        $storage->write('example.com/news', 'n', 'https://example.com/news', ['element::1'], null);

        $this->assertSame(1, $this->plugin()->clearer->clearCustomTags(['Weather'], 'test'));
        $this->assertFalse($storage->exists('example.com/weather'));
        $this->assertTrue($storage->exists('example.com/news'));
    }
}
