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
use craft\helpers\FileHelper;
use craft\helpers\Json;

/**
 * Reads another page cache’s settings and templates, and says what each becomes in Speedy, for switching over.
 */
class BlitzImport extends Component
{
    /** Template calls and what to use instead. */
    private const TEMPLATE_CALLS = [
        'craft.blitz.includeDynamic' => 'craft.speedy.dynamic(template, variables)',
        'craft.blitz.fetchUri' => 'craft.speedy.dynamic() with a template that renders the same content',
        'craft.blitz.includeCached' => 'a plain {% include %}: the page around it is cached already',
        'craft.blitz.csrfInput' => 'csrfInput(): Speedy refreshes tokens on cached pages itself',
        'craft.blitz.csrfToken' => 'csrfToken(): Speedy refreshes tokens on cached pages itself',
        'craft.blitz.csrfParam' => 'csrfParam(): Speedy refreshes tokens on cached pages itself',
        'craft.blitz.options' => 'craft.speedy.skip(), craft.speedy.ttl(seconds) and craft.speedy.tag(name)',
    ];

    /**
     * The settings, as stored in the project config with `config/blitz.php` on top.
     *
     * @return array<string, mixed>
     */
    public function readSettings(): array
    {
        $stored = Craft::$app->getProjectConfig()->get('plugins.blitz.settings') ?? [];
        $file = Craft::$app->getConfig()->getConfigFromFile('blitz');
        // A multi-environment file is keyed by environment, with '*' for all of them.
        if (isset($file['*']) && is_array($file['*'])) {
            $env = Craft::$app->getConfig()->env;
            $file = array_merge($file['*'], is_array($file[$env] ?? null) ? $file[$env] : []);
        }
        $settings = array_merge(is_array($stored) ? $stored : [], $file);

        // While it’s installed, its own settings, keeping only what differs from its defaults.
        $plugin = Craft::$app->getPlugins()->getPlugin('blitz');
        $model = $plugin?->getSettings();
        if ($model instanceof \craft\base\Model) {
            $defaults = (new ($model::class)())->toArray();
            foreach ($model->toArray() as $name => $value) {
                if (!array_key_exists($name, $settings) && Json::encode($value) !== Json::encode($defaults[$name] ?? null)) {
                    $settings[$name] = $value;
                }
            }
        }

        return $settings;
    }

    /**
     * Speedy’s settings for those, and notes on what doesn’t carry over.
     *
     * @param array<string, mixed> $blitz
     * @return array{settings: array<string, mixed>, notes: string[]}
     */
    public function map(array $blitz): array
    {
        $settings = [];
        $notes = [];

        $never = [];
        foreach ((array)($blitz['excludedUriPatterns'] ?? []) as $row) {
            $pattern = is_array($row) ? ($row['uriPattern'] ?? $row[1] ?? '') : (string)$row;
            if ($pattern === '') {
                continue;
            }
            $wildcard = $this->toWildcard((string)$pattern);
            if ($wildcard === null) {
                $notes[] = "Excluded pattern “{$pattern}” is a regular expression; add it to neverCache as wildcards (*) by hand.";
            } else {
                $never[] = $wildcard;
            }
        }
        if ($never) {
            $settings['neverCache'] = array_values(array_unique($never));
        }
        $rows = (array)($blitz['includedUriPatterns'] ?? []);
        $patternOf = fn($row) => (string)(is_array($row) ? ($row['uriPattern'] ?? $row[1] ?? '') : $row);
        $included = array_filter($rows, fn($row) => !in_array($patternOf($row), ['', '.*', '.+', '*'], true));
        // A row that caches everything, or rows for particular sites, can’t become one Only cache list without
        // leaving some sites uncached.
        $catchAll = count($included) < count($rows);
        $perSite = (bool)array_filter($rows, fn($row) => is_array($row) && !empty($row['siteId'] ?? $row[0] ?? null));
        if ($included && ($catchAll || $perSite)) {
            $notes[] = 'Included URI patterns differ between sites, or one includes everything. Speedy caches every page; to cache only some, set onlyCache by hand.';
        } elseif ($included) {
            $only = [];
            foreach ($included as $row) {
                $pattern = is_array($row) ? ($row['uriPattern'] ?? $row[1] ?? '') : (string)$row;
                $wildcard = $this->toWildcard((string)$pattern);
                if ($wildcard === null) {
                    $notes[] = "Included pattern “{$pattern}” is a regular expression; add it to onlyCache as wildcards (*) by hand.";
                } else {
                    // The homepage is `^$`.
                    $only[] = $wildcard === '' ? '/' : $wildcard;
                }
            }
            if ($only) {
                $settings['onlyCache'] = array_values(array_unique($only));
            }
        } elseif (array_key_exists('includedUriPatterns', $blitz) && !$blitz['includedUriPatterns']) {
            $notes[] = 'No included URI patterns were set, so nothing was being cached. Speedy caches every page.';
        }

        $mode = (int)($blitz['queryStringCaching'] ?? 0);
        if ($mode === 2) {
            $settings['queryStrings'] = 'ignore';
        } elseif ($mode === 1) {
            $params = [];
            foreach ((array)($blitz['includedQueryStringParams'] ?? []) as $row) {
                $param = is_array($row) ? ($row['queryStringParam'] ?? '') : (string)$row;
                if ($param !== '' && !in_array($param, ['.*', '.+', '*'], true)) {
                    $params[] = $this->toWildcard((string)$param) ?? (string)$param;
                }
            }
            if ($params) {
                $settings['cachedParams'] = array_values(array_unique($params));
            } else {
                $notes[] = 'Every query string was cached as its own page. Speedy only caches the parameters in cachedParams, such as page, so list the ones that change content.';
            }
        }
        $ignored = [];
        foreach ((array)($blitz['excludedQueryStringParams'] ?? []) as $row) {
            $param = is_array($row) ? ($row['queryStringParam'] ?? '') : (string)$row;
            if ($param !== '') {
                $ignored[] = $this->toWildcard((string)$param) ?? (string)$param;
            }
        }
        if ($ignored) {
            $settings['ignoredParams'] = array_values(array_unique(array_merge(\suprim\speedy\Plugin::getInstance()->getSettings()->ignoredParams, $ignored)));
        }

        if (isset($blitz['refreshMode'])) {
            // 2 and 3 generate pages again after clearing or expiring them.
            $settings['rebuildCleared'] = in_array((int)$blitz['refreshMode'], [2, 3], true);
        }
        if (array_key_exists('refreshCacheWhenElementSavedUnchanged', $blitz)) {
            $settings['ignoreUnchangedSaves'] = !$blitz['refreshCacheWhenElementSavedUnchanged'];
        }
        if (!empty($blitz['nonCacheableElementTypes'])) {
            $settings['ignoredElementTypes'] = array_values(array_filter((array)$blitz['nonCacheableElementTypes'], 'is_string'));
        }
        if (isset($blitz['cacheDuration']) && is_numeric($blitz['cacheDuration']) && (int)$blitz['cacheDuration'] > 0) {
            $settings['defaultTtl'] = (int)$blitz['cacheDuration'];
        }
        if (array_key_exists('outputComments', $blitz)) {
            $settings['cacheComment'] = (bool)$blitz['outputComments'];
        }
        if (!empty($blitz['apiKey']) && strlen((string)$blitz['apiKey']) >= 16) {
            $settings['apiKey'] = (string)$blitz['apiKey'];
        }

        $storage = (string)($blitz['cacheStorageType'] ?? '');
        if (str_contains($storage, 'YiiCacheStorage')) {
            $settings['storage'] = 'craftCache';
        } elseif ($storage !== '' && !str_contains($storage, 'FileStorage')) {
            $notes[] = "Storage $storage has no match; Speedy keeps pages in files, or in Craft’s cache (Redis, Memcached).";
        }

        $generator = (array)($blitz['cacheGeneratorSettings'] ?? []);
        if (!empty($generator['concurrency'])) {
            $settings['warmConcurrency'] = max(1, min(10, (int)$generator['concurrency']));
        }
        if (!empty($generator['useBasicAuth'])) {
            $settings['warmUsername'] = (string)($generator['username'] ?? '');
            $settings['warmPassword'] = (string)($generator['password'] ?? '');
        }
        $custom = [];
        foreach ((array)($blitz['customSiteUris'] ?? []) as $row) {
            $uri = is_array($row) ? ($row['uri'] ?? '') : (string)$row;
            if ($uri !== '') {
                $custom[] = (string)$uri;
            }
        }
        if ($custom) {
            $notes[] = 'Custom site URIs were warmed as well as the cached pages: ' . implode(', ', array_slice($custom, 0, 5)) . (count($custom) > 5 ? '…' : '') . '. Add them to warm, or rely on the sitemap.';
        }

        $purger = (string)($blitz['cachePurgerType'] ?? '');
        $purgerSettings = (array)($blitz['cachePurgerSettings'] ?? []);
        if (str_contains($purger, 'CloudflarePurger')) {
            if (!empty($purgerSettings['apiToken'])) {
                $settings['cloudflareToken'] = (string)$purgerSettings['apiToken'];
            } else {
                $notes[] = 'Cloudflare used a global API key and email. Speedy needs an API token with the Cache Purge permission.';
            }
            // Zones by site UID.
            $zones = array_filter(array_map(fn($z) => (string)(is_array($z) ? ($z['zoneId'] ?? '') : $z), (array)($purgerSettings['zoneIds'] ?? [])));
            if ($zones) {
                $settings['cloudflareZoneId'] = (string)reset($zones);
            }
            if (count(array_unique($zones)) > 1) {
                $byDomain = [];
                foreach ($zones as $siteUid => $zone) {
                    $site = array_values(array_filter(Craft::$app->getSites()->getAllSites(true), fn($s) => $s->uid === (string)$siteUid))[0] ?? null;
                    $host = $site ? parse_url((string)$site->getBaseUrl(), PHP_URL_HOST) : null;
                    if ($host) {
                        $byDomain[preg_replace('/^www\./', '', strtolower($host))] = $zone;
                    } else {
                        $notes[] = "Cloudflare zone $zone is for a site that isn’t here; add it to cloudflareZones by hand.";
                    }
                }
                if ($byDomain) {
                    $settings['cloudflareZones'] = $byDomain;
                }
            }
        } elseif ($purger !== '' && !str_contains($purger, 'DummyPurger')) {
            $notes[] = "Purger $purger has no direct match. Speedy clears Cloudflare, CloudFront, Fastly, Bunny, KeyCDN and Varnish; see the CDN tab.";
        }

        if (!empty($blitz['ssiEnabled']) || !empty($blitz['esiEnabled'])) {
            $notes[] = 'Server-side includes were on. Speedy loads dynamic parts with one request after the page loads, with no server setup: use craft.speedy.dynamic().';
        }
        if (!empty($blitz['cacheNonHtmlResponses'])) {
            $notes[] = 'Responses other than HTML were cached. Speedy caches HTML pages only.';
        }
        $deployer = (string)($blitz['deployerType'] ?? '');
        if ($deployer !== '' && !str_contains($deployer, 'DummyDeployer')) {
            $notes[] = 'Pages were deployed to a Git repository. Speedy serves pages from your own server and CDN.';
        }

        return ['settings' => $settings, 'notes' => $notes];
    }

    /**
     * Template lines that call the other plugin, with what to use instead.
     *
     * @return array<int, array{file: string, line: int, call: string, instead: string}>
     */
    public function scanTemplates(): array
    {
        $root = (string)Craft::getAlias('@templates');
        if (!is_dir($root)) {
            return [];
        }
        $found = [];
        $files = FileHelper::findFiles($root, ['only' => ['*.twig', '*.html', '*.htm'], 'except' => ['node_modules/', 'vendor/']]);
        foreach ($files as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES);
            foreach (is_array($lines) ? $lines : [] as $i => $line) {
                if (!str_contains($line, 'craft.blitz')) {
                    continue;
                }
                $call = 'craft.blitz';
                $instead = 'remove it';
                foreach (self::TEMPLATE_CALLS as $name => $replacement) {
                    if (str_contains($line, $name)) {
                        $call = $name;
                        $instead = $replacement;
                        break;
                    }
                }
                $found[] = ['file' => substr($file, strlen($root) + 1), 'line' => $i + 1, 'call' => $call, 'instead' => $instead];
            }
        }

        return $found;
    }

    /**
     * A regular expression as a wildcard pattern, where it’s simple enough: `blog/.*` → `blog/*`. Null otherwise.
     */
    private function toWildcard(string $pattern): ?string
    {
        $simple = str_replace(['.*', '.+', '\\.', '\\-', '\\/'], ['*', '*', '.', '-', '/'], trim($pattern, '^$/'));

        return preg_match('/[\\\\()\[\]{}|?+^$]/', $simple) ? null : $simple;
    }
}
