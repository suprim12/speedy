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
use suprim\speedy\jobs\WarmJob;
use suprim\speedy\Plugin;
use Throwable;

/**
 * Builds pages ahead of visitors, in the background.
 */
class Warmer extends Component
{
    /**
     * The value of the X-Speedy-Warm header, so only Speedy’s own requests can rebuild pages.
     */
    public function secret(): string
    {
        return hash_hmac('sha256', 'speedy-warm', Craft::$app->getConfig()->getGeneral()->securityKey);
    }

    /**
     * @param string[] $urls
     * @param bool $rebuild Whether these pages are being replaced: they’re removed if they can’t be rebuilt, and cleared from the CDN once rebuilt.
     */
    public function queue(array $urls, bool $rebuild): void
    {
        $urls = array_values(array_unique(array_filter($urls)));
        foreach (array_chunk($urls, 200) as $chunk) {
            Queue::push(new WarmJob(['urls' => $chunk, 'rebuild' => $rebuild]), 1025);
        }
    }

    /**
     * Requests the pages so they’re cached.
     *
     * @param string[] $urls
     * @return array{built: int, failed: int}
     */
    public function warm(array $urls, bool $rebuild, ?callable $progress = null): array
    {
        $plugin = Plugin::getInstance();
        $client = Craft::createGuzzleClient(['timeout' => 30, 'http_errors' => false, 'allow_redirects' => false, 'verify' => !Craft::$app->getConfig()->getGeneral()->devMode]);
        $built = [];
        $failed = [];
        $total = count($urls);
        $done = 0;

        $requests = function() use ($urls) {
            foreach ($urls as $url) {
                // A one-off query string makes CDNs pass the request on, rather than answer with their old copy.
                $warmUrl = $url . (str_contains($url, '?') ? '&' : '?') . Cacher::WARM_PARAM . '=' . bin2hex(random_bytes(4));
                yield $url => new PsrRequest('GET', $warmUrl, ['X-Speedy-Warm' => $this->secret(), 'User-Agent' => 'SpeedyCache/1.0 (warming)']);
            }
        };
        $pool = new Pool($client, $requests(), [
            'concurrency' => max(1, $plugin->getSettings()->warmConcurrency),
            'fulfilled' => function(ResponseInterface $response, string $url) use (&$built, &$failed, &$done, $total, $progress) {
                $state = $response->getHeaderLine(Cacher::HEADER);
                if ($state === 'WARM' || $state === 'MISS') {
                    $built[] = $url;
                } else {
                    $failed[] = $url;
                    Craft::info("Couldn’t build $url: status {$response->getStatusCode()}" . ($state !== '' ? ", $state" : ''), 'speedy');
                }
                $done++;
                if ($progress) {
                    $progress($done, $total);
                }
            },
            'rejected' => function($reason, string $url) use (&$failed, &$done, $total, $progress) {
                $failed[] = $url;
                Craft::warning("Couldn’t request $url: " . ($reason instanceof Throwable ? $reason->getMessage() : 'no response'), 'speedy');
                $done++;
                if ($progress) {
                    $progress($done, $total);
                }
            },
        ]);
        $pool->promise()->wait();

        if ($rebuild) {
            // A page that can’t be rebuilt (now a 404, now private…) mustn’t stay cached.
            foreach ($failed as $url) {
                $parts = parse_url($url);
                $key = $plugin->storage->key($parts['host'] ?? '', $parts['path'] ?? '');
                if ($key) {
                    $plugin->storage->delete($key);
                }
            }
            if ($failed) {
                $plugin->log->add(Craft::t('speedy', 'Couldn’t rebuild, so removed'), count($failed), $failed);
            }
            $plugin->cdn->purgeUrls(array_merge($built, $failed));
        }

        return ['built' => count($built), 'failed' => count($failed)];
    }

    /**
     * The pages to warm after everything is cleared: from the sitemap, or the list in the config file.
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
        $urls = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            if (!$site->hasUrls || !$site->getBaseUrl()) {
                continue;
            }
            $urls = array_merge($urls, $this->readSitemap(rtrim($site->getBaseUrl(), '/') . '/' . ltrim(Plugin::getInstance()->getSettings()->sitemap, '/')));
        }

        return array_values(array_unique($urls));
    }

    /**
     * @return string[]
     */
    private function readSitemap(string $url, int $depth = 0): array
    {
        try {
            $body = (string)Craft::createGuzzleClient(['timeout' => 20, 'verify' => !Craft::$app->getConfig()->getGeneral()->devMode])->get($url)->getBody();
        } catch (Throwable $e) {
            Craft::warning("Couldn’t read the sitemap $url: " . $e->getMessage(), 'speedy');
            return [];
        }
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
                $urls = array_merge($urls, $this->readSitemap((string)$sitemap->loc, $depth + 1));
            }
        }
        foreach ($xml->url ?? [] as $entry) {
            $urls[] = (string)$entry->loc;
        }

        return $urls;
    }
}
