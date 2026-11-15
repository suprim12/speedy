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
 * Clears pages whose scheduled content has gone live or expired.
 */
class ExpireJob extends BaseJob
{
    public function execute($queue): void
    {
        Plugin::getInstance()->clearer->clearExpired();
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('speedy', 'Clearing pages with scheduled changes');
    }
}
