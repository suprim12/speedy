<?php

namespace suprim\speedy\tests\Integration;

use Craft;
use craft\elements\Entry;
use suprim\speedy\tests\TestCase;

/**
 * The “Page cache” column element indexes can show, such as the entries table.
 */
class PageCacheColumnTest extends TestCase
{
    private function anEntry(): Entry
    {
        foreach (Entry::find()->uri(':notempty:')->status('live')->limit(50)->all() as $entry) {
            if ($entry->getUrl()) {
                return $entry;
            }
        }
        $this->markTestSkipped('No entry with a URL on this site.');
    }

    public function testEntryIndexesCanShowIt(): void
    {
        $available = Craft::$app->getElementSources()->getAvailableTableAttributes(Entry::class);
        $this->assertArrayHasKey('speedyCache', $available);
        $this->assertSame('Page cache', $available['speedyCache']['label']);
    }

    public function testItSaysWhetherTheEntrysPageIsCached(): void
    {
        $entry = $this->anEntry();
        $this->assertStringContainsString('Not cached', $entry->getAttributeHtml('speedyCache'));

        $storage = $this->plugin()->storage;
        $storage->write((string)$storage->keyForUrl((string)$entry->getUrl()), '<html></html>', (string)$entry->getUrl(), [], null);
        $html = $entry->getAttributeHtml('speedyCache');
        $this->assertStringContainsString('Cached', $html);
        $this->assertStringContainsString('status green', $html);

        $this->settings()->neverCache = [$entry->uri === '__home__' ? '' : (string)$entry->uri];
        $this->assertStringContainsString('Never cached', $entry->getAttributeHtml('speedyCache'));
    }

    public function testOtherColumnsAreLeftAlone(): void
    {
        $entry = $this->anEntry();
        $this->assertSame(Craft::$app->getFormatter()->asText($entry->slug), strip_tags($entry->getAttributeHtml('slug')));
    }
}
