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
use GuzzleHttp\ClientInterface;
use suprim\speedy\Plugin;
use Throwable;

/**
 * “Why isn’t this page cached?”: opens a page as a logged-out visitor would and says, in words, what Speedy did and
 * what to change.
 */
class PageCheck extends Component
{
    /**
     * @return array{url: string, cached: bool, answer: string, why: ?string, fix: ?string, notes: string[], visits: string[]}
     */
    public function check(string $url, ?ClientInterface $client = null): array
    {
        $url = $this->absolute(trim($url));
        $result = ['url' => $url, 'cached' => false, 'answer' => '', 'why' => null, 'fix' => null, 'notes' => [], 'visits' => []];
        $parts = parse_url($url);
        if (empty($parts['host']) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || !Plugin::getInstance()->cacher->isKnownSiteHost((string)$parts['host'], $parts['port'] ?? null)) {
            // Only the sites’ own pages, so this can’t be used to send requests elsewhere.
            $result['answer'] = Craft::t('speedy', 'That isn’t one of this site’s addresses.');
            $result['fix'] = Plugin::getInstance()->cacher->certainHosts() === null
                ? Craft::t('speedy', 'The site’s base URL is `@web`, which answers to any domain, so pages are checked only on domains listed in `allowedHosts` in `config/speedy.php`.')
                : Craft::t('speedy', 'Enter a page of the site, such as {example}.', ['example' => '`' . $this->absolute('/') . '`']);

            return $result;
        }

        $client ??= $this->client();
        $visits = [];
        $first = $this->visit($client, $url);
        $visits[] = $first;
        $second = null;
        if ($first['error'] === null && ($first['speedy'] === 'MISS' || $first['speedy'] === 'WARM')) {
            $second = $this->visit($client, $url);
            $visits[] = $second;
        }
        foreach ($visits as $i => $visit) {
            $result['visits'][] = ($i === 0 ? Craft::t('speedy', 'First visit') : Craft::t('speedy', 'Second visit')) . ': ' . ($visit['error'] ?? trim(($visit['speedy'] !== '' ? "X-Speedy: {$visit['speedy']}, " : '') . Craft::t('speedy', 'status {status}, {ms} ms', ['status' => $visit['status'], 'ms' => $visit['ms']])));
        }

        $last = $second ?? $first;
        if ($first['error'] !== null) {
            $result['answer'] = Craft::t('speedy', 'The page couldn’t be opened: {error}', ['error' => $first['error']]);
            $result['fix'] = Craft::t('speedy', 'Check that the site can reach its own address, such as through a firewall or basic authentication (`warmUsername` and `warmPassword`).');
        } elseif (in_array($last['speedy'], ['HIT', 'STATIC', 'STALE'], true)) {
            $this->cached($result, $last['speedy'], $second !== null);
        } elseif ($last['speedy'] === '') {
            $result['answer'] = Craft::t('speedy', 'Speedy didn’t handle this address.');
            $result['why'] = Craft::t('speedy', 'The response has no `X-Speedy` header, so the request didn’t reach Craft with Speedy turned on: it may be a file, a redirect made by the web server, or another site.');
        } elseif (str_starts_with($last['speedy'], 'SKIP ')) {
            $this->skipped($result, substr($last['speedy'], 5), $last, $url);
        } else {
            $result['answer'] = Craft::t('speedy', 'The page was built twice without being saved.');
            $result['why'] = Craft::t('speedy', 'Each visit built the page, but it wasn’t kept. Pages changing while they’re built, or a full disk, can do this.');
            $result['fix'] = Craft::t('speedy', 'Check again in a minute, and see the problems listed at the top of this page.');
        }

        if (!Craft::$app->getUser()->getIsGuest()) {
            $result['notes'][] = Craft::t('speedy', 'You’re logged in, so you always get fresh pages (`X-Speedy: SKIP logged in`). A private window shows what visitors get.');
        }

        return $result;
    }

    /**
     * @param array{url: string, cached: bool, answer: string, why: ?string, fix: ?string, notes: string[], visits: string[]} $result
     */
    private function cached(array &$result, string $speedy, bool $justNow): void
    {
        $plugin = Plugin::getInstance();
        $result['cached'] = true;
        $result['answer'] = $justNow ? Craft::t('speedy', 'Cached: it was built on the first visit and sent from the cache on the second.') : Craft::t('speedy', 'Cached.');
        $key = $plugin->storage->keyForUrl($result['url']);
        $meta = $key ? $plugin->storage->meta($key) : null;
        if ($meta) {
            $result['notes'][] = Craft::t('speedy', 'Built {ago} ago{expires, select, none{} other{, and expires {expires}}}.', [
                'ago' => Craft::$app->getFormatter()->asDuration(max(1, time() - (int)$meta['created'])),
                'expires' => $meta['expires'] ? Craft::$app->getFormatter()->asDatetime($meta['expires'], 'short') : 'none',
            ]);
        }
        $result['notes'][] = match ($speedy) {
            'STATIC' => Craft::t('speedy', 'Sent by the web server, without PHP.'),
            'STALE' => Craft::t('speedy', 'Sent from the cache while a new copy is built.'),
            default => Craft::t('speedy', 'Sent by PHP from the cache. Add the [server rules](#setup) to send it without PHP, which is faster.'),
        };
    }

    /**
     * @param array{url: string, cached: bool, answer: string, why: ?string, fix: ?string, notes: string[], visits: string[]} $result
     * @param array{status: int, speedy: string, location: string, cookies: string[], vary: string, ms: int, error: ?string} $visit
     */
    private function skipped(array &$result, string $reason, array $visit, string $url): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $result['answer'] = Craft::t('speedy', 'Not cached: {reason}.', ['reason' => $reason]);
        $settingsUrl = 'settings/plugins/speedy';
        [$why, $fix] = match (true) {
            $reason === 'query string' => [
                Craft::t('speedy', 'The address has a query string with {params}. Pages with a query string are only cached for the parameters you list, so made-up ones can’t fill the disk.', ['params' => $this->codeList($this->unlistedParams($url))]),
                Craft::t('speedy', 'If it changes what the page shows, such as a tab, add it to **Cached parameters**; if not, add it to **Ignored parameters**. Both are in [Settings → Query strings]({url}).', ['url' => \craft\helpers\UrlHelper::cpUrl($settingsUrl) . '#settings-query-strings']),
            ],
            $reason === 'too many query strings' => [
                Craft::t('speedy', 'This page already has {max} query string versions cached, the most `maxQueryPages` allows.', ['max' => $settings->maxQueryPages]),
                Craft::t('speedy', 'Raise `maxQueryPages` in `config/speedy.php` if each version is a real page.'),
            ],
            $reason === 'never cache' => [
                Craft::t('speedy', 'It matches {pattern} in **Never cache**.', ['pattern' => $this->codeList(array_filter([$this->neverCachePattern($url)]))]),
                Craft::t('speedy', 'Remove it from **Never cache** in [Settings]({url}) if the page is the same for everyone.', ['url' => \craft\helpers\UrlHelper::cpUrl($settingsUrl)]),
            ],
            $reason === 'not in only cache' => [
                Craft::t('speedy', '**Only cache** is set, and this page isn’t in it.'),
                Craft::t('speedy', 'Add it to **Only cache** in [Settings]({url}).', ['url' => \craft\helpers\UrlHelper::cpUrl($settingsUrl)]),
            ],
            $reason === 'template' => [
                Craft::t('speedy', 'Its template calls `craft.speedy.skip()`.'),
                Craft::t('speedy', 'Remove the call if the page is the same for everyone.'),
            ],
            str_starts_with($reason, 'status 3') => [
                Craft::t('speedy', 'It redirects{to, select, none{} other{ to {to}}}. Only pages that load are cached; the page it redirects to can be.', ['to' => $visit['location'] !== '' ? '`' . $visit['location'] . '`' : 'none']),
                null,
            ],
            $reason === 'status 404' || $reason === 'status 410' => [
                Craft::t('speedy', 'The page doesn’t exist. Only pages that load are cached.'),
                null,
            ],
            str_starts_with($reason, 'status ') => [
                Craft::t('speedy', 'The page sends an error ({status}). Only pages that load are cached.', ['status' => substr($reason, 7)]),
                Craft::t('speedy', 'See `storage/logs` for the error.'),
            ],
            $reason === 'sets a cookie' => [
                Craft::t('speedy', 'The page sets {cookies}, so each visitor gets their own copy.', ['cookies' => $visit['cookies'] ? $this->codeList($visit['cookies']) : Craft::t('speedy', 'a cookie')]),
                Craft::t('speedy', 'Find what sets it, such as a plugin or a {tag} tag. Cookies a visitor chooses, such as a consent banner’s, are best set by JavaScript.', ['tag' => '`{% header %}`']),
            ],
            $reason === 'session' => [
                Craft::t('speedy', 'The page writes to the visitor’s session, such as a flash message or a form’s state, so each visitor gets their own copy.'),
                Craft::t('speedy', 'Find what writes to the session on this page, such as a plugin.'),
            ],
            $reason === 'private' => [
                Craft::t('speedy', 'Something on the page marks it as private (`Cache-Control: private` or `no-store`), such as a plugin or a {tag} tag.', ['tag' => '`{% header %}`']),
                Craft::t('speedy', 'Find what sends it if the page is the same for everyone.'),
            ],
            $reason === 'vary' => [
                Craft::t('speedy', 'The page sends {header}, so it can differ between visitors.', ['header' => '`Vary: ' . ($visit['vary'] !== '' ? $visit['vary'] : '…') . '`']),
                Craft::t('speedy', 'Find what sends it if the page is the same for everyone.'),
            ],
            $reason === 'disabled' => [
                Craft::t('speedy', 'Caching is turned off.'),
                Craft::t('speedy', 'Turn on **Cache pages** in [Settings]({url}).', ['url' => \craft\helpers\UrlHelper::cpUrl($settingsUrl)]),
            ],
            $reason === 'url case' => [
                Craft::t('speedy', 'The address has capital letters where its page’s URI doesn’t, or the other way round. Only the URI’s own spelling is cached, so made-up spellings can’t fill the cache.'),
                Craft::t('speedy', 'Link to the page with its URI’s spelling.'),
            ],
            $reason === 'rewritten URL' => [
                Craft::t('speedy', 'The site’s `index.php` changes this address before Craft opens it, other than taking part of it off, so the address alone might not decide what the page shows.'),
                null,
            ],
            $reason === 'unknown host' => [
                Craft::t('speedy', 'The address isn’t one of the sites’ base URLs.'),
                Craft::t('speedy', 'Add the domain to `allowedHosts` in `config/speedy.php`.'),
            ],
            $reason === 'not html' => [Craft::t('speedy', 'It isn’t an HTML page. Speedy caches pages, not files or feeds.'), null],
            $reason === 'too large' => [Craft::t('speedy', 'The page is over 5 MB.'), null],
            $reason === 'changed while building' => [Craft::t('speedy', 'Its content changed while it was being built, so this copy could have been out of date.'), Craft::t('speedy', 'Check again.')],
            in_array($reason, ['cache full', 'disk full', 'cache folder'], true) => [Craft::t('speedy', 'New pages aren’t being saved: see the problems listed at the top of this page.'), null],
            default => [null, null],
        };
        $result['why'] = $why;
        $result['fix'] = $fix;
    }

    /**
     * @return array{status: int, speedy: string, location: string, cookies: string[], vary: string, ms: int, error: ?string}
     */
    private function visit(ClientInterface $client, string $url): array
    {
        $start = microtime(true);
        try {
            $response = $client->request('GET', $url, ['headers' => ['Accept' => 'text/html']]);
        } catch (Throwable $e) {
            return ['status' => 0, 'speedy' => '', 'location' => '', 'cookies' => [], 'vary' => '', 'ms' => 0, 'error' => $e->getMessage()];
        }
        $cookies = [];
        foreach ($response->getHeader('Set-Cookie') as $cookie) {
            $cookies[] = trim(explode('=', $cookie, 2)[0]);
        }

        return [
            'status' => $response->getStatusCode(),
            'speedy' => $response->getHeaderLine('X-Speedy'),
            'location' => $response->getHeaderLine('Location'),
            'cookies' => array_values(array_unique(array_filter($cookies))),
            'vary' => $response->getHeaderLine('Vary'),
            'ms' => (int)round((microtime(true) - $start) * 1000),
            'error' => null,
        ];
    }

    private function client(): ClientInterface
    {
        return Craft::createGuzzleClient(array_filter([
            'timeout' => 30,
            'http_errors' => false,
            'allow_redirects' => false,
            'cookies' => false,
            'verify' => !Craft::$app->getConfig()->getGeneral()->devMode,
            // For sites behind basic authentication, such as staging.
            'auth' => Plugin::getInstance()->getSettings()->getWarmAuth(),
        ], fn($v) => $v !== null));
    }

    /**
     * A path such as `/news` on the primary site, or the address as given.
     */
    private function absolute(string $url): string
    {
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            $base = (string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl();
            $origin = (string)parse_url($base, PHP_URL_SCHEME) . '://' . (string)parse_url($base, PHP_URL_HOST) . (parse_url($base, PHP_URL_PORT) ? ':' . parse_url($base, PHP_URL_PORT) : '');

            return $origin . $url;
        }

        return $url;
    }

    /**
     * The query parameters that keep the page out of the cache: not tracking, not listed.
     *
     * @return string[]
     */
    private function unlistedParams(string $url): array
    {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $params);
        $settings = Plugin::getInstance()->getSettings();

        return array_values(array_filter(array_map('strval', array_keys($params)), fn($p) => Cacher::matchingPattern($p, $settings->ignoredParams) === null && Cacher::matchingPattern($p, $settings->cachedParams) === null));
    }

    private function neverCachePattern(string $url): ?string
    {
        $path = (string)parse_url($url, PHP_URL_PATH);
        $never = Plugin::getInstance()->getSettings()->getNeverCache();
        $pattern = Cacher::matchingPattern($path, $never);
        if ($pattern === null) {
            // Patterns also match the path within a site in a folder, such as /de/.
            foreach (Craft::$app->getSites()->getAllSites() as $site) {
                $base = trim((string)parse_url((string)$site->getBaseUrl(), PHP_URL_PATH), '/');
                if ($base !== '' && str_starts_with(trim($path, '/') . '/', "$base/")) {
                    $pattern = Cacher::matchingPattern(substr(trim($path, '/'), strlen($base)), $never);
                }
            }
        }

        return $pattern;
    }

    /**
     * @param string[] $items
     */
    private function codeList(array $items): string
    {
        return $items ? implode(', ', array_map(fn($i) => "`$i`", $items)) : '…';
    }
}
