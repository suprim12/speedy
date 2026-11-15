<?php
/**
 * Speedy Cache plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\speedy\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\helpers\App;
use craft\helpers\ElementHelper;
use craft\helpers\Html;
use suprim\speedy\Plugin;

/**
 * What’s cached and why: the cached pages list, an element’s cache status, and setup checks.
 */
class Insights extends Component
{
    /**
     * Cached pages, sorted by URL, optionally filtered.
     *
     * @return array{total: int, pages: array<int, array{key: string, url: string, created: ?int, expires: ?int, tags: string[], deps: string[]}>}
     */
    public function pages(string $search = '', int $offset = 0, int $limit = 50): array
    {
        $storage = Plugin::getInstance()->storage;
        $search = strtolower(trim($search));
        $keys = [];
        foreach ($storage->allKeys() as $key) {
            if ($search === '' || str_contains(strtolower($key), $search)) {
                $keys[] = $key;
            }
        }
        sort($keys);

        $pages = [];
        foreach (array_slice($keys, $offset, $limit) as $key) {
            $meta = $storage->meta($key) ?? [];
            $pages[] = [
                'key' => $key,
                'url' => $meta['url'] ?? "https://$key",
                'created' => $meta['created'] ?? null,
                'expires' => $meta['expires'] ?? null,
                'tags' => $meta['tags'] ?? [],
                'deps' => $this->describeTags($meta['tags'] ?? []),
            ];
        }

        return ['total' => count($keys), 'pages' => $pages];
    }

    /**
     * What a page depends on, in words: “Entries in News”, “Summer Sale”, “Any entry”.
     *
     * @param string[] $tags
     * @return string[] HTML
     */
    public function describeTags(array $tags): array
    {
        $ids = [];
        $out = [];
        foreach ($tags as $tag) {
            if (preg_match('/^element::(\d+)$/', $tag, $m)) {
                $ids[] = (int)$m[1];
            } elseif (preg_match('/^element::([^:]+(?:\\\\[^:]+)*)::(.+)$/', $tag, $m)) {
                if (in_array($m[2], ['drafts', 'revisions'], true)) {
                    continue;
                }
                $out[] = Html::encode($this->describeQueryTag($m[1], $m[2]));
            }
        }
        if ($ids) {
            $elements = [];
            foreach (array_slice(array_unique($ids), 0, 100) as $id) {
                $element = Craft::$app->getElements()->getElementById($id);
                if ($element) {
                    $elements[] = $element;
                }
            }
            foreach ($elements as $element) {
                $label = Html::encode($element::displayName() . ': ' . ($element->title ?? (string)$element));
                $url = $element->getCpEditUrl();
                $out[] = $url ? Html::a($label, $url) : $label;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * The cache status of an element’s page, for its edit page.
     *
     * @return array{state: string, label: string, detail: ?string, pages: int}|null
     */
    public function forElement(ElementInterface $element): ?array
    {
        if (!$element->id || ElementHelper::isDraftOrRevision($element)) {
            return null;
        }
        $url = $element->getUrl();
        if (!$url) {
            return null;
        }
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $pages = $plugin->storage->countPagesForTag("element::$element->id");
        $parts = parse_url($url);
        $path = trim($parts['path'] ?? '', '/');

        if (!$settings->isEnabled()) {
            return ['state' => 'off', 'label' => Craft::t('speedy', 'Caching is off'), 'detail' => null, 'pages' => $pages];
        }
        foreach ($settings->getNeverCache() as $pattern) {
            $regex = '#^' . str_replace('\*', '.*', preg_quote(trim((string)$pattern, '/'), '#')) . '$#i';
            if (preg_match($regex, $path)) {
                return ['state' => 'never', 'label' => Craft::t('speedy', 'Never cached'), 'detail' => Craft::t('speedy', 'Matches {pattern}', ['pattern' => $pattern]), 'pages' => $pages];
            }
        }
        $key = $plugin->storage->key($parts['host'] ?? '', $path);
        $meta = $key ? $plugin->storage->meta($key) : null;
        if (!$meta || !$plugin->storage->exists((string)$key)) {
            return ['state' => 'uncached', 'label' => Craft::t('speedy', 'Not cached'), 'detail' => null, 'pages' => $pages];
        }
        $formatter = Craft::$app->getFormatter();

        return [
            'state' => 'cached',
            'label' => Craft::t('speedy', 'Cached'),
            'detail' => $formatter->asRelativeTime($meta['created']) . (($meta['expires'] ?? null) ? ' · ' . Craft::t('speedy', 'rebuilt {time}', ['time' => $formatter->asRelativeTime($meta['expires'])]) : ''),
            'pages' => $pages,
        ];
    }

    /**
     * Setup checks and hints, most important first.
     *
     * @return array<int, array{level: string, message: string}>
     */
    public function checks(): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $general = Craft::$app->getConfig()->getGeneral();
        $checks = [];
        $add = function(string $level, string $message) use (&$checks) {
            $checks[] = ['level' => $level, 'message' => $message];
        };

        if (App::isEphemeral()) {
            $add('warning', Craft::t('speedy', 'This host’s disk is temporary, as on Craft Cloud, so Speedy can’t keep pages and is off. Craft Cloud caches pages itself.'));
        } elseif (!$settings->isEnabled()) {
            $add('warning', Craft::t('speedy', 'Caching is off, so pages are built for every visit.'));
        }
        foreach ([$plugin->storage->cacheRoot(), $plugin->storage->dataRoot()] as $dir) {
            $parent = is_dir($dir) ? $dir : dirname($dir);
            if (!is_writable($parent)) {
                $add('error', Craft::t('speedy', 'Speedy can’t write to {dir}.', ['dir' => $dir]));
            }
        }
        // Other full-page caches; getPlugin() only returns enabled ones.
        foreach (['blitz'] as $handle) {
            $other = Craft::$app->getPlugins()->getPlugin($handle);
            if ($other) {
                $add('error', Craft::t('speedy', '{plugin} is enabled too. Two page caches fight: it marks pages as not cacheable, so Speedy can’t cache them. Use one.', ['plugin' => $other->name]));
            }
        }
        $failed = $this->failedJobs();
        if ($failed) {
            $add('error', Craft::t('speedy', '{count, plural, =1{# Speedy queue job has} other{# Speedy queue jobs have}} failed. See Utilities → Queue Manager.', ['count' => $failed]));
        }
        $last = $plugin->cdn->lastResults();
        foreach ($last as $cdn => $result) {
            if ($result['error']) {
                $add('error', Craft::t('speedy', 'The last {cdn} clear failed: {result}', ['cdn' => $cdn === Cdn::CLOUDFRONT ? 'CloudFront' : 'Cloudflare', 'result' => $result['result']]));
            }
        }
        $detected = $plugin->cdn->detected();
        if ($detected && !in_array($detected, $plugin->cdn->configured(), true)) {
            $add('hint', Craft::t('speedy', 'Your site is behind {cdn}. Add its details in Settings and it can keep pages too, closer to visitors.', ['cdn' => $detected === Cdn::CLOUDFRONT ? 'CloudFront' : 'Cloudflare']));
        }
        $usage = $plugin->cdn->cloudfrontUsage();
        if ($usage['paths'] > 800) {
            $add('warning', Craft::t('speedy', '{count} CloudFront paths used this month. The first 1,000 are free; lower wildcardThreshold to use fewer.', ['count' => $usage['paths']]));
        }
        $everything = $plugin->storage->countPagesForTag('element::craft\elements\Entry::*');
        if ($everything > 0) {
            $add('hint', Craft::t('speedy', '{count, plural, =1{# page is} other{# pages are}} cleared whenever any entry is saved, as {count, plural, =1{it lists} other{they list}} entries without choosing a section. Add `.section()` to those queries to clear them less often.', ['count' => $everything]));
        }
        if (!$general->generateTransformsBeforePageLoad) {
            $add('hint', Craft::t('speedy', 'Set generateTransformsBeforePageLoad to true in config/general.php, so cached pages link to finished image transforms.'));
        }
        if ($general->devMode && Craft::$app->env === 'production') {
            $add('warning', Craft::t('speedy', 'devMode is on in production.'));
        }
        if (!($plugin->storage->readData('state')['serverChecked'] ?? false)) {
            $add('hint', Craft::t('speedy', 'Add the server rules below so cached pages are sent without PHP, then click “Check server serving”.'));
        }

        return $checks;
    }

    private function describeQueryTag(string $class, string $tag): string
    {
        $type = class_exists($class) && is_subclass_of($class, ElementInterface::class) ? $class::pluralDisplayName() : $class;
        if ($tag === '*') {
            return Craft::t('speedy', 'Any of: {type}', ['type' => $type]);
        }
        [$kind, $id] = array_pad(explode(':', $tag, 2), 2, null);
        $name = match ($kind) {
            'section' => Craft::$app->getEntries()->getSectionById((int)$id)?->name,
            'group' => Craft::$app->getCategories()->getGroupById((int)$id)?->name,
            'volume' => Craft::$app->getVolumes()->getVolumeById((int)$id)?->name,
            'field' => Craft::$app->getFields()->getFieldById((int)$id)?->name,
            default => null,
        };

        return $name ? "$type: $name" : "$type: $tag";
    }

    private function failedJobs(): int
    {
        $queue = Craft::$app->getQueue();
        if (!$queue instanceof \craft\queue\Queue) {
            return 0;
        }
        $count = 0;
        foreach ($queue->getJobInfo() as $job) {
            if (($job['status'] ?? null) === \craft\queue\Queue::STATUS_FAILED && str_starts_with((string)($job['description'] ?? ''), 'Speedy Cache:')) {
                $count++;
            }
        }

        return $count;
    }
}
