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
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\Category;
use craft\elements\Entry;
use craft\helpers\App;
use craft\helpers\ElementHelper;
use craft\helpers\Html;
use craft\web\View;
use suprim\speedy\Plugin;
use yii\base\Event;

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

    /** The most element pages coverage counts, so the utility page stays quick on very large sites. */
    public const COVERAGE_MOST = 50000;

    public const COVERAGE_CACHE = 'speedy:coverage';

    /** @var array<string, bool> Whether each section’s or category group’s template is missing, by type, group and site. */
    private array $_templateMissing = [];

    /**
     * How many of the pages of entries, categories, products and other elements with URLs are cached, by section or
     * group, with the ones that aren’t. Pages left out by Never cache or Only cache don’t count. Counted at most every
     * five minutes, like the cached pages, unless $fresh. Pages that couldn’t be built when last tried are listed
     * apart, with why. Pages of sections and category groups whose template doesn’t exist are only counted, by name.
     *
     * @return array{total: int, cached: int, never: int, noTemplate: array<string, int>, groups: array<int, array{label: string, total: int, cached: int, missing: string[], failed: array<string, string>}>, time: int, capped: bool}
     */
    public function coverage(bool $fresh = false): array
    {
        $cache = Craft::$app->getCache();
        if (!$fresh) {
            $coverage = $cache->get(self::COVERAGE_CACHE);
            if (is_array($coverage) && isset($coverage['groups'])) {
                return $coverage;
            }
        }
        $plugin = Plugin::getInstance();
        $storage = $plugin->storage;
        $cached = [];
        foreach ($storage->allKeys() as $key) {
            $cached[$key] = true;
        }
        $unbuilt = $plugin->warmer->unbuilt();
        $sites = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            if ($site->hasUrls && $site->getBaseUrl()) {
                $sites[$site->id] = rtrim((string)$site->getBaseUrl(), '/');
            }
        }

        $groups = [];
        $never = 0;
        $redirects = 0;
        $noTemplate = [];
        $count = 0;
        foreach ($sites ? Craft::$app->getElements()->getAllElementTypes() : [] as $class) {
            /** @var class-string<ElementInterface> $class */
            if (!$class::hasUris()) {
                continue;
            }
            [$groupColumn, $labels] = $this->coverageGroups($class);
            $query = $class::find()
                ->asArray()
                ->site('*')
                ->siteId(array_keys($sites))
                ->uri(':notempty:')
                ->select(array_filter(['elements_sites.uri', 'elements_sites.siteId', $groupColumn]))
                ->limit(self::COVERAGE_MOST - $count + 1);
            foreach ($query->all() as $row) {
                if (++$count > self::COVERAGE_MOST) {
                    break 2;
                }
                $uri = $row['uri'] === Element::HOMEPAGE_URI ? '' : (string)$row['uri'];
                // Such as `now-selling?type=land`: a link to another page with a query string, not a page of its own.
                if (strpbrk($uri, '?#') !== false) {
                    $count--;
                    continue;
                }
                $url = $sites[$row['siteId']] . '/' . $uri;
                if ($this->leftOut($url, $uri)) {
                    $never++;
                    continue;
                }
                $groupId = $groupColumn ? (int)$row[substr($groupColumn, strpos($groupColumn, '.') + 1)] : 0;
                if ($this->templateMissing($class, $groupId, (int)$row['siteId'])) {
                    $label = $labels($groupId);
                    $noTemplate[$label] = ($noTemplate[$label] ?? 0) + 1;
                    continue;
                }
                // Such as an entry type that only links elsewhere: there’s no page to cache.
                if ($unbuilt[$url]['redirect'] ?? false) {
                    $redirects++;
                    continue;
                }
                $id = "$class:$groupId";
                $groups[$id] ??= ['label' => $labels($groupId), 'total' => 0, 'cached' => 0, 'missing' => [], 'failed' => []];
                $groups[$id]['total']++;
                $key = $storage->keyForUrl($url);
                if ($key !== null && isset($cached[$key])) {
                    $groups[$id]['cached']++;
                } elseif (isset($unbuilt[$url])) {
                    $groups[$id]['failed'][$url] = $unbuilt[$url]['why'];
                } else {
                    $groups[$id]['missing'][] = $url;
                }
            }
        }
        // The groups with the most pages not cached first.
        uasort($groups, fn($a, $b) => [$b['total'] - $b['cached'], $a['label']] <=> [$a['total'] - $a['cached'], $b['label']]);

        $coverage = [
            'total' => array_sum(array_column($groups, 'total')),
            'cached' => array_sum(array_column($groups, 'cached')),
            'never' => $never,
            'redirects' => $redirects,
            'noTemplate' => $noTemplate,
            'groups' => array_values($groups),
            'time' => time(),
            'capped' => $count > self::COVERAGE_MOST,
        ];
        $cache->set(self::COVERAGE_CACHE, $coverage, 300);

        return $coverage;
    }

    /**
     * Whether a page is left out by Never cache or Only cache, matched on its full path and its path within the site,
     * as for visitors.
     *
     * @param string $sitePath Such as an element’s URI; `__home__` for a homepage.
     */
    public function leftOut(string $url, string $sitePath): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        $path = trim(rawurldecode((string)parse_url($url, PHP_URL_PATH)), '/');
        $sitePath = $sitePath === Element::HOMEPAGE_URI ? '' : $sitePath;
        if (Cacher::matchingPattern($path, $settings->getNeverCache()) !== null || Cacher::matchingPattern($sitePath, $settings->getNeverCache()) !== null) {
            return true;
        }
        $only = $settings->getOnlyCache();

        return $only && Cacher::matchingPattern($path, $only) === null && Cacher::matchingPattern($sitePath, $only) === null;
    }

    /**
     * Whether the pages of an entry section or category group in a site can’t be shown, because its template doesn’t
     * exist, such as a section with URLs whose entries are only shown on other pages. Not for other element types,
     * nested entries, a blank template, or when a plugin or module sets elements’ routes itself.
     */
    public function templateMissing(string $class, int $groupId, int $siteId): bool
    {
        $key = "$class:$groupId:$siteId";
        if (array_key_exists($key, $this->_templateMissing)) {
            return $this->_templateMissing[$key];
        }
        $settings = null;
        if ($groupId && !Event::hasHandlers($class, Element::EVENT_SET_ROUTE)) {
            if ($class === Entry::class) {
                $settings = Craft::$app->getEntries()->getSectionById($groupId)?->getSiteSettings()[$siteId] ?? null;
            } elseif ($class === Category::class) {
                $settings = Craft::$app->getCategories()->getGroupById($groupId)?->getSiteSettings()[$siteId] ?? null;
            }
        }
        $template = $settings && $settings->hasUrls ? trim((string)$settings->template) : '';

        return $this->_templateMissing[$key] = $template !== '' && !$this->siteTemplateExists($template);
    }

    /**
     * Whether a site template exists, in the templates folder, a site’s own folder in it, or a plugin’s or module’s
     * template root. Checked in site mode, and the view is put back as it was.
     */
    private function siteTemplateExists(string $template): bool
    {
        $view = Craft::$app->getView();
        $mode = $view->getTemplateMode();
        try {
            $view->setTemplateMode(View::TEMPLATE_MODE_SITE);
            if ($view->doesTemplateExist($template)) {
                return true;
            }
            foreach (Craft::$app->getSites()->getAllSites() as $site) {
                if ($view->doesTemplateExist($site->handle . '/' . $template)) {
                    return true;
                }
            }

            return false;
        } finally {
            $view->setTemplateMode($mode);
        }
    }

    /**
     * The column that groups an element type’s pages, and a function that names a group: sections for entries,
     * groups for categories, one group for other types.
     *
     * @param class-string<ElementInterface> $class
     * @return array{0: ?string, 1: callable(int): string}
     */
    private function coverageGroups(string $class): array
    {
        if ($class === Entry::class) {
            return ['entries.sectionId', fn(int $id) => Craft::$app->getEntries()->getSectionById($id)->name ?? $class::pluralDisplayName()];
        }
        if ($class === Category::class) {
            return ['categories.groupId', fn(int $id) => Craft::$app->getCategories()->getGroupById($id)->name ?? $class::pluralDisplayName()];
        }

        return [null, fn() => $class::pluralDisplayName()];
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
        $sitePath = $element->uri === '__home__' ? '' : (string)$element->uri;
        $pattern = Cacher::matchingPattern($path, $settings->getNeverCache()) ?? Cacher::matchingPattern($sitePath, $settings->getNeverCache());
        if ($pattern !== null) {
            return ['state' => 'never', 'label' => Craft::t('speedy', 'Never cached'), 'detail' => Craft::t('speedy', 'Matches {pattern}', ['pattern' => $pattern]), 'pages' => $pages];
        }
        if ($settings->getOnlyCache() && Cacher::matchingPattern($path, $settings->getOnlyCache()) === null && Cacher::matchingPattern($sitePath, $settings->getOnlyCache()) === null) {
            return ['state' => 'never', 'label' => Craft::t('speedy', 'Never cached'), 'detail' => Craft::t('speedy', 'Not in Only cache'), 'pages' => $pages];
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

        $usesCache = $plugin->storage->usesCache();
        if ($usesCache) {
            $cache = CacheStore::describe();
            if ($cache['database']) {
                $add('warning', Craft::t('speedy', 'Pages are kept in Craft’s cache, which is the database on this site, so they make the database grow. Set Craft’s cache to Redis, or keep pages in files.'));
            } elseif (!$cache['shared']) {
                $add('warning', Craft::t('speedy', 'Pages are kept in Craft’s cache, which is on this server only ({class}). Each web server keeps its own copies; files are faster for one server.', ['class' => $cache['label']]));
            }
        }
        if (App::isEphemeral() && !$usesCache) {
            $add('warning', Craft::t('speedy', 'This host’s disk is temporary, as on Craft Cloud, so Speedy can’t keep pages and is off. Craft Cloud caches pages itself.'));
        } elseif (!$settings->isEnabled()) {
            $add('warning', Craft::t('speedy', 'Caching is off, so pages are built for every visit.'));
        }
        foreach ($usesCache ? [] : [$plugin->storage->cacheRoot(), $plugin->storage->dataRoot()] as $dir) {
            $parent = is_dir($dir) ? $dir : dirname($dir);
            if (!is_writable($parent)) {
                $add('error', Craft::t('speedy', 'Speedy can’t write to {dir}.', ['dir' => $dir]));
            }
        }
        if (($unsafe = $plugin->storage->unsafeFolder()) !== null) {
            $add('error', Craft::t('speedy', '`cachePath` or `dataPath` is set to {folder}, which holds other files, so nothing is cached or cleared. Set it to a folder of its own, such as `@webroot/speedy`.', ['folder' => '`' . $unsafe . '`']));
        }
        if ($plugin->storage->diskFull()) {
            $add('error', Craft::t('speedy', 'The disk is nearly full ({free} MB free), so new pages aren’t being cached. Pages already cached are still sent, and still cleared when content changes.', ['free' => intdiv((int)$plugin->storage->freeSpace(), 1048576)]));
        }
        if ($plugin->storage->overSizeLimit()) {
            $add('warning', Craft::t('speedy', 'Cached pages use the {limit} MB allowed by maxCacheSize, so new pages aren’t being cached. Pages already cached are still sent and cleared as usual.', ['limit' => $settings->maxCacheSize]));
        }
        // Other full-page caches; getPlugin() only returns enabled ones.
        foreach (['blitz'] as $handle) {
            $other = Craft::$app->getPlugins()->getPlugin($handle);
            if ($other) {
                $add('error', Craft::t('speedy', '{plugin} is enabled too. Two page caches fight: it marks pages as not cacheable, so Speedy can’t cache them. Use one.', ['plugin' => $other->name]));
            }
        }
        $stalled = $this->queueStuckFor();
        if ($stalled) {
            $add('error', Craft::t('speedy', 'Speedy’s queue jobs have waited {minutes} minutes, so the queue isn’t running. Pages are cleared straight away until it is, and rebuilt on their next visit. Run the queue with a cron job or `php craft queue/listen`.', ['minutes' => intdiv($stalled, 60)]));
        }
        $slow = $settings->queryReport ? count($plugin->queries->report()) : 0;
        if ($slow) {
            $add('hint', Craft::t('speedy', '{count, plural, =1{# page runs} other{# pages run}} the same database query again and again, usually in a loop. See [Performance](#queries).', ['count' => $slow]));
        }
        $rebuild = $this->rebuildProblem();
        if ($rebuild) {
            $add('error', $rebuild);
        }
        $failed = $this->failedJobs();
        if ($failed) {
            $add('error', Craft::t('speedy', '{count, plural, =1{# Speedy queue job has} other{# Speedy queue jobs have}} failed. See Utilities → Queue Manager.', ['count' => $failed]));
        }
        $last = $plugin->cdn->lastResults();
        foreach ($last as $cdn => $result) {
            if ($result['error']) {
                $add('error', Craft::t('speedy', 'The last {cdn} clear failed: {result}', ['cdn' => $plugin->cdn->names()[$cdn] ?? $cdn, 'result' => $result['result']]));
            }
        }
        $detected = $plugin->cdn->detected();
        if ($detected && !in_array($detected, $plugin->cdn->configured(), true)) {
            $add('hint', Craft::t('speedy', 'Your site is behind {cdn}. Add its details in Settings and it can keep pages too, closer to visitors.', ['cdn' => $plugin->cdn->names()[$detected] ?? $detected]));
        }
        $usage = $plugin->cdn->cloudfrontUsage();
        if ($usage['paths'] > 800) {
            $add('warning', Craft::t('speedy', '{count} CloudFront paths used this month. The first 1,000 are free; lower wildcardThreshold to use fewer.', ['count' => $usage['paths']]));
        }
        foreach ($this->skippedParams() as $param => $seen) {
            $add('hint', Craft::t('speedy', 'About {count} visits to pages with `?{param}=`, such as {path}, weren’t cached because of it. If it changes what the page shows, add `{param}` under Settings → Query strings → Cached parameters; if not, under Ignored parameters.', ['count' => $seen['count'], 'param' => $param, 'path' => Html::encode($seen['path'])]));
        }
        $everything = $plugin->storage->countPagesForTag('element::craft\elements\Entry::*');
        if ($everything > 0) {
            $add('hint', Craft::t('speedy', '{count, plural, =1{# page is} other{# pages are}} cleared whenever any entry is saved, as {count, plural, =1{it lists} other{they list}} entries without choosing a section. Add `.section()` to those queries to clear them less often.', ['count' => $everything])
                . ($plugin->getSettings()->queryReport && $plugin->queries->broad() ? ' ' . Craft::t('speedy', 'See [which lines](#queries).') : ''));
        }
        if ($plugin->cacher->certainHosts() === null) {
            $add('warning', Craft::t('speedy', 'A site’s base URL is `@web`, which answers to any domain. List your domains in `allowedHosts` in `config/speedy.php`, so pages are only cached for them, visitors with cookies get pages built in the background, and the warming login is sent.'));
        }
        if (!$general->generateTransformsBeforePageLoad) {
            $add('hint', Craft::t('speedy', 'Set generateTransformsBeforePageLoad to true in config/general.php, so cached pages link to finished image transforms.'));
        }
        if ($general->devMode && Craft::$app->env === 'production') {
            $add('warning', Craft::t('speedy', 'devMode is on in production.'));
        }
        $state = $plugin->storage->readData('state');
        $webPath = $plugin->serverRules->webPath();
        // Only for a folder in the web root, so the lines to add can never name the whole site, such as `Disallow: /`.
        if (!$usesCache && ($state['folderOpen'] ?? null) === true && $webPath !== null && $webPath !== '') {
            if (($state['robotsKeepsOut'] ?? null) === true) {
                $add('hint', Craft::t('speedy', 'Cached pages can be opened directly in the cache folder, such as {path}, though robots.txt keeps search engines out of it. Add the [server rules](#setup) to close it, or on nginx just {rule}, then click “Check server serving” again.', ['path' => '`' . $webPath . '/…/index.html`', 'rule' => '`location ^~ ' . $webPath . '/ { internal; }`']));
            } else {
                $add('warning', Craft::t('speedy', 'Cached pages can be opened directly in the cache folder, such as {path}, so search engines could list a second copy of each page. Add {robots} to robots.txt to keep them out, and close the folder with the [server rules](#setup), or on nginx just {rule}. Then click “Check server serving” again.', ['path' => '`' . $webPath . '/…/index.html`', 'robots' => '`Disallow: ' . $webPath . '/`', 'rule' => '`location ^~ ' . $webPath . '/ { internal; }`']));
            }
        }
        if (!$usesCache && !($state['serverChecked'] ?? false)) {
            $add('hint', Craft::t('speedy', 'Add the [server rules](#setup) so cached pages are sent without PHP, then click “Check server serving”.'));
        }

        return $checks;
    }

    /** How many cached pages the missing CSS and JavaScript check reads, at most. */
    private const ASSET_CHECK_PAGES = 100;

    /**
     * Cached pages that link to CSS or JavaScript files that are gone from the web root, such as after a deploy
     * replaced the built files or Craft’s published plugin files (cpresources). Only when someone asks, from the
     * utility page: up to 100 pages are read from the cache and their files looked up on disk, with no requests.
     *
     * @return array{pages: int, checked: int, example: ?string}
     */
    public function missingAssets(): array
    {
        $result = ['pages' => 0, 'checked' => 0, 'example' => null];
        $webroot = rtrim((string)Craft::getAlias('@webroot', false), '/');
        // The URL path of the web root, such as /craft for a site in a folder of its domain.
        $base = rtrim((string)parse_url((string)Craft::getAlias('@web', false), PHP_URL_PATH), '/');
        $storage = Plugin::getInstance()->storage;
        if ($webroot !== '' && is_dir($webroot)) {
            foreach ($storage->allKeys() as $key) {
                if ($result['checked'] >= self::ASSET_CHECK_PAGES) {
                    break;
                }
                // Query string versions link to the same files as their page.
                if (str_contains($key, '/' . Storage::QUERY_FOLDER . '/')) {
                    continue;
                }
                $page = $storage->read($key, false);
                if ($page === null) {
                    continue;
                }
                $result['checked']++;
                $host = (string)preg_replace('/:\d+$/', '', explode('/', $key, 2)[0]);
                foreach (self::assetPaths($page['content'], $host) as $path) {
                    if ($base !== '' && !str_starts_with($path, $base . '/')) {
                        continue;
                    }
                    if ($this->assetGone($webroot, substr($path, strlen($base)))) {
                        $result['pages']++;
                        // Shown in the control panel: only plain characters, kept short.
                        $result['example'] ??= mb_strimwidth((string)preg_replace('/[^\w\/.\-~%]/u', '', $path), 0, 120, '…');
                        break;
                    }
                }
            }
        }
        return $result;
    }

    /**
     * Whether a linked file is gone from the web root. Only in a folder that’s there, such as `/dist/` or
     * `/cpresources/`, where built and published files live: a file at the top or in a folder that isn’t on disk may
     * be sent by Craft or a plugin instead, such as `/sw.js`. Looked up on disk only, never requested.
     *
     * @param string $path Within the web root, such as `/dist/main-4f2a.css`.
     */
    private function assetGone(string $webroot, string $path): bool
    {
        $first = explode('/', ltrim($path, '/'), 2);
        if (count($first) < 2 || !is_dir($webroot . '/' . $first[0])) {
            return false;
        }

        return !is_file($webroot . $path);
    }

    /**
     * The CSS and JavaScript files a page links to on its own host, as URL paths, such as `/dist/main-4f2a.css`.
     *
     * @return string[]
     */
    public static function assetPaths(string $html, string $host): array
    {
        $paths = [];
        preg_match_all('#<(script|link)\b[^>]*>#i', $html, $tags, PREG_SET_ORDER);
        foreach ($tags as [$tag, $name]) {
            $attr = fn(string $a) => preg_match('#\s' . $a . '\s*=\s*(["\'])(.*?)\1#is', $tag, $m) ? html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5) : null;
            if (strtolower($name) === 'script') {
                $url = $attr('src');
            } else {
                $rel = strtolower((string)$attr('rel'));
                $url = preg_match('/\b(stylesheet|modulepreload|preload)\b/', $rel) ? $attr('href') : null;
            }
            $parts = $url !== null ? parse_url($url) : false;
            if (!is_array($parts) || (isset($parts['scheme']) && !in_array(strtolower($parts['scheme']), ['http', 'https'], true))) {
                continue;
            }
            // Only this site’s own files: a CDN or another domain isn’t on this disk.
            if (isset($parts['host']) && strtolower($parts['host']) !== strtolower($host)) {
                continue;
            }
            $path = rawurldecode($parts['path'] ?? '');
            if (!str_starts_with($path, '/') || str_contains($path, '..') || !preg_match('/\.(css|js|mjs)$/i', $path)) {
                continue;
            }
            $paths[$path] = true;
        }

        return array_keys($paths);
    }


    /**
     * A plain-text summary for support requests: versions, settings (secrets only as “set”), checks and recent clears.
     */
    public function report(): string
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $craft = Craft::$app;
        $stats = $plugin->storage->cachedStats();
        $set = fn(string $v) => $v !== '' ? 'set' : 'not set';
        $lines = [
            '# Speedy Cache support report',
            '',
            'Generated: ' . date('c'),
            'Speedy Cache: ' . $plugin->getVersion(),
            'Craft: ' . $craft->getVersion() . ' ' . ucfirst(Craft::$app->edition->handle()),
            'PHP: ' . PHP_VERSION,
            'Database: ' . $craft->getDb()->getDriverLabel() . ' ' . $craft->getDb()->getSchema()->getServerVersion(),
            'Environment: ' . ($craft->env ?? 'unknown') . ($craft->getConfig()->getGeneral()->devMode ? ' (devMode on)' : ''),
            'Temporary disk: ' . (App::isEphemeral() ? 'yes' : 'no'),
            '',
            '## Caching',
            'Enabled: ' . ($settings->isEnabled() ? 'yes' : 'no'),
            'Storage: ' . $settings->storage . ($plugin->storage->usesCache() ? ' (' . CacheStore::describe()['class'] . ')' : ''),
            'Pages cached: ' . $stats['pages'] . ' (' . round($stats['bytes'] / 1048576, 1) . ' MB)',
            'Never cache: ' . (implode(', ', $settings->getNeverCache()) ?: 'none'),
            'Only cache: ' . (implode(', ', $settings->getOnlyCache()) ?: 'every page'),
            'Query strings: ' . $settings->queryStrings . '; skip with cart: ' . ($settings->skipWhenCart ? 'yes' : 'no') . '; skip cookies: ' . (implode(', ', $settings->skipCookies) ?: 'none'),
            'Rebuild changed pages: ' . ($settings->rebuildCleared ? 'yes' : 'no') . '; after clearing everything: ' . (is_array($settings->warm) ? count($settings->warm) . ' listed pages' : ($settings->warm ?: 'wait for visitors')),
            'Default TTL: ' . ($settings->defaultTtl ?? 'none') . '; rebuild auth: ' . ($settings->getWarmAuth() ? 'set' : 'not set') . '; API key: ' . $set($settings->getApiKey()),
            'Set in config/speedy.php: ' . (is_array($file = $craft->getConfig()->getConfigFromFile('speedy')) ? (implode(', ', array_keys($file)) ?: 'nothing') : 'a closure or config object'),
            'Server serving checked: ' . (($plugin->storage->readData('state')['serverChecked'] ?? false) ? 'yes' : 'no'),
            '',
            '## CDN',
            'Detected: ' . ($plugin->cdn->detected() ?? 'none'),
            'Clearing: ' . (implode(', ', $plugin->cdn->configured()) ?: 'none'),
        ];
        foreach ($plugin->cdn->lastResults() as $cdn => $last) {
            $lines[] = "Last $cdn result: " . $last['result'] . ' (' . date('c', $last['time']) . ')';
        }
        $lines[] = '';
        $lines[] = '## Checks';
        foreach ($this->checks() ?: [['level' => 'ok', 'message' => 'Nothing to report.']] as $check) {
            $lines[] = '- ' . strtoupper($check['level']) . ': ' . strip_tags((string)$check['message']);
        }
        $lines[] = '';
        $lines[] = '## Recent clears';
        foreach ($plugin->log->recent(15) as $entry) {
            $lines[] = '- ' . date('Y-m-d H:i', (int)$entry['time']) . ' · ' . $entry['count'] . ' · ' . $entry['reason'];
        }
        $lines[] = '';
        $lines[] = '## Repeated queries';
        foreach (array_slice($plugin->queries->report(), 0, 10) as $page) {
            foreach ($page['repeated'] as $repeat) {
                $lines[] = '- ' . $page['url'] . ' · ' . $repeat['count'] . '× of ' . $page['queries'] . ' queries' . ($repeat['at'] ? ' · ' . $repeat['at'] : '');
            }
        }
        $lines[] = '';
        $lines[] = '## Slowest pages';
        foreach (array_slice($plugin->queries->slowest(), 0, 10) as $page) {
            $lines[] = '- ' . $page['url'] . ' · ' . $page['ms'] . ' ms · ' . $page['queries'] . ' queries';
        }
        $lines[] = '';
        $lines[] = '## Cleared by any change';
        foreach (array_slice($plugin->queries->broad(), 0, 10) as $page) {
            foreach ($page['broad'] as $query) {
                $lines[] = '- ' . $page['url'] . ' · any of ' . $query['type'] . ($query['at'] ? ' · ' . $query['at'] : '');
            }
        }
        $lines[] = '';
        $lines[] = '## Plugins';
        foreach ($craft->getPlugins()->getAllPlugins() as $other) {
            $lines[] = '- ' . $other->name . ' ' . $other->getVersion();
        }

        return implode("\n", $lines) . "\n";
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

    /**
     * The query parameters that kept the most visits out of the cache in the past week, three at most, with roughly
     * how many visits each.
     *
     * @return array<string, array{count: int, path: string}>
     */
    public function skippedParams(): array
    {
        $seen = Craft::$app->getCache()->get(Cacher::SKIPPED_PARAMS);
        if (!is_array($seen)) {
            return [];
        }
        $allowed = Plugin::getInstance()->getSettings()->cachedParams;
        $ignored = Plugin::getInstance()->getSettings()->ignoredParams;
        $params = [];
        foreach ($seen as $param => $info) {
            $param = (string)$param;
            $count = (int)($info['count'] ?? 0) * Cacher::SKIPPED_SAMPLE;
            // Set up since: no longer a problem.
            if ($count < 50 || in_array($param, $allowed, true) || in_array($param, $ignored, true)) {
                continue;
            }
            $params[$param] = ['count' => $count, 'path' => (string)($info['path'] ?? '/')];
        }
        uasort($params, fn($a, $b) => $b['count'] <=> $a['count']);

        return array_slice($params, 0, 3, true);
    }

    /**
     * When the latest background rebuild failed in the past day, and none has worked since: what happened and what to
     * do. Pages that can’t be rebuilt are removed, so this is why a cache can keep emptying itself.
     */
    public function rebuildProblem(): ?string
    {
        $state = Plugin::getInstance()->storage->readData('state');
        $failed = $state['rebuildFailed'] ?? null;
        if (!is_array($failed) || (int)($failed['time'] ?? 0) < time() - 86400 || (int)($state['rebuiltAt'] ?? 0) > (int)$failed['time']) {
            return null;
        }
        $why = (string)($failed['why'] ?? '');
        $host = (string)parse_url((string)($failed['url'] ?? ''), PHP_URL_HOST);
        $advice = match (true) {
            (bool)preg_match('/^status 40[13]\b/', $why) => Craft::t('speedy', 'The site asks for a password, or blocks the request: set `warmUsername` and `warmPassword` in config/speedy.php, or let requests with the X-Speedy-Warm header through.'),
            str_starts_with($why, 'status 5') => Craft::t('speedy', 'The page had an error while it was rebuilt: see storage/logs.'),
            str_starts_with($why, 'cURL error') || str_contains($why, 'resolve') || str_contains($why, 'SSL') => Craft::t('speedy', 'This server couldn’t open {host} itself. Check that the server can reach its own address: DNS, firewall and SSL certificate.', ['host' => $host]),
            default => '',
        };

        return trim(Craft::t('speedy', 'Pages couldn’t be rebuilt in the background ({reason}), so they were removed and are built again on their next visit.', ['reason' => Html::encode(str_replace('`', '', $why))]) . ' ' . $advice);
    }

    /** How long a Speedy job can wait before the queue counts as stuck, in seconds. */
    public const STALL_AFTER = 600;

    /**
     * How long, in seconds, Speedy’s oldest job has been waiting for the queue, once it’s more than ten minutes.
     * Zero while the queue is running. Checked once a minute.
     */
    public function queueStalledFor(): int
    {
        $queue = Craft::$app->getQueue();
        if (!$queue instanceof \craft\queue\Queue) {
            // A Redis or SQS queue can’t be inspected the same way.
            return 0;
        }

        return (int)Craft::$app->getCache()->getOrSet('speedy:queueStalled', function() use ($queue) {
            $oldest = (new \craft\db\Query())
                ->from($queue->tableName)
                ->where(['fail' => false, 'dateReserved' => null])
                ->andWhere(['like', 'description', 'Speedy Cache:%', false])
                ->min('[[timePushed]] + [[delay]]');
            $age = $oldest ? time() - (int)$oldest : 0;

            return $age > self::STALL_AFTER ? $age : 0;
        }, 60);
    }

    /**
     * How long, in seconds, the queue has been stuck, for telling people about it.
     *
     * When the control panel runs the queue (`runQueueAutomatically`), jobs wait while nobody has it open, which is normal on
     * a quiet site. The wait then counts from when the control panel was opened, so it only shows if the queue still doesn’t run.
     */
    public function queueStuckFor(): int
    {
        $stalled = $this->queueStalledFor();
        if (!$stalled || !Craft::$app->getConfig()->getGeneral()->runQueueAutomatically || !Craft::$app->getRequest()->getIsCpRequest()) {
            return $stalled;
        }

        // When this visit to the control panel started: a gap of more than ten minutes starts a new one.
        $now = time();
        $cache = Craft::$app->getCache();
        $visit = $cache->get('speedy:cpVisit');
        if (!is_array($visit) || $now - (int)$visit['last'] > self::STALL_AFTER) {
            $visit = ['since' => $now, 'last' => $now];
        }
        $visit['last'] = $now;
        $cache->set('speedy:cpVisit', $visit, 86400);
        $waited = min($stalled, $now - (int)$visit['since']);

        return $waited > self::STALL_AFTER ? $waited : 0;
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
