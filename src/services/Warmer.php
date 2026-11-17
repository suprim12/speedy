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
     * @return array{built: int, failed: int}
     */
    public function warm(array $urls, bool $rebuild, ?callable $progress = null, array $keys = []): array
    {
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
            'auth' => $plugin->getSettings()->getWarmAuth(),
        ], fn($v) => $v !== null));
        $built = [];
        $failed = [];
        // Why each page failed, such as “status 401”, so the log and the utility page can say.
        $why = [];
        $changed = [];
        $total = count($urls);
        $done = 0;

        $skipped = [];
        $requests = function() use ($urls, $plugin, &$skipped, &$why) {
            foreach ($urls as $url) {
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
            'fulfilled' => function(ResponseInterface $response, string $url) use (&$built, &$failed, &$why, &$changed, &$done, $total, $progress) {
                $state = $response->getHeaderLine(Cacher::HEADER);
                if ($state === 'WARM' || $state === 'MISS') {
                    $built[] = $url;
                } elseif ($state === 'SKIP changed while building') {
                    // Changed again meanwhile: that change rebuilds it, so the copy there is kept until then.
                    $changed[] = $url;
                } else {
                    $failed[] = $url;
                    $status = $response->getStatusCode();
                    // Such as “SKIP private” for a page that answered, or “status 404”; Speedy’s own “SKIP status 404” adds nothing.
                    $why[$url] = $state !== '' && $status === 200 ? $state : "status $status" . ($state !== '' && !str_contains($state, (string)$status) ? ", $state" : '');
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

        if ($rebuild) {
            // A page that can’t be rebuilt (now a 404, now private…) mustn’t stay cached.
            foreach ($failed as $url) {
                $key = $keys[$url] ?? $plugin->storage->keyForUrl($url);
                if ($key) {
                    $plugin->storage->delete($key);
                }
            }
            if ($failed) {
                $reasons = array_count_values(array_map('strval', $why));
                arsort($reasons);
                $reason = (string)array_key_first($reasons);
                $plugin->log->add(Craft::t('speedy', 'Couldn’t rebuild, so removed ({reason})', ['reason' => $reason]), count($failed), $failed);
            }
            $this->noteResult(count($built), $failed, $why);
            $plugin->cdn->purgeUrls(array_merge($built, $failed));
        }

        if ($this->hasEventHandlers(self::EVENT_AFTER_WARM)) {
            $this->trigger(self::EVENT_AFTER_WARM, new WarmEvent(['urls' => $urls, 'rebuild' => $rebuild, 'built' => $built, 'failed' => $failed]));
        }

        return ['built' => count($built), 'failed' => count($failed)];
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
        $storage = Plugin::getInstance()->storage;
        $state = $storage->readData('state');
        if ($built) {
            $state['rebuiltAt'] = time();
        }
        if ($failed) {
            $url = (string)$failed[0];
            $state['rebuildFailed'] = ['time' => time(), 'count' => count($failed), 'url' => $url, 'why' => (string)($why[$url] ?? '')];
        }
        $storage->writeData('state', $state);
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
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            if (!$site->hasUrls || !$site->getBaseUrl()) {
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
        try {
            $body = (string)Craft::createGuzzleClient(array_filter([
                'timeout' => 20,
                'verify' => !Craft::$app->getConfig()->getGeneral()->devMode,
                'auth' => Plugin::getInstance()->getSettings()->getWarmAuth(),
            ], fn($v) => $v !== null))->get($url)->getBody();
        } catch (Throwable $e) {
            Craft::warning("Couldn’t read the sitemap $url: " . $e->getMessage(), 'speedy');
            return [];
        }

        return $this->parseSitemap($body, $depth);
    }

    /**
     * @return array<string, float|null>
     */
    public function parseSitemap(string $body, int $depth = 0): array
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
            if ($depth < 2) {
                $urls += $this->readSitemap((string)$sitemap->loc, $depth + 1);
            }
        }
        foreach ($xml->url ?? [] as $entry) {
            $loc = trim((string)$entry->loc);
            if ($loc !== '' && !array_key_exists($loc, $urls)) {
                $priority = trim((string)$entry->priority);
                $urls[$loc] = is_numeric($priority) ? (float)$priority : null;
            }
        }

        return $urls;
    }
}
