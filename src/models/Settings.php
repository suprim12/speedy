<?php
/**
 * Speedy Cache plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\speedy\models;

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

    /** Cloudflare API token with the Zone → Cache Purge permission. Environment variables are supported. */
    public string $cloudflareToken = '';

    /** Cloudflare zone ID. */
    public string $cloudflareZoneId = '';

    /** CloudFront distribution ID. */
    public string $cloudfrontDistributionId = '';

    /** AWS access key for CloudFront. Leave blank to use AWS_ACCESS_KEY_ID. */
    public string $awsAccessKeyId = '';

    /** AWS secret for CloudFront. Leave blank to use AWS_SECRET_ACCESS_KEY. */
    public string $awsSecretAccessKey = '';

    /** @var string|string[]|false Pages to warm: `'sitemap'`, a list of URLs, or false. */
    public string|array|false $warm = false;

    /** The sitemap to read when `warm` is `'sitemap'`, relative to each site. */
    public string $sitemap = 'sitemap.xml';

    /** Whether cleared pages are rebuilt in the background, so visitors never wait for them. */
    public bool $rebuildCleared = true;

    /** How many pages are rebuilt at once. */
    public int $warmConcurrency = 2;

    /** Where cached HTML is written. It must be inside the web root for the web server to serve it. */
    public string $cachePath = '@webroot/speedy';

    /** Where Speedy keeps what each page depends on, its log and CDN details. */
    public string $dataPath = '@storage/speedy';

    /** Query strings: `'skip'` doesn't cache pages with one (except tracking parameters), `'ignore'` caches the page without it. */
    public string $queryStrings = 'skip';

    /** @var string[] Query parameters that never stop a page being cached, and are ignored. */
    public array $ignoredParams = ['utm_*', 'gclid', 'fbclid', 'msclkid', 'mc_cid', 'mc_eid', '_ga', '_gl'];

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

    /** Add a comment to the end of cached pages saying when they were cached. */
    public bool $cacheComment = true;

    /**
     * @return string[]
     */
    public function getNeverCache(): array
    {
        $patterns = is_string($this->neverCache) ? preg_split('/\R/', $this->neverCache) : $this->neverCache;

        return array_values(array_filter(array_map('trim', $patterns ?: []), fn($p) => $p !== ''));
    }

    public function isEnabled(): bool
    {
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
            [['warmConcurrency'], 'integer', 'min' => 1, 'max' => 10],
            [['wildcardThreshold'], 'integer', 'min' => 1],
            [['cdnMaxAge'], 'integer', 'min' => 0],
        ];
    }
}
