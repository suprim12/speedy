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
 * Clears the pages that use some cache tags, or everything.
 */
class ClearJob extends BaseJob
{
    /** @var string[] */
    public array $tags = [];
    public bool $all = false;
    public string $reason = '';

    /** Cleared by hand, such as a big section from the utility page, so its pages are rebuilt straight away. */
    public bool $byHand = false;

    public function execute($queue): void
    {
        $clearer = Plugin::getInstance()->clearer;
        if ($this->all) {
            $clearer->clearAll($this->reason ?: Craft::t('speedy', 'All content changed'));
            return;
        }
        $clearer->clearTags($this->tags, $this->reason, !$this->byHand);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('speedy', 'Speedy Cache: clearing pages');
    }
}
