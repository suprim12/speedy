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
 * Builds pages in the background.
 */
class WarmJob extends BaseJob
{
    /** @var string[] */
    public array $urls = [];
    public bool $rebuild = false;

    public function execute($queue): void
    {
        Plugin::getInstance()->warmer->warm($this->urls, $this->rebuild, function(int $done, int $total) use ($queue) {
            $this->setProgress($queue, $done / max(1, $total), Craft::t('speedy', '{done} of {total}', ['done' => $done, 'total' => $total]));
        });
    }

    protected function defaultDescription(): ?string
    {
        return $this->rebuild ? Craft::t('speedy', 'Speedy Cache: rebuilding changed pages') : Craft::t('speedy', 'Speedy Cache: warming pages');
    }
}
