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
use craft\helpers\Json;
use craft\web\Request;

/**
 * KeyCDN, with an API key and the pull zone’s ID.
 */
class KeyCdn extends BasePurger
{
    private const API = 'https://api.keycdn.com';

    /** URLs sent in one request: its body can be at most 64 KB. */
    private const BATCH = 100;

    public static function handle(): string
    {
        return 'keycdn';
    }

    public static function displayName(): string
    {
        return 'KeyCDN';
    }

    public function isConfigured(): bool
    {
        return $this->settings()->getKeycdnApiKey() !== '' && $this->settings()->getKeycdnZoneId() !== '';
    }

    public function detect(Request $request): bool
    {
        // KeyCDN asks for pages with an X-Pull header, “KeyCDN” unless the zone sets its own.
        return $request->getHeaders()->has('X-Pull');
    }

    public function purgeUrls(array $urls): string
    {
        $batches = array_chunk(array_values(array_unique(array_map(fn($url) => $this->zoneUrl($url), $urls))), self::BATCH);
        foreach ($batches as $i => $batch) {
            // KeyCDN takes one purge a second.
            if ($i > 0) {
                sleep(1);
            }
            $this->check($this->client()->delete(self::API . '/zones/purgeurl/' . rawurlencode($this->settings()->getKeycdnZoneId()) . '.json', [
                'auth' => [$this->settings()->getKeycdnApiKey(), ''],
                'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                'body' => Json::encode(['urls' => $batch]),
            ]));
        }

        return $this->cleared(count($urls));
    }

    public function purgeAll(): string
    {
        $this->check($this->client()->get(self::API . '/zones/purge/' . rawurlencode($this->settings()->getKeycdnZoneId()) . '.json', [
            'auth' => [$this->settings()->getKeycdnApiKey(), ''],
            'headers' => ['Accept' => 'application/json'],
        ]));

        return Craft::t('speedy', 'Cleared everything');
    }

    public function test(): string
    {
        $this->check($this->client()->get(self::API . '/zones/' . rawurlencode($this->settings()->getKeycdnZoneId()) . '.json', [
            'auth' => [$this->settings()->getKeycdnApiKey(), ''],
            'headers' => ['Accept' => 'application/json'],
        ]));

        return Craft::t('speedy', 'The KeyCDN API key works for this zone.');
    }

    /**
     * A page’s address as KeyCDN keeps it: without the scheme, on the zone URL such as `example-1a2b.kxcdn.com` when
     * one is set, or on the page’s own domain for a zone that keeps pages by their host (Cache Key Host).
     */
    public function zoneUrl(string $url): string
    {
        $parts = parse_url($url);
        $host = $this->settings()->getKeycdnZoneUrl();
        $host = $host !== '' ? (string)preg_replace('#^https?://#', '', rtrim($host, '/')) : ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return $host . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
