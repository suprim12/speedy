<?php
/**
 * Speedy Cache plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\speedy\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;

/**
 * Speedy Cache settings. The control panel shows the first few; the rest go in `config/speedy.php`.
 */
class Settings extends Model
{
    /** Whether pages are cached. Turn it off per environment, such as on dev. */
    public bool|string $enabled = true;

    /** @var string[]|string URL patterns that are never cached, such as `checkout/*`. `*` matches anything. One per line from the settings page. */
    public array|string $neverCache = [];

    /** @var string[]|string URL patterns to cache, when only some pages should be, such as `news/*`. Empty caches every page. `*` matches anything; `/` is the homepage. */
    public array|string $onlyCache = [];

    /**
     * @var string[]|string Host names pages are cached for, such as `example.com`, when a site’s base URL is `@web`
     * and so answers to any host. Empty caches any host for those sites, as before. Add a port when it isn’t 80 or
     * 443, such as `localhost:8080`.
     */
    public array|string $allowedHosts = [];

    /** Cloudflare API token with the Zone → Cache Purge permission. Environment variables are supported. */
    public string $cloudflareToken = '';

    /** Cloudflare zone ID. */
    public string $cloudflareZoneId = '';

    /**
     * @var array<string, string> Cloudflare zone IDs by domain, for sites on several domains, such as
     * `['example.com' => '$CF_ZONE_COM', 'example.de' => '$CF_ZONE_DE']`. A domain covers its subdomains, such as www.
     * Other URLs use `cloudflareZoneId`.
     */
    public array|string|null $cloudflareZones = [];

    /** CloudFront distribution ID. */
    public string $cloudfrontDistributionId = '';

    /** AWS access key for CloudFront. Leave blank to use AWS_ACCESS_KEY_ID. */
    public string $awsAccessKeyId = '';

    /** AWS secret for CloudFront. Leave blank to use AWS_SECRET_ACCESS_KEY. */
    public string $awsSecretAccessKey = '';

    /** Fastly API token with purge permission. */
    public string $fastlyToken = '';

    /** Fastly service ID. */
    public string $fastlyServiceId = '';

    /** Bunny account API key. */
    public string $bunnyApiKey = '';

    /** Bunny pull zone ID. */
    public string $bunnyPullZoneId = '';

    /** @var string[]|string Varnish servers to send PURGE and BAN requests to, such as `http://127.0.0.1:6081`. */
    public array|string $varnishUrls = [];

    /** Username for rebuild requests, when the site is behind basic authentication, such as on staging. */
    public string $warmUsername = '';

    /** Password for rebuild requests behind basic authentication. */
    public string $warmPassword = '';

    /** @var string|string[]|false Pages to warm: `'sitemap'`, a list of URLs, or false. */
    public string|array|false $warm = false;

    /** The sitemap to read when `warm` is `'sitemap'`, relative to each site. */
    public string $sitemap = 'sitemap.xml';

    /** Whether cleared pages are rebuilt in the background, so visitors never wait for them. */
    public bool $rebuildCleared = true;

    /**
     * Rebuild every cached page in the background when project config changes are applied, such as by `craft up` in a
     * deploy. Off by default, as on a large site that’s a lot of rebuilding after each deploy.
     */
    public bool $rebuildAfterDeploy = false;

    /** Count the database queries of pages Speedy rebuilds, and list the ones that run the same query again and again (N+1 queries). Visitors’ requests aren’t counted. */
    public bool $queryReport = true;

    /** How many pages are rebuilt at once. */
    public int $warmConcurrency = 2;

    public const STORAGE_FILES = 'files';
    public const STORAGE_CRAFT_CACHE = 'craftCache';

    /** Where pages are kept: `files` (fastest, and the web server can send them) or `craftCache`, Craft’s cache such as Redis, shared by several servers. */
    public string $storage = self::STORAGE_FILES;

    /** Where cached HTML is written. It must be inside the web root for the web server to serve it. */
    public string $cachePath = '@webroot/speedy';

    /** Where Speedy keeps what each page depends on, its log and CDN details. */
    public string $dataPath = '@storage/speedy';

    /** Query strings: `'skip'` doesn't cache pages with one (except tracking parameters), `'ignore'` caches the page without it. */
    public string $queryStrings = 'skip';

    /**
     * @var string[] Query parameters that make a page of their own, such as `page` or `category`: `?page=2` is cached
     * separately from the page without it. Only these, so made-up query strings can’t fill the disk. `*` matches any characters.
     */
    public array $cachedParams = [];

    /** The most query string versions of one page to keep, such as `?page=2` to `?page=100`. More are built but not saved. */
    public int $maxQueryPages = 100;

    /** @var string[] Element types whose changes never clear pages, by class name, such as `craft\elements\User`. */
    public array $ignoredElementTypes = [];

    /**
     * Clear pages straight away rather than in the queue: `'auto'` does so only while the queue isn’t running, so
     * changes still show; `true` always; `false` never. Pages cleared this way are rebuilt on their next visit.
     */
    public bool|string $clearInRequest = 'auto';

    /** Don’t clear pages when an entry, category, product or user is saved without changes, or saved disabled and was disabled already. */
    public bool $ignoreUnchangedSaves = true;

    /** @var string[] Query parameters that never stop a page being cached, and are ignored. */
    public array $ignoredParams = ['utm_*', 'gclid', 'fbclid', 'msclkid', 'mc_cid', 'mc_eid', '_ga', '_gl'];

    /** @var string[] Cookies that mean a visitor sees their own version of pages, such as a consent banner rendered on the server. Matched by part of the name. */
    public array $skipCookies = [];

    /**
     * @var string[] Cookies that never change what a page shows, as well as analytics IDs, which are built in. Pages are
     * only saved from requests without other cookies, so a made-up cookie can’t end up in everyone’s copy; visitors
     * with other cookies are still sent cached pages. Matched by the start of the name.
     */
    public array $ignoredCookies = [];

    /** Don’t cache pages for visitors who have a Commerce cart. */
    public bool $skipWhenCart = true;

    /** Refresh CSRF tokens on cached pages with a small script, so forms keep working. */
    public bool $refreshCsrf = true;

    /** How long CDNs keep pages, in seconds, when Speedy can clear them. */
    public int $cdnMaxAge = 31536000;

    /** CloudFront: jobs with more paths than this are collapsed into wildcards. */
    public int $wildcardThreshold = 30;

    /** A maximum age for every page, in seconds, for sites showing data from outside Craft. Null for none. */
    public ?int $defaultTtl = null;

    /** A key, at least 16 characters, for clearing and rebuilding over HTTP. Use an environment variable. Empty turns the API off. */
    public string $apiKey = '';

    /** Add a comment to the end of cached pages saying when they were cached. */
    public bool $cacheComment = true;

    /**
     * @return string[]
     */
    public function getNeverCache(): array
    {
        return $this->patterns($this->neverCache);
    }

    /**
     * @return string[]
     */
    public function getAllowedHosts(): array
    {
        return array_map('strtolower', $this->patterns(is_string($this->allowedHosts) ? str_replace(',', "\n", (string)App::parseEnv($this->allowedHosts)) : $this->allowedHosts));
    }

    /**
     * @return string[]
     */
    public function getOnlyCache(): array
    {
        return $this->patterns($this->onlyCache);
    }

    /**
     * @param string[]|string $value
     * @return string[]
     */
    private function patterns(array|string $value): array
    {
        $patterns = is_string($value) ? preg_split('/\R/', $value) : $value;

        return array_values(array_filter(array_map(fn($p) => trim((string)$p), $patterns ?: []), fn($p) => $p !== ''));
    }

    public function setAttributes($values, $safeOnly = true): void
    {
        // From the settings page: an empty choice means off, and “list” keeps the list from the config file.
        if (array_key_exists('warm', $values)) {
            if ($values['warm'] === '') {
                $values['warm'] = false;
            } elseif ($values['warm'] === 'list') {
                unset($values['warm']);
            }
        }
        // Parameter lists come from the settings page as text, one per line.
        foreach (['cachedParams', 'ignoredParams'] as $name) {
            if (isset($values[$name]) && is_string($values[$name])) {
                $values[$name] = $this->patterns(str_replace(',', "\n", $values[$name]));
            }
        }
        if (isset($values['rebuildCleared']) && is_string($values['rebuildCleared'])) {
            $values['rebuildCleared'] = $values['rebuildCleared'] === '1';
        }
        parent::setAttributes($values, $safeOnly);
    }

    public function getFastlyToken(): string
    {
        return (string)App::parseEnv($this->fastlyToken);
    }

    public function getFastlyServiceId(): string
    {
        return (string)App::parseEnv($this->fastlyServiceId);
    }

    public function getBunnyApiKey(): string
    {
        return (string)App::parseEnv($this->bunnyApiKey);
    }

    public function getBunnyPullZoneId(): string
    {
        return (string)App::parseEnv($this->bunnyPullZoneId);
    }

    /**
     * @return string[]
     */
    public function getVarnishUrls(): array
    {
        $urls = is_array($this->varnishUrls) ? $this->varnishUrls : preg_split('/[\r\n,]+/', $this->varnishUrls);

        return array_values(array_filter(array_map(fn($u) => trim((string)App::parseEnv((string)$u)), $urls ?: [])));
    }

    /**
     * Basic authentication for rebuild requests, or null.
     *
     * @return array{0: string, 1: string}|null
     */
    public function getWarmAuth(): ?array
    {
        $user = (string)App::parseEnv($this->warmUsername);

        return $user !== '' ? [$user, (string)App::parseEnv($this->warmPassword)] : null;
    }

    public function getApiKey(): string
    {
        return (string)App::parseEnv($this->apiKey);
    }

    public function isEnabled(): bool
    {
        // On hosts with a temporary disk, such as Craft Cloud, saved pages would vanish or differ between servers.
        if (App::isEphemeral() && $this->storage !== self::STORAGE_CRAFT_CACHE) {
            return false;
        }

        return (bool)App::parseBooleanEnv($this->enabled);
    }

    public function getCloudflareToken(): string
    {
        return (string)App::parseEnv($this->cloudflareToken);
    }

    public function getCloudflareZoneId(): string
    {
        return (string)App::parseEnv($this->cloudflareZoneId);
    }

    /**
     * The domains given their own Cloudflare zone in `cloudflareZones`.
     *
     * @return string[]
     */
    public function getCloudflareZoneDomains(): array
    {
        return array_values(array_filter(array_keys($this->getCloudflareZones()), fn($d) => $d !== '*'));
    }

    /**
     * Every Cloudflare zone ID, by domain, with `*` for `cloudflareZoneId`.
     *
     * @return array<string, string>
     */
    public function getCloudflareZones(): array
    {
        $zones = [];
        $value = $this->cloudflareZones;
        // From an environment variable: JSON, or `example.com=zone1,example.de=zone2`.
        if (is_string($value)) {
            $value = trim((string)App::parseEnv($value));
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                $pairs = [];
                foreach (preg_split('/[\s,]+/', $value) ?: [] as $pair) {
                    if (str_contains($pair, '=')) {
                        [$domain, $zone] = explode('=', $pair, 2);
                        $pairs[$domain] = $zone;
                    }
                }
                $value = $pairs;
            }
        }
        foreach ((array)$value as $domain => $zone) {
            $zone = (string)App::parseEnv((string)$zone);
            $domain = strtolower(trim((string)$domain, ". \t"));
            if ($zone !== '' && $domain !== '') {
                $zones[$domain] = $zone;
            }
        }
        if ($this->getCloudflareZoneId() !== '') {
            $zones['*'] = $this->getCloudflareZoneId();
        }

        return $zones;
    }

    public function getCloudfrontDistributionId(): string
    {
        return (string)App::parseEnv($this->cloudfrontDistributionId);
    }

    public function getAwsAccessKeyId(): string
    {
        return (string)(App::parseEnv($this->awsAccessKeyId) ?: App::env('AWS_ACCESS_KEY_ID'));
    }

    public function getAwsSecretAccessKey(): string
    {
        return (string)(App::parseEnv($this->awsSecretAccessKey) ?: App::env('AWS_SECRET_ACCESS_KEY'));
    }

    public function getAwsSessionToken(): ?string
    {
        return App::env('AWS_SESSION_TOKEN') ?: null;
    }

    protected function defineRules(): array
    {
        return [
            [['queryStrings'], 'in', 'range' => ['skip', 'ignore']],
            [['storage'], 'in', 'range' => [self::STORAGE_FILES, self::STORAGE_CRAFT_CACHE]],
            [['warmConcurrency'], 'integer', 'min' => 1, 'max' => 10],
            [['maxQueryPages'], 'integer', 'min' => 1],
            [['wildcardThreshold'], 'integer', 'min' => 1],
            [['cdnMaxAge'], 'integer', 'min' => 0],
            [['apiKey'], function(string $attribute) {
                $key = $this->getApiKey();
                if ($key !== '' && strlen($key) < 16) {
                    $this->addError($attribute, Craft::t('speedy', 'Use at least 16 characters.'));
                }
            }],
        ];
    }
}
