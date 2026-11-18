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
use craft\helpers\Queue;
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

    /** @var array<string, string> The pages’ keys by URL, when known. */
    public array $keys = [];

    /** Seconds kept free at the end of the queue’s time for a job, for the pages being built to finish (each may take 30). */
    private const TIME_LEFT = 60;

    public function execute($queue): void
    {
        // The queue stops a job that runs past its time (`ttr`, 5 minutes by default) and marks it failed. On a site with
        // slow pages, the pages not started in time are built by a job of their own instead.
        $until = $this->until($queue);
        $left = [];
        Plugin::getInstance()->warmer->warm($this->urls, $this->rebuild, function(int $done, int $total) use ($queue) {
            $this->setProgress($queue, $done / max(1, $total), Craft::t('speedy', '{done} of {total}', ['done' => $done, 'total' => $total]));
        }, $this->keys, $until, $left);
        if ($left) {
            Queue::push(new self([
                'urls' => $left,
                'rebuild' => $this->rebuild,
                'keys' => array_intersect_key($this->keys, array_flip($left)),
            ]), 1025);
        }
    }

    /**
     * When to stop starting pages: a minute before the queue’s time for a job runs out, or half way on a short one.
     *
     * @param \yii\queue\Queue $queue
     */
    protected function until($queue): float
    {
        $ttr = (int)$queue->ttr;

        return microtime(true) + ($ttr >= 2 * self::TIME_LEFT ? $ttr - self::TIME_LEFT : $ttr / 2);
    }

    protected function defaultDescription(): ?string
    {
        return $this->rebuild ? Craft::t('speedy', 'Speedy Cache: rebuilding changed pages') : Craft::t('speedy', 'Speedy Cache: warming pages');
    }
}
