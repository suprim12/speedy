<?php

namespace suprim\speedy\tests\Integration;

use craft\elements\Entry;
use suprim\speedy\tests\TestCase;

/**
 * Coverage: how many of the content pages are cached, by section.
 */
class CoverageTest extends TestCase
{
    private function anEntry(): Entry
    {
        foreach (Entry::find()->uri(':notempty:')->limit(200)->all() as $entry) {
            if ($entry->getUrl() && !$this->plugin()->insights->templateMissing(Entry::class, (int)$entry->sectionId, (int)$entry->siteId)) {
                return $entry;
            }
        }
        $this->markTestSkipped('No entry with a URL and a template on this site.');
    }

    /**
     * @return array{label: string, total: int, cached: int, missing: string[]}
     */
    private function groupOf(Entry $entry, array $coverage): array
    {
        foreach ($coverage['groups'] as $group) {
            if ($group['label'] === $entry->getSection()->name) {
                return $group;
            }
        }
        $this->fail('The entry’s section isn’t in the coverage.');
    }

    public function testCountsCachedAndMissingPagesBySection(): void
    {
        $entry = $this->anEntry();
        $insights = $this->plugin()->insights;
        $storage = $this->plugin()->storage;

        $before = $insights->coverage(true);
        $group = $this->groupOf($entry, $before);
        $this->assertContains($entry->getUrl(), $group['missing']);
        $this->assertSame($before['total'], array_sum(array_column($before['groups'], 'total')));

        $storage->write((string)$storage->keyForUrl($entry->getUrl()), '<html></html>', $entry->getUrl(), [], null);
        $after = $insights->coverage(true);
        $this->assertSame($before['cached'] + 1, $after['cached']);
        $this->assertNotContains($entry->getUrl(), $this->groupOf($entry, $after)['missing']);
        $this->assertSame($after, $insights->coverage(), 'The count is kept for a few minutes.');

        $storage->clearAll();
        $this->assertSame($before['cached'], $insights->coverage()['cached'], 'Clearing everything counts again.');
    }

    public function testPagesThatCouldntBeBuiltSayWhy(): void
    {
        $entry = $this->anEntry();
        $warmer = $this->plugin()->warmer;
        $note = fn(array $built, array $failed, array $why) => $this->noteUnbuilt($built, $failed, $why);

        $note->call($warmer, [], [$entry->getUrl()], [$entry->getUrl() => 'status 404']);
        $group = $this->groupOf($entry, $this->plugin()->insights->coverage(true));
        $this->assertSame('status 404', $group['failed'][$entry->getUrl()]);
        $this->assertNotContains($entry->getUrl(), $group['missing'], 'Listed apart from the pages not tried yet.');

        $note->call($warmer, [$entry->getUrl()], [], []);
        $this->assertArrayNotHasKey($entry->getUrl(), $warmer->unbuilt(), 'Forgotten once it’s built.');
    }

    public function testNeverCacheIsLeftOut(): void
    {
        $entry = $this->anEntry();
        $before = $this->plugin()->insights->coverage(true);

        $this->settings()->neverCache = [$entry->uri === '__home__' ? '' : $entry->uri];
        $after = $this->plugin()->insights->coverage(true);
        $this->assertGreaterThanOrEqual(1, $after['never']);
        $this->assertSame($before['total'] - $after['never'] + $before['never'], $after['total']);
        foreach ($after['groups'] as $group) {
            $this->assertNotContains($entry->getUrl(), $group['missing']);
        }
    }

    public function testSectionsWithoutATemplateAreLeftOut(): void
    {
        $entry = $this->anEntry();
        $insights = $this->plugin()->insights;
        $siteSettings = $entry->getSection()->getSiteSettings()[$entry->siteId];
        $template = $siteSettings->template;
        $mode = \Craft::$app->getView()->getTemplateMode();
        $forget = fn() => $this->_templateMissing = [];
        try {
            $siteSettings->template = 'speedy-test-no-such-template/_entry';
            $forget->call($insights);
            $coverage = $insights->coverage(true);
            $this->assertGreaterThanOrEqual(1, $coverage['noTemplate'][$entry->getSection()->name] ?? 0);
            foreach ($coverage['groups'] as $group) {
                $this->assertNotContains($entry->getUrl(), $group['missing']);
            }
            $this->assertSame($mode, \Craft::$app->getView()->getTemplateMode(), 'The view is put back as it was.');
        } finally {
            $siteSettings->template = $template;
            $forget->call($insights);
        }
        $this->assertArrayNotHasKey($entry->getSection()->name, $insights->coverage(true)['noTemplate'], 'Counted again once it has a template.');
    }

    public function testTemplatesInSiteFoldersCount(): void
    {
        $insights = $this->plugin()->insights;
        $exists = fn(string $template) => $this->siteTemplateExists($template);
        $this->assertFalse($exists->call($insights, 'speedy-test-no-such-template/_entry'));
        $entry = $this->anEntry();
        $this->assertTrue($exists->call($insights, (string)$entry->getSection()->getSiteSettings()[$entry->siteId]->template));

        // A template only in a site’s own folder, such as templates/default/news/_entry.twig.
        $folder = \Craft::getAlias('@templates') . '/' . \Craft::$app->getSites()->getPrimarySite()->handle;
        $made = !is_dir($folder);
        $file = $folder . '/speedy-test-site-only-' . bin2hex(random_bytes(3)) . '.twig';
        try {
            if ($made) {
                mkdir($folder);
            }
            file_put_contents($file, 'site only');
            $this->assertTrue($exists->call($insights, basename($file, '.twig')));
        } finally {
            @unlink($file);
            if ($made) {
                @rmdir($folder);
            }
        }
    }

    protected function tearDown(): void
    {
        \Craft::$app->getCache()->delete(\suprim\speedy\services\Insights::COVERAGE_CACHE);
        parent::tearDown();
    }
}
