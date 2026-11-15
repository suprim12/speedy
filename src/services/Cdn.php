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
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\Json;
use craft\web\Request;
use suprim\speedy\Plugin;
use suprim\speedy\purgers\Bunny;
use suprim\speedy\purgers\Fastly;
use suprim\speedy\purgers\PurgerInterface;
use suprim\speedy\purgers\Varnish;
use suprim\speedy\services\cdn\SigV4;
use Throwable;

/**
 * Detects a CDN in front of the site and clears it: Cloudflare and CloudFront.
 */
class Cdn extends Component
{
    public const CLOUDFLARE = 'cloudflare';
    public const CLOUDFRONT = 'cloudfront';

    /** Register more CDNs: add classes that implement PurgerInterface to `$event->types`. */
    public const EVENT_REGISTER_PURGERS = 'registerPurgers';

    /** @var array<string, PurgerInterface>|null */
    private ?array $_purgers = null;

    /**
     * CDNs other than Cloudflare and CloudFront, built in or registered by other plugins, by handle.
     *
     * @return array<string, PurgerInterface>
     */
    public function purgers(): array
    {
        if ($this->_purgers === null) {
            $event = new RegisterComponentTypesEvent(['types' => [Fastly::class, Bunny::class, Varnish::class]]);
            $this->trigger(self::EVENT_REGISTER_PURGERS, $event);
            $this->_purgers = [];
            foreach ($event->types as $class) {
                if (is_subclass_of($class, PurgerInterface::class)) {
                    $this->_purgers[$class::handle()] = new $class();
                }
            }
        }

        return $this->_purgers;
    }

    /**
     * Every CDN’s name, by handle.
     *
     * @return array<string, string>
     */
    public function names(): array
    {
        return [self::CLOUDFLARE => 'Cloudflare', self::CLOUDFRONT => 'CloudFront'] + array_map(fn(PurgerInterface $p) => $p::displayName(), $this->purgers());
    }

    /**
     * Notes which CDN the request came through, from its headers. Checked at most once an hour.
     */
    public function detect(Request $request): void
    {
        $cache = Craft::$app->getCache();
        if ($cache->get('speedy:cdn-checked')) {
            return;
        }
        $headers = $request->getHeaders();
        $found = match (true) {
            $headers->has('CF-Ray') => self::CLOUDFLARE,
            $headers->has('X-Amz-Cf-Id') || str_contains(strtolower((string)$headers->get('Via')), 'cloudfront') => self::CLOUDFRONT,
            default => null,
        };
        foreach ($found === null ? $this->purgers() : [] as $handle => $purger) {
            if ($purger->detect($request)) {
                $found = $handle;
                break;
            }
        }
        $storage = Plugin::getInstance()->storage;
        $state = $storage->readData('cdn');
        if (($state['detected'] ?? null) !== $found) {
            $state['detected'] = $found;
            $state['detectedAt'] = time();
            $storage->writeData('cdn', $state);
        }
        $cache->set('speedy:cdn-checked', true, 3600);
    }

    /**
     * The CDN the site was last seen behind, or null.
     */
    public function detected(): ?string
    {
        return Plugin::getInstance()->storage->readData('cdn')['detected'] ?? null;
    }

    /**
     * The CDNs Speedy can clear, because their details are set.
     *
     * @return string[]
     */
    public function configured(): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $cdns = [];
        if ($settings->getCloudflareToken() && $settings->getCloudflareZoneId()) {
            $cdns[] = self::CLOUDFLARE;
        }
        if ($settings->getCloudfrontDistributionId() && $settings->getAwsAccessKeyId() && $settings->getAwsSecretAccessKey()) {
            $cdns[] = self::CLOUDFRONT;
        }
        foreach ($this->purgers() as $handle => $purger) {
            if ($purger->isConfigured()) {
                $cdns[] = $handle;
            }
        }

        return $cdns;
    }

    /**
     * The Cache-Control header for cached pages. CDNs only keep pages that Speedy can clear from them.
     */
    public function cacheControl(): string
    {
        if ($this->configured()) {
            return 'public, s-maxage=' . Plugin::getInstance()->getSettings()->cdnMaxAge . ', max-age=0';
        }

        return 'no-cache, s-maxage=0';
    }

    /**
     * Clears URLs from every configured CDN.
     *
     * @param string[] $urls
     */
    public function purgeUrls(array $urls): void
    {
        $urls = array_values(array_unique(array_filter($urls)));
        if (!$urls) {
            return;
        }
        foreach ($this->configured() as $cdn) {
            try {
                $result = match ($cdn) {
                    self::CLOUDFLARE => $this->cloudflarePurge($urls),
                    self::CLOUDFRONT => $this->cloudfrontInvalidate($this->cloudfrontPaths($urls)),
                    default => $this->purgers()[$cdn]->purgeUrls($urls),
                };
                $this->record($cdn, $result);
            } catch (Throwable $e) {
                $this->record($cdn, 'failed: ' . $e->getMessage(), true);
                Craft::warning("Couldn’t clear $cdn: " . $e->getMessage(), 'speedy');
            }
        }
    }

    /**
     * Clears everything from every configured CDN.
     */
    public function purgeAll(): void
    {
        foreach ($this->configured() as $cdn) {
            try {
                $result = match ($cdn) {
                    self::CLOUDFLARE => $this->cloudflarePurge(null),
                    self::CLOUDFRONT => $this->cloudfrontInvalidate(['/*']),
                    default => $this->purgers()[$cdn]->purgeAll(),
                };
                $this->record($cdn, $result);
            } catch (Throwable $e) {
                $this->record($cdn, 'failed: ' . $e->getMessage(), true);
                Craft::warning("Couldn’t clear $cdn: " . $e->getMessage(), 'speedy');
            }
        }
    }

    /**
     * Checks the CDN details by making a harmless request.
     */
    public function test(string $cdn): string
    {
        $settings = Plugin::getInstance()->getSettings();
        if (isset($this->purgers()[$cdn])) {
            return $this->purgers()[$cdn]->test();
        }
        if ($cdn === self::CLOUDFLARE) {
            $response = Craft::createGuzzleClient(['http_errors' => false, 'timeout' => 15])->get('https://api.cloudflare.com/client/v4/user/tokens/verify', [
                'headers' => ['Authorization' => 'Bearer ' . $settings->getCloudflareToken()],
            ]);
            $data = Json::decodeIfJson((string)$response->getBody());
            if (!($data['success'] ?? false)) {
                throw new \RuntimeException($data['errors'][0]['message'] ?? 'The token wasn’t accepted.');
            }
            return Craft::t('speedy', 'The Cloudflare token works.');
        }

        // CloudFront has no free “check” call that an invalidation-only key may use, so clear one path that no page has.
        $this->cloudfrontInvalidate(['/speedy-cache-check']);

        return Craft::t('speedy', 'CloudFront accepted a test invalidation (1 path).');
    }

    /**
     * CloudFront paths used this month and when the month started.
     *
     * @return array{month: string, paths: int}
     */
    public function cloudfrontUsage(): array
    {
        $usage = Plugin::getInstance()->storage->readData('cdn')['cloudfrontUsage'] ?? [];
        $month = date('Y-m');

        return ($usage['month'] ?? null) === $month ? ['month' => $month, 'paths' => (int)$usage['paths']] : ['month' => $month, 'paths' => 0];
    }

    /**
     * The last result for each CDN.
     *
     * @return array<string, array{time: int, result: string, error: bool}>
     */
    public function lastResults(): array
    {
        return Plugin::getInstance()->storage->readData('cdn')['last'] ?? [];
    }

    /**
     * CloudFront paths for URLs, collapsed into wildcards when there are many, to stay in the free tier.
     *
     * @param string[] $urls
     * @return string[]
     */
    public function cloudfrontPaths(array $urls): array
    {
        $paths = [];
        foreach ($urls as $url) {
            $paths[parse_url($url, PHP_URL_PATH) ?: '/'] = true;
        }
        $paths = array_keys($paths);
        $threshold = Plugin::getInstance()->getSettings()->wildcardThreshold;
        if (count($paths) <= $threshold) {
            return $paths;
        }

        // Group by first folder: /news/a, /news/b → /news*
        $groups = [];
        foreach ($paths as $path) {
            $first = explode('/', trim($path, '/'))[0];
            $groups[$first === '' ? '/' : "/$first*"][] = $path;
        }
        $collapsed = [];
        foreach ($groups as $wildcard => $members) {
            if ($wildcard === '/') {
                $collapsed[] = '/';
            } else {
                $collapsed[] = count($members) > 1 ? $wildcard : $members[0];
            }
        }

        return count($collapsed) <= $threshold ? array_values(array_unique($collapsed)) : ['/*'];
    }

    /**
     * @param string[]|null $urls Null clears everything.
     */
    private function cloudflarePurge(?array $urls): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $client = Craft::createGuzzleClient(['timeout' => 20]);
        $endpoint = 'https://api.cloudflare.com/client/v4/zones/' . rawurlencode($settings->getCloudflareZoneId()) . '/purge_cache';
        $headers = ['Authorization' => 'Bearer ' . $settings->getCloudflareToken(), 'Content-Type' => 'application/json'];

        if ($urls === null) {
            $client->post($endpoint, ['headers' => $headers, 'body' => Json::encode(['purge_everything' => true])]);
            return Craft::t('speedy', 'Cleared everything');
        }
        // Cloudflare takes up to 30 URLs per request.
        foreach (array_chunk($urls, 30) as $chunk) {
            $client->post($endpoint, ['headers' => $headers, 'body' => Json::encode(['files' => $chunk])]);
        }

        return Craft::t('speedy', 'Cleared {count, plural, =1{# URL} other{# URLs}}', ['count' => count($urls)]);
    }

    /**
     * @param string[] $paths
     */
    private function cloudfrontInvalidate(array $paths): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $distribution = $settings->getCloudfrontDistributionId();
        $url = 'https://cloudfront.amazonaws.com/2020-05-31/distribution/' . rawurlencode($distribution) . '/invalidation';

        $items = implode('', array_map(fn($p) => '<Path>' . htmlspecialchars($this->encodePath($p), ENT_XML1) . '</Path>', $paths));
        $body = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<InvalidationBatch xmlns="http://cloudfront.amazonaws.com/doc/2020-05-31/">'
            . '<Paths><Quantity>' . count($paths) . '</Quantity><Items>' . $items . '</Items></Paths>'
            . '<CallerReference>speedy-' . bin2hex(random_bytes(8)) . '</CallerReference>'
            . '</InvalidationBatch>';

        $signer = new SigV4($settings->getAwsAccessKeyId(), $settings->getAwsSecretAccessKey(), 'us-east-1', 'cloudfront', $settings->getAwsSessionToken());
        $headers = $signer->sign('POST', $url, ['Content-Type' => 'application/xml'], $body);
        $response = Craft::createGuzzleClient(['timeout' => 20, 'http_errors' => false])->post($url, ['headers' => $headers, 'body' => $body]);
        if ($response->getStatusCode() >= 300) {
            $xml = @simplexml_load_string((string)$response->getBody());
            throw new \RuntimeException((string)($xml->Error->Message ?? $xml->Message ?? 'HTTP ' . $response->getStatusCode()));
        }

        $this->countPaths(count($paths));

        return Craft::t('speedy', 'Invalidated {count, plural, =1{# path} other{# paths}}', ['count' => count($paths)]);
    }

    /**
     * CloudFront paths must be URL-encoded, except for the wildcard.
     */
    private function encodePath(string $path): string
    {
        return implode('/', array_map(fn($s) => $s === '*' ? $s : str_replace('%2A', '*', rawurlencode(rawurldecode($s))), explode('/', $path)));
    }

    private function countPaths(int $count): void
    {
        $storage = Plugin::getInstance()->storage;
        $state = $storage->readData('cdn');
        $usage = $this->cloudfrontUsage();
        $usage['paths'] += $count;
        $state['cloudfrontUsage'] = $usage;
        $storage->writeData('cdn', $state);
    }

    private function record(string $cdn, string $result, bool $error = false): void
    {
        $storage = Plugin::getInstance()->storage;
        $state = $storage->readData('cdn');
        $state['last'][$cdn] = ['time' => time(), 'result' => $result, 'error' => $error];
        $storage->writeData('cdn', $state);
        if ($error) {
            Plugin::getInstance()->log->add(($this->names()[$cdn] ?? $cdn) . ': ' . $result, 0, [], $result);
        }
    }
}
