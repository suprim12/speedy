<?php

namespace suprim\speedy\tests\Integration;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use suprim\speedy\tests\TestCase;

/**
 * Build new pages: an entry saved or gone live has its page built when it isn’t cached, but not during imports.
 */
class BuildSavedTest extends TestCase
{
    /** @var Entry[] */
    private array $entries = [];

    private string $slug;

    /** The last queue job before the test, so it removes only the jobs it queued, such as clearing after its saves. */
    private int $lastJob = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings()->clearInRequest = false;
        $this->slug = 'speedy-test-build-' . bin2hex(random_bytes(3));
        $this->lastJob = (int)(new \craft\db\Query())->from('{{%queue}}')->max('id');
    }

    protected function tearDown(): void
    {
        Craft::$app->getDb()->createCommand()->delete('{{%queue}}', ['>', 'id', $this->lastJob])->execute();
        foreach ($this->entries as $entry) {
            Craft::$app->getElements()->deleteElement($entry, true);
        }
        // Only the jobs these tests queued.
        Craft::$app->getDb()->createCommand()->delete('{{%queue}}', ['like', 'job', $this->slug])->execute();
        parent::tearDown();
    }

    private ?\craft\models\Section $section = null;

    private function section(): \craft\models\Section
    {
        if ($this->section) {
            return $this->section;
        }
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            foreach ($section->getSiteSettings() as $siteSettings) {
                if ($siteSettings->hasUrls && !$this->plugin()->insights->templateMissing(Entry::class, (int)$section->id, (int)$siteSettings->siteId)) {
                    return $this->section = $section;
                }
            }
        }
        $this->markTestSkipped('No section with URLs and a template.');
    }

    private function save(array $attributes = [], string $suffix = ''): Entry
    {
        $section = $this->section();
        $entry = new Entry(array_merge([
            'sectionId' => $section->id,
            'typeId' => $section->getEntryTypes()[0]->id,
            'title' => 'Speedy test: build',
            'slug' => $this->slug . $suffix,
        ], $attributes));
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry), json_encode($entry->getErrors()));
        $this->entries[] = $entry;

        return $entry;
    }

    /**
     * How many warm jobs were queued for these tests’ pages.
     */
    private function queued(): int
    {
        $this->plugin()->clearer->flush();
        $this->plugin()->clearer->buildNoted();

        return (int)(new Query())->from('{{%queue}}')->where(['like', 'description', 'Speedy Cache: warming'])->andWhere(['like', 'job', $this->slug])->count();
    }

    public function testASavedLivePageIsBuilt(): void
    {
        $this->save();
        $this->assertSame(1, $this->queued());
    }

    public function testNotWhenDisabledOffOrLeftOut(): void
    {
        $this->save(['enabled' => false], '-disabled');
        $this->assertSame(0, $this->queued(), 'Disabled entries have no page.');

        $this->save(['postDate' => new \DateTime('+1 day')], '-later');
        $this->assertSame(0, $this->queued(), 'Not live yet.');

        $this->settings()->neverCache = ['*' . $this->slug . '*'];
        $this->save([], '-never');
        $this->assertSame(0, $this->queued(), 'Left out by Never cache.');

        $this->settings()->neverCache = [];
        $this->settings()->buildSavedPages = false;
        $this->save([], '-off');
        $this->assertSame(0, $this->queued(), 'Turned off.');
    }

    public function testNotWhenTheSectionHasNoTemplate(): void
    {
        $section = $this->section();
        $insights = $this->plugin()->insights;
        $forget = fn() => $this->_templateMissing = [];
        $templates = [];
        foreach ($section->getSiteSettings() as $siteId => $siteSettings) {
            $templates[$siteId] = $siteSettings->template;
            $siteSettings->template = 'speedy-test-no-such-template/_entry';
        }
        $forget->call($insights);
        try {
            $this->save();
            $this->assertSame(0, $this->queued(), 'Its page would only be a 404.');
        } finally {
            foreach ($section->getSiteSettings() as $siteId => $siteSettings) {
                $siteSettings->template = $templates[$siteId];
            }
            $forget->call($insights);
        }
    }

    public function testNotWhenAlreadyCached(): void
    {
        $entry = $this->save([], '-cached');
        $this->queued();
        Craft::$app->getDb()->createCommand()->delete('{{%queue}}', ['like', 'job', $this->slug])->execute();

        $storage = $this->plugin()->storage;
        $storage->write((string)$storage->keyForUrl((string)$entry->getUrl()), '<html></html>', (string)$entry->getUrl(), [], null);
        $entry->title = 'Speedy test: build, changed';
        Craft::$app->getElements()->saveElement($entry);
        $this->assertSame(0, $this->queued(), 'A cached page is rebuilt when it’s cleared instead.');
    }

    public function testNotWhileResavingOrImporting(): void
    {
        $this->save(['resaving' => true], '-resave');
        $this->assertSame(0, $this->queued(), 'Resaving, such as a section’s entries.');

        for ($i = 0; $i <= \suprim\speedy\services\Clearer::BUILD_SAVED_MOST; $i++) {
            $this->save([], "-import-$i");
        }
        $this->assertSame(0, $this->queued(), 'More saves than that in one go is an import.');
    }

    public function testAPageIsBuiltWhenItGoesLive(): void
    {
        $this->save(['postDate' => new \DateTime('-5 seconds')], '-live');
        Craft::$app->getDb()->createCommand()->delete('{{%queue}}', ['like', 'job', $this->slug])->execute();
        $this->plugin()->storage->writeData('state', ['lastExpire' => time() - 60]);

        $this->plugin()->clearer->clearExpired();
        $this->assertSame(1, $this->queued());
    }
}
