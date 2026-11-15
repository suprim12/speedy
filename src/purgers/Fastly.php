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
 * Fastly, with an API token that has purge permission for the service.
 */
class Fastly extends BasePurger
{
    private const API = 'https://api.fastly.com';

    public static function handle(): string
    {
        return 'fastly';
    }

    public static function displayName(): string
    {
        return 'Fastly';
    }

    public function isConfigured(): bool
    {
        return $this->settings()->getFastlyToken() !== '' && $this->settings()->getFastlyServiceId() !== '';
    }

    public function detect(Request $request): bool
    {
        return $request->getHeaders()->has('Fastly-FF') || $request->getHeaders()->has('Fastly-Client-IP');
    }

    public function purgeUrls(array $urls): string
    {
        foreach ($urls as $url) {
            // Fastly purges a single URL by its address without the scheme.
            $this->check($this->client()->post(self::API . '/purge/' . preg_replace('#^https?://#', '', $url), ['headers' => $this->headers()]));
        }

        return $this->cleared(count($urls));
    }

    public function purgeAll(): string
    {
        $this->check($this->client()->post(self::API . '/service/' . rawurlencode($this->settings()->getFastlyServiceId()) . '/purge_all', ['headers' => $this->headers()]));

        return Craft::t('speedy', 'Cleared everything');
    }

    public function test(): string
    {
        $this->check($this->client()->get(self::API . '/service/' . rawurlencode($this->settings()->getFastlyServiceId()), ['headers' => $this->headers()]));

        return Craft::t('speedy', 'The Fastly token works for this service.');
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['Fastly-Key' => $this->settings()->getFastlyToken(), 'Accept' => 'application/json'];
    }
}
