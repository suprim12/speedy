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
use craft\helpers\Queue;
use craft\helpers\UrlHelper;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Psr\Http\Message\ResponseInterface;
use suprim\speedy\events\WarmEvent;
use suprim\speedy\jobs\WarmJob;
use suprim\speedy\Plugin;
use Throwable;

/**
 * Builds pages ahead of visitors, in the background.
 */
class Warmer extends Component
{
    /** Before pages are queued to be built: change the URLs, or stop it. */
    public const EVENT_BEFORE_WARM = 'beforeWarm';

    /** After pages are built, with the ones that were and weren’t. */
    public const EVENT_AFTER_WARM = 'afterWarm';

    /**
     * The value of the X-Speedy-Warm header, so only Speedy’s own requests can rebuild pages. It’s for one page and
     * a short time, so if it ever reached anyone else it couldn’t be used to build any other page.
     */
    public function token(string $url, int $expires): string
    {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);

        return $this->tokenFor((string)($parts['host'] ?? ''), (string)($parts['path'] ?? ''), $query, $expires);
    }

    /**
     * The token for a request’s host, path and query. Speedy’s rebuild request and the site work it out the same
     * way from the same address, whether or not Craft is in a subfolder.
     *
     * @param array<string, mixed> $query
     */
    public function tokenFor(string $host, string $path, array $query, int $expires): string
    {
        unset($query[Cacher::WARM_PARAM]);
        ksort($query);
        $address = strtolower($host) . '/' . trim(rawurldecode($path), '/') . ($query ? '?' . http_build_query($query) : '');

        return $expires . '.' . hash_hmac('sha256', "speedy-warm|$address|$expires", Craft::$app->getConfig()->getGeneral()->securityKey);
    }

    /**
     * Where a redirect goes, as a path when it stays on the same host, such as `/now-selling/land`. Kept short.
     */
    public static function shortUrl(string $location, string $from): string
    {
        $to = parse_url($location);
        $same = !isset($to['host']) || strtolower((string)$to['host']) === strtolower((string)parse_url($from, PHP_URL_HOST));
        $short = $same ? ($to['path'] ?? '/') . (isset($to['query']) ? '?' . $to['query'] : '') : $location;

        return mb_strimwidth($short, 0, 150, '…');
    }

    /** Seconds before a page that changed while it was being rebuilt is rebuilt again. */
    public const REBUILD_AGAIN = 10;

    /** Times in an hour a page is rebuilt again before it’s removed instead, for the next visitor to build. */
    public const REBUILD_AGAIN_MOST = 5;

    /**
     * @param string[] $urls
     * @param bool $rebuild Whether these pages are being replaced: they’re removed if they can’t be rebuilt, and cleared from the CDN once rebuilt.
     * @param array<string, string> $keys The pages’ keys by URL, when known, so a page that can’t be rebuilt is removed by its own key.
     * @param int $delay Seconds to wait before warming them.
     */
    public function queue(array $urls, bool $rebuild, array $keys = [], int $delay = 0): void
    {
        $urls = array_values(array_unique(array_filter($urls)));
        if (!$urls) {
            return;
        }
        if ($this->hasEventHandlers(self::EVENT_BEFORE_WARM)) {
            $event = new WarmEvent(['urls' => $urls, 'rebuild' => $rebuild]);
            $this->trigger(self::EVENT_BEFORE_WARM, $event);
            if (!$event->isValid) {
                return;
            }
            $urls = array_values(array_unique(array_filter($event->urls)));
        }
        foreach (array_chunk($urls, 200) as $chunk) {
            Queue::push(new WarmJob(['urls' => $chunk, 'rebuild' => $rebuild, 'keys' => array_intersect_key($keys, array_flip($chunk))]), 1025, $delay ?: null);
        }
    }

    /**
     * Requests the pages so they’re cached.
     *
     * @param string[] $urls
     * @param array<string, string> $keys The pages’ keys by URL, when known.
     * @param float|null $until No page but the first is started after this time (a Unix time), such as near the end of a queue job’s time.
     * @param string[]|null $left Set to the pages not started by then, to build later.
     * @param bool $pauseWhenBusy Whether to stop starting pages while the server is busy, leaving them in `$left`.
     * @param bool $paused Set to whether it stopped because the server was busy.
     * @return array{built: int, failed: int}
     */
    public function warm(array $urls, bool $rebuild, ?callable $progress = null, array $keys = [], ?float $until = null, ?array &$left = null, bool $pauseWhenBusy = false, bool &$paused = false): array
    {
        $paused = false;
        $plugin = Plugin::getInstance();
        if ($rebuild) {
            // Changes from now on need another rebuild, as this one may have started before them.
            $cache = Craft::$app->getCache();
            foreach ($urls as $url) {
                $cache->delete(Clearer::REBUILD_LATER . md5($url));
            }
        }
        $client = Craft::createGuzzleClient(array_filter([
            'timeout' => 30,
            'http_errors' => false,
            'allow_redirects' => false,
            'verify' => !Craft::$app->getConfig()->getGeneral()->devMode,
            // For sites behind basic authentication, such as staging.
            // The login only goes to hosts known for certain, never to whatever host a request named.
            'auth' => $plugin->cacher->certainHosts() !== null ? $plugin->getSettings()->getWarmAuth() : null,
        ], fn($v) => $v !== null));
        $built = [];
        $failed = [];
        // Why each page failed, such as “status 401”, so the log and the utility page can say.
        $why = [];
        $redirects = [];
        $changed = [];
        $total = count($urls);
        $done = 0;

        $skipped = [];
        $left = [];
        $urls = array_values($urls);
        $requests = function() use ($urls, $plugin, $until, $pauseWhenBusy, &$skipped, &$why, &$left, &$paused) {
            foreach ($urls as $i => $url) {
                // At least one page each time, so a job that started late still makes progress.
                if ($until !== null && $i > 0 && microtime(true) >= $until) {
                    $left = array_slice($urls, $i);
                    return;
                }
                // The server is busy, such as with a rush of visitors: the rest wait, so they don’t slow it further.
                if ($pauseWhenBusy && $i > 0 && $this->busy()) {
                    $left = array_slice($urls, $i);
                    $paused = true;
                    return;
                }
                $parts = parse_url($url);
                // Only the sites’ own pages, so a made-up address can’t send requests elsewhere.
                if (empty($parts['host']) || !$plugin->cacher->isSiteHost((string)$parts['host'], $parts['port'] ?? null)) {
                    $skipped[] = $url;
                    $why[$url] = Craft::t('speedy', 'not one of the sites’ addresses');
                    continue;
                }
                // A one-off query string makes CDNs pass the request on, rather than answer with their old copy.
                $warmUrl = $url . (str_contains($url, '?') ? '&' : '?') . Cacher::WARM_PARAM . '=' . bin2hex(random_bytes(4));
                yield $url => new PsrRequest('GET', $warmUrl, ['X-Speedy-Warm' => $this->token($url, time() + 900), 'User-Agent' => 'SpeedyCache/1.0 (warming)']);
            }
        };
        $pool = new Pool($client, $requests(), [
            'concurrency' => max(1, $plugin->getSettings()->warmConcurrency),
            'fulfilled' => function(ResponseInterface $response, string $url) use (&$built, &$failed, &$why, &$redirects, &$changed, &$done, $total, $progress) {
                $state = $response->getHeaderLine(Cacher::HEADER);
                if ($state === 'WARM' || $state === 'MISS') {
                    $built[] = $url;
                } elseif ($state === 'SKIP changed while building') {
                    // Changed again meanwhile: the copy there is kept, and rebuilt again below.
                    $changed[] = $url;
                } else {
                    $failed[] = $url;
                    $status = $response->getStatusCode();
                    $location = $response->getHeaderLine('Location');
                    if ($status >= 300 && $status < 400 && $location !== '') {
                        $redirects[$url] = true;
                    }
                    // Such as “SKIP private” for a page that answered, or “status 404”; Speedy’s own “SKIP status 404” adds nothing.
                    $why[$url] = match (true) {
                        $status >= 300 && $status < 400 && $location !== '' => Craft::t('speedy', 'redirects to {url}', ['url' => self::shortUrl($location, $url)]),
                        $state !== '' && $status === 200 => $state,
                        default => "status $status" . ($state !== '' && !str_contains($state, (string)$status) ? ", $state" : ''),
                    };
                    Craft::info("Couldn’t build $url: status {$response->getStatusCode()}" . ($state !== '' ? ", $state" : ''), 'speedy');
                }
                $done++;
                if ($progress) {
                    $progress($done, $total);
                }
            },
            'rejected' => function($reason, string $url) use (&$failed, &$why, &$done, $total, $progress) {
                $failed[] = $url;
                // Such as “cURL error 6: Could not resolve host”, without the link to curl’s docs.
                $why[$url] = $reason instanceof Throwable ? trim((string)preg_replace('/\s*\(see https?:\/\/\S+\).*$/s', '', $reason->getMessage())) : 'no response';
                Craft::warning("Couldn’t request $url: " . ($reason instanceof Throwable ? $reason->getMessage() : 'no response'), 'speedy');
                $done++;
                if ($progress) {
                    $progress($done, $total);
                }
            },
        ]);
        $pool->promise()->wait();
        $failed = array_merge($failed, $skipped);
        // Copies kept from before a spelling of an address in other letter case stopped being cached: removed below,
        // as they should be, rather than reported as rebuilds that failed.
        $retired = array_keys(array_filter($why, fn($w) => $w === 'SKIP url case'));
        $this->noteUnbuilt($built, array_values(array_diff($failed, $retired)), $why, $redirects);

        if ($rebuild) {
            // A page that can’t be rebuilt (now a 404, now private…) mustn’t stay cached.
            foreach ($failed as $url) {
                $key = $keys[$url] ?? $plugin->storage->keyForUrl($url);
                if ($key) {
                    $plugin->storage->delete($key);
                }
            }
            if ($problems = array_values(array_diff($failed, $retired))) {
                $reasons = array_count_values(array_map('strval', array_intersect_key($why, array_flip($problems))));
                arsort($reasons);
                $reason = (string)array_key_first($reasons);
                $plugin->log->add(Craft::t('speedy', 'Couldn’t rebuild, so removed ({reason})', ['reason' => $reason]), count($problems), $problems);
            }
            $this->noteResult(count($built), $problems, $why);
            $plugin->cdn->purgeUrls(array_merge($built, $failed));
            // The change made while it was being built couldn’t find it: its tags were let go when it was cleared.
            // Rebuild it again shortly, once, however many changes come meanwhile.
            $cache = Craft::$app->getCache();
            $again = [];
            foreach ($changed as $url) {
                $tries = 'speedy:rebuildAgain:' . md5($url);
                $count = (int)$cache->get($tries);
                if ($count >= self::REBUILD_AGAIN_MOST) {
                    // Something changes on every rebuild, such as on a busy shop: remove it, so the next visitor builds it.
                    $cache->delete($tries);
                    $key = $keys[$url] ?? $plugin->storage->keyForUrl($url);
                    if ($key) {
                        $plugin->storage->delete($key);
                    }
                    continue;
                }
                if ($cache->add(Clearer::REBUILD_LATER . md5($url), true, self::REBUILD_AGAIN + Clearer::REBUILD_LATER_GRACE)) {
                    $cache->set($tries, $count + 1, 3600);
                    $again[] = $url;
                }
            }
            if ($again) {
                $this->queue($again, true, array_intersect_key($keys, array_flip($again)), self::REBUILD_AGAIN);
            }
        }

        if ($this->hasEventHandlers(self::EVENT_AFTER_WARM)) {
            $this->trigger(self::EVENT_AFTER_WARM, new WarmEvent(['urls' => $urls, 'rebuild' => $rebuild, 'built' => $built, 'failed' => $failed]));
        }

        return ['built' => count($built), 'failed' => count($failed)];
    }

    /** The server’s load average for each CPU core, replaceable in tests; read from the server when null. */
    public ?float $load = null;

    private ?int $_cpus = null;

    /**
     * Whether the server is too busy to rebuild pages now, by its load average for each CPU core and `warmBusyLoad`.
     * Never where the load or the number of cores can’t be read, such as on Windows.
     */
    public function busy(): bool
    {
        $limit = Plugin::getInstance()->getSettings()->warmBusyLoad;
        if ($limit <= 0) {
            return false;
        }
        $load = $this->load ?? $this->loadPerCpu();

        return $load !== null && $load >= $limit;
    }

    private function loadPerCpu(): ?float
    {
        $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;
        if (!is_array($load)) {
            return null;
        }
        if ($this->_cpus === null) {
            $info = @is_readable('/proc/cpuinfo') ? (string)@file_get_contents('/proc/cpuinfo') : '';
            $this->_cpus = (int)preg_match_all('/^processor\s*:/m', $info);
        }

        return $this->_cpus > 0 ? $load[0] / $this->_cpus : null;
    }

    /** How many pages that couldn’t be built are remembered, for coverage, and for how long. */
    private const UNBUILT_MOST = 5000;
    private const UNBUILT_DAYS = 30;

    /**
     * Pages that couldn’t be built when last tried, and why, such as “status 404”.
     *
     * @return array<string, array{why: string, time: int, redirect?: bool}>
     */
    public function unbuilt(): array
    {
        return Plugin::getInstance()->storage->readData('unbuilt');
    }

    /**
     * Remembers why pages couldn’t be built, and forgets those that now were.
     *
     * @param string[] $built
     * @param string[] $failed
     * @param array<string, string> $why
     * @param array<string, bool> $redirects The pages that redirect elsewhere, which have nothing to cache.
     */
    private function noteUnbuilt(array $built, array $failed, array $why, array $redirects = []): void
    {
        $storage = Plugin::getInstance()->storage;
        // Most rebuilds change nothing here: only lock it when they do.
        if (!$failed && !array_intersect_key($storage->readData('unbuilt'), array_flip($built))) {
            return;
        }
        $storage->updateData('unbuilt', function(array $unbuilt) use ($built, $failed, $why, $redirects) {
            $unbuilt = array_diff_key($unbuilt, array_flip($built));
            foreach ($failed as $url) {
                unset($unbuilt[$url]);
                $unbuilt[$url] = ['why' => (string)($why[$url] ?? ''), 'time' => time()] + (isset($redirects[$url]) ? ['redirect' => true] : []);
            }
            $since = time() - self::UNBUILT_DAYS * 86400;

            return array_slice(array_filter($unbuilt, fn($u) => $u['time'] >= $since), -self::UNBUILT_MOST, null, true);
        });
    }

    /**
     * Notes the latest rebuild that failed, and the latest that worked, for the utility page’s checks.
     *
     * @param string[] $failed
     * @param array<string, string> $why
     */
    private function noteResult(int $built, array $failed, array $why): void
    {
        if (!$built && !$failed) {
            return;
        }
        Plugin::getInstance()->storage->updateData('state', function(array $state) use ($built, $failed, $why) {
            if ($built) {
                $state['rebuiltAt'] = time();
            }
            if ($failed) {
                $url = (string)$failed[0];
                $state['rebuildFailed'] = ['time' => time(), 'count' => count($failed), 'url' => $url, 'why' => (string)($why[$url] ?? '')];
            }
            return $state;
        });
    }

    /**
     * The pages to warm after everything is cleared: from the sitemap, or the list in the config file. Important pages
     * come first, so the homepage and top sections are ready before the rest.
     *
     * @return string[]
     */
    public function warmList(): array
    {
        $warm = Plugin::getInstance()->getSettings()->warm;
        if (is_array($warm)) {
            return array_map(fn($u) => UrlHelper::isFullUrl($u) ? $u : UrlHelper::siteUrl($u), $warm);
        }
        if ($warm !== 'sitemap') {
            return [];
        }
        $priorities = [];
        $never = Plugin::getInstance()->getSettings()->getNeverCacheSiteIds();
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            if (!$site->hasUrls || !$site->getBaseUrl() || in_array((int)$site->id, $never, true)) {
                continue;
            }
            $priorities += $this->readSitemap(rtrim($site->getBaseUrl(), '/') . '/' . ltrim(Plugin::getInstance()->getSettings()->sitemap, '/'));
        }

        return $this->importantFirst(array_keys($priorities), $priorities);
    }

    /**
     * Puts important pages first: homepages, then by the sitemap’s priority, then the shortest paths, such as a
     * section’s index before its entries. Otherwise the order is kept.
     *
     * @param string[] $urls
     * @param array<string, float|null> $priorities Sitemap priorities, from 0 to 1, by URL.
     * @return string[]
     */
    public function importantFirst(array $urls, array $priorities = []): array
    {
        // Each site’s homepage, including sites in a folder such as /de/.
        $homes = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            if ($site->hasUrls && $site->getBaseUrl()) {
                $homes[rtrim((string)$site->getBaseUrl(), '/')] = true;
            }
        }
        $rank = [];
        foreach (array_values($urls) as $i => $url) {
            $path = trim((string)parse_url($url, PHP_URL_PATH), '/');
            $home = $path === '' || isset($homes[rtrim($url, '/')]);
            $rank[$url] = [
                $home ? 0 : 1,
                -($priorities[$url] ?? 0.5),
                $home ? 0 : substr_count($path, '/') + 1,
                $i,
            ];
        }
        uksort($rank, fn($a, $b) => $rank[$a] <=> $rank[$b]);

        return array_map('strval', array_keys($rank));
    }

    /**
     * The URLs in a sitemap, with their priorities.
     *
     * @return array<string, float|null>
     */
    private function readSitemap(string $url, int $depth = 0): array
    {
        if (!in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return [];
        }
        try {
            $body = (string)Craft::createGuzzleClient(array_filter([
                'timeout' => 20,
                'verify' => !Craft::$app->getConfig()->getGeneral()->devMode,
                'auth' => Plugin::getInstance()->cacher->certainHosts() !== null ? Plugin::getInstance()->getSettings()->getWarmAuth() : null,
            ], fn($v) => $v !== null))->get($url)->getBody();
        } catch (Throwable $e) {
            Craft::warning("Couldn’t read the sitemap $url: " . $e->getMessage(), 'speedy');
            return [];
        }

        return $this->parseSitemap($body, $depth, (string)parse_url($url, PHP_URL_HOST));
    }

    /**
     * Whether a sitemap may send Speedy to this address: on the sitemap’s own host, or one of the sites’, so a
     * sitemap can’t make the server request other hosts.
     */
    private function sitemapMayList(string $url, string $sitemapHost): bool
    {
        $parts = parse_url($url);
        if (!in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || isset($parts['user'])) {
            return false;
        }

        return strtolower((string)$parts['host']) === strtolower($sitemapHost) || Plugin::getInstance()->cacher->isKnownSiteHost((string)$parts['host'], $parts['port'] ?? null);
    }

    /**
     * @param string|null $host The sitemap’s host: what it lists must be on it, or one of the sites’. Null takes any.
     * @return array<string, float|null>
     */
    public function parseSitemap(string $body, int $depth = 0, ?string $host = null): array
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            return [];
        }
        $urls = [];
        // A sitemap index lists other sitemaps.
        foreach ($xml->sitemap ?? [] as $sitemap) {
            $loc = trim((string)$sitemap->loc);
            if ($depth < 2 && ($host === null || $this->sitemapMayList($loc, $host))) {
                $urls += $this->readSitemap($loc, $depth + 1);
            }
        }
        foreach ($xml->url ?? [] as $entry) {
            $loc = trim((string)$entry->loc);
            if ($loc !== '' && !array_key_exists($loc, $urls) && ($host === null || $this->sitemapMayList($loc, $host))) {
                $priority = trim((string)$entry->priority);
                $urls[$loc] = is_numeric($priority) ? (float)$priority : null;
            }
        }

        return $urls;
    }
}
