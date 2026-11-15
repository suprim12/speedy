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

/**
 * Bunny CDN, with the account API key and the pull zone ID.
 */
class Bunny extends BasePurger
{
    private const API = 'https://api.bunny.net';

    public static function handle(): string
    {
        return 'bunny';
    }

    public static function displayName(): string
    {
        return 'Bunny';
    }

    public function isConfigured(): bool
    {
        return $this->settings()->getBunnyApiKey() !== '' && $this->settings()->getBunnyPullZoneId() !== '';
    }

    public function purgeUrls(array $urls): string
    {
        foreach ($urls as $url) {
            $this->check($this->client()->post(self::API . '/purge?url=' . rawurlencode($url), ['headers' => $this->headers()]));
        }

        return $this->cleared(count($urls));
    }

    public function purgeAll(): string
    {
        $this->check($this->client()->post(self::API . '/pullzone/' . rawurlencode($this->settings()->getBunnyPullZoneId()) . '/purgeCache', [
            'headers' => $this->headers() + ['Content-Type' => 'application/json'],
            'body' => '{}',
        ]));

        return Craft::t('speedy', 'Cleared everything');
    }

    public function test(): string
    {
        $this->check($this->client()->get(self::API . '/pullzone/' . rawurlencode($this->settings()->getBunnyPullZoneId()), ['headers' => $this->headers()]));

        return Craft::t('speedy', 'The Bunny API key works for this pull zone.');
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['AccessKey' => $this->settings()->getBunnyApiKey(), 'Accept' => 'application/json'];
    }
}
