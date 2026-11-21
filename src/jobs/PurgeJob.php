<?php
/**
 * Speedy Cache plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\speedy\jobs;

use Craft;
use craft\queue\BaseJob;
use suprim\speedy\Plugin;

/**
 * Clears part of a site from Cloudflare by prefix or host, queued after the rebuilds of its pages, so Cloudflare
 * doesn’t fetch the old copies again.
 */
class PurgeJob extends BaseJob
{
    /** @var array{prefixes?: string[], hosts?: string[]} */
    public array $cloudflare = [];

    public function execute($queue): void
    {
        Plugin::getInstance()->cdn->purgeUrls([], $this->cloudflare);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('speedy', 'Speedy Cache: clearing the CDN');
    }
}
