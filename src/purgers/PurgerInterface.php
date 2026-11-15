<?php
/**
 * Speedy Cache plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\speedy\purgers;

use craft\web\Request;

/**
 * A CDN or reverse proxy Speedy can clear. Register your own with Cdn::EVENT_REGISTER_PURGERS.
 */
interface PurgerInterface
{
    /** A short, unique handle, such as `fastly`. */
    public static function handle(): string;

    /** The name shown in the control panel, such as `Fastly`. */
    public static function displayName(): string;

    /** Whether its details are set, so Speedy may let it keep pages and clear them. */
    public function isConfigured(): bool;

    /** Whether a request came through it, judging by its headers. */
    public function detect(Request $request): bool;

    /**
     * Clears these URLs. Returns a short result, such as “Cleared 3 URLs”. Throws on failure.
     *
     * @param string[] $urls
     */
    public function purgeUrls(array $urls): string;

    /** Clears everything. Returns a short result. Throws on failure. */
    public function purgeAll(): string;

    /** Checks the details with a harmless request. Returns a short result. Throws on failure. */
    public function test(): string;
}
