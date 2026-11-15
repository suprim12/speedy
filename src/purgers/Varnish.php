<?php
/**
 * Speedy Cache plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\speedy\purgers;

use Craft;
use craft\web\Request;

/**
 * Varnish, or another reverse proxy that accepts PURGE and BAN requests. The VCL must allow them; see the docs.
 */
class Varnish extends BasePurger
{
    public static function handle(): string
    {
        return 'varnish';
    }

    public static function displayName(): string
    {
        return 'Varnish';
    }

    public function isConfigured(): bool
    {
        return $this->settings()->getVarnishUrls() !== [];
    }

    public function detect(Request $request): bool
    {
        return $request->getHeaders()->has('X-Varnish');
    }

    public function purgeUrls(array $urls): string
    {
        foreach ($this->settings()->getVarnishUrls() as $server) {
            foreach ($urls as $url) {
                $parts = parse_url($url);
                $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
                $this->check($this->client()->request('PURGE', rtrim($server, '/') . $path, ['headers' => ['Host' => $parts['host'] ?? '']]));
            }
        }

        return $this->cleared(count($urls));
    }

    public function purgeAll(): string
    {
        foreach ($this->settings()->getVarnishUrls() as $server) {
            foreach ($this->hosts() as $host) {
                $this->check($this->client()->request('BAN', rtrim($server, '/') . '/', ['headers' => ['Host' => $host, 'X-Ban-Url' => '.*']]));
            }
        }

        return Craft::t('speedy', 'Cleared everything');
    }

    public function test(): string
    {
        foreach ($this->settings()->getVarnishUrls() as $server) {
            $response = $this->client()->request('PURGE', rtrim($server, '/') . '/speedy-cache-check', ['headers' => ['Host' => $this->hosts()[0] ?? 'localhost']]);
            if ($response->getStatusCode() === 405 || $response->getStatusCode() >= 500) {
                throw new \RuntimeException(Craft::t('speedy', '{server} refused PURGE (HTTP {status}). Allow it in the VCL.', ['server' => $server, 'status' => $response->getStatusCode()]));
            }
        }

        return Craft::t('speedy', 'Varnish accepts PURGE requests.');
    }

    /**
     * The sites’ hosts, for clearing everything.
     *
     * @return string[]
     */
    private function hosts(): array
    {
        $hosts = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $host = parse_url((string)$site->getBaseUrl(), PHP_URL_HOST);
            if ($host) {
                $hosts[$host] = true;
            }
        }

        return array_keys($hosts);
    }
}
