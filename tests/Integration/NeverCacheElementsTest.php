<?php

namespace suprim\speedy\tests\Integration;

use craft\db\Query;
use craft\db\Table;
use craft\elements\Category;
use craft\elements\Entry;
use suprim\speedy\services\Cacher;
use suprim\speedy\tests\TestCase;

/**
 * Never cache by entry type and by category: which pages, how they’re counted and shown, and removing copies cached
 * before.
 */
class NeverCacheElementsTest extends TestCase
{
    /** @var array{0: string[], 1: string[]} */
    private array $before = [[], []];

    protected function setUp(): void
    {
        parent::setUp();
        $settings = $this->settings();
        $this->before = [$settings->neverCacheEntryTypes, $settings->neverCacheCategories];
    }

    protected function tearDown(): void
    {
        $this->settings()->setAttributes(['neverCacheEntryTypes' => $this->before[0], 'neverCacheCategories' => $this->before[1]], false);
        \Craft::$app->getCache()->delete(\suprim\speedy\services\Insights::COVERAGE_CACHE);
        parent::tearDown();
    }

    private function anEntry(): Entry
    {
        foreach (Entry::find()->uri(':notempty:')->limit(100)->all() as $entry) {
            if ($entry->getUrl() && !$this->plugin()->insights->templateMissing(Entry::class, (int)$entry->sectionId, (int)$entry->siteId)) {
                return $entry;
            }
        }
        $this->markTestSkipped('No entry with a URL and a template on this site.');
    }

    /**
     * A category with an element related to it.
     *
     * @return array{0: Category, 1: int}
     */
    private function aRelatedCategory(): array
    {
        $ids = Category::find()->site('*')->status(null)->unique()->ids();
        $relation = $ids ? (new Query())->select(['sourceId', 'targetId'])->from(Table::RELATIONS)->where(['targetId' => $ids])->one() : null;
        if (!$relation) {
            $this->markTestSkipped('Needs an element related to a category.');
        }

        return [Category::find()->id($relation['targetId'])->site('*')->status(null)->one(), (int)$relation['sourceId']];
    }

    public function testEntriesOfAnEntryTypeAreNeverCached(): void
    {
        $entry = $this->anEntry();
        $this->assertNull(Cacher::neverCacheReason($entry));

        $this->settings()->neverCacheEntryTypes = [$entry->getType()->handle];
        $this->assertSame('never cache entry type', Cacher::neverCacheReason($entry));
        $status = $this->plugin()->insights->forElement($entry, false);
        $this->assertSame('never', $status['state']);
        $this->assertSame('Its entry type is in Never cache', $status['detail']);
    }

    public function testCategoriesAndWhatHasThemAreNeverCached(): void
    {
        [$category, $sourceId] = $this->aRelatedCategory();
        $source = \Craft::$app->getElements()->getElementById($sourceId, null, '*');
        $this->settings()->setAttributes(['neverCacheCategories' => [(string)$category->id]], false);
        $this->assertSame([$category->getGroup()->handle . '/' . $category->slug], $this->settings()->neverCacheCategories, 'Kept by group and slug, the same on every environment.');

        $this->assertSame('never cache category', Cacher::neverCacheReason($category), 'The category’s own page.');
        if ($source) {
            $this->assertSame('never cache category', Cacher::neverCacheReason($source), 'An element that has it.');
        }
        $this->settings()->setAttributes(['neverCacheCategories' => ['nosuchgroup/nosuchslug', ''], 'neverCacheEntryTypes' => ''], false);
        $this->assertSame([], $this->settings()->getNeverCacheCategoryIds(), 'One that doesn’t exist matches nothing.');
        $this->assertSame([], $this->settings()->neverCacheEntryTypes, 'No boxes ticked.');
        $this->assertNull(Cacher::neverCacheReason($category));
    }

    public function testCoverageLeavesThemOut(): void
    {
        $entry = $this->anEntry();
        $before = $this->plugin()->insights->coverage(true);
        $this->settings()->neverCacheEntryTypes = [$entry->getType()->handle];
        $after = $this->plugin()->insights->coverage(true);
        $this->assertGreaterThan($before['never'], $after['never']);
        foreach ($after['groups'] as $group) {
            $this->assertNotContains($entry->getUrl(), $group['missing']);
        }
    }

    public function testCopiesCachedBeforeAreRemoved(): void
    {
        // An entry type with none of its pages cached, so the only copy removed is the one this test makes.
        $storage = $this->plugin()->storage;
        $entry = null;
        foreach (Entry::find()->uri(':notempty:')->limit(100)->all() as $candidate) {
            $urls = array_filter(array_map(fn($e) => $e->getUrl(), Entry::find()->type($candidate->getType()->handle)->site('*')->status(null)->all()));
            if ($candidate->getUrl() && !array_filter($urls, fn($url) => $storage->exists((string)$storage->keyForUrl((string)$url)))) {
                $entry = $candidate;
                break;
            }
        }
        if (!$entry) {
            $this->markTestSkipped('Needs an entry type with none of its pages cached.');
        }
        $key = (string)$storage->keyForUrl((string)$entry->getUrl());
        $other = 'example.com/speedy-test-other';
        $storage->write($key, '<html>Old</html>', (string)$entry->getUrl(), [], null);
        $storage->write($other, '<html>Old</html>', 'https://' . $other, [], null);
        $lastJob = (int)(new Query())->from('{{%queue}}')->max('id');
        try {
            $this->settings()->neverCacheEntryTypes = [$entry->getType()->handle];
            $this->assertGreaterThanOrEqual(1, $this->plugin()->clearer->clearNeverCached($this->settings()));
            $this->assertFalse($storage->exists($key));
            $this->assertTrue($storage->exists($other), 'Other pages are kept.');
            $jobs = (new Query())->select(['job'])->from('{{%queue}}')->where(['>', 'id', $lastJob])->column();
            foreach ($jobs as $job) {
                $job = unserialize(is_resource($job) ? stream_get_contents($job) : $job);
                $this->assertNotContains((string)$entry->getUrl(), $job->urls ?? [], 'Removed, not rebuilt.');
            }
        } finally {
            $storage->delete($other);
            $storage->delete($key);
            \Craft::$app->getDb()->createCommand()->delete('{{%queue}}', ['>', 'id', $lastJob])->execute();
        }
    }

    public function testPagesMatchingANewNeverCachePatternAreRemoved(): void
    {
        $storage = $this->plugin()->storage;
        $pages = [
            'example.com/speedy-test-nc/a' => 'https://example.com/speedy-test-nc/a',
            'example.com/speedy-test-nc/a/~query/page%3D2' => 'https://example.com/speedy-test-nc/a?page=2',
            'example.com/speedy-test-nc-kept' => 'https://example.com/speedy-test-nc-kept',
        ];
        foreach ($pages as $key => $url) {
            $storage->write($key, '<html>Old</html>', $url, [], null);
        }
        // Only the patterns: no entry type or category, so no other page is touched.
        $settings = new \suprim\speedy\models\Settings();
        $settings->neverCache = ['speedy-test-nc/*'];
        try {
            $this->assertSame(2, $this->plugin()->clearer->clearNeverCached($settings));
            $this->assertFalse($storage->exists('example.com/speedy-test-nc/a'));
            $this->assertFalse($storage->exists('example.com/speedy-test-nc/a/~query/page%3D2'), 'Its query string versions too.');
            $this->assertTrue($storage->exists('example.com/speedy-test-nc-kept'), 'A page the pattern doesn’t match is kept.');
        } finally {
            foreach (array_keys($pages) as $key) {
                $storage->delete($key);
            }
        }
    }

    public function testOnlyCacheDecidesWithTheSettingsGiven(): void
    {
        $settings = new \suprim\speedy\models\Settings();
        $settings->onlyCache = ['news*'];
        $insights = $this->plugin()->insights;
        $this->assertTrue($insights->leftOut('https://example.com/about', 'about', $settings), 'Outside Only cache.');
        $this->assertFalse($insights->leftOut('https://example.com/news/a', 'news/a', $settings));
        $this->assertFalse($insights->leftOut('https://example.com/about', 'about'), 'The site’s own settings when none are given.');
    }
}
