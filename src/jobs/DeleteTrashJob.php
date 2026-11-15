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
 * Deletes the old pages after everything is cleared.
 */
class DeleteTrashJob extends BaseJob
{
    public function execute($queue): void
    {
        Plugin::getInstance()->storage->deleteTrash();
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('speedy', 'Speedy Cache: deleting old pages');
    }
}
