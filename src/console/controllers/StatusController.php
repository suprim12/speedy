<?php
/**
 * Speedy Cache plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\speedy\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use suprim\speedy\Plugin;
use yii\console\ExitCode;

/**
 * Shows what’s cached, the CDN and recent clears.
 *
 *   php craft speedy/status
 */
class StatusController extends Controller
{
    public $defaultAction = 'index';

    public function actionIndex(): int
    {
        $plugin = Plugin::getInstance();
        $stats = $plugin->storage->stats();
        $this->stdout('Caching:      ' . ($plugin->getSettings()->isEnabled() ? 'on' : 'off') . "\n");
        $this->stdout("Pages cached: {$stats['pages']} (" . round($stats['bytes'] / 1048576, 1) . " MB)\n");
        $this->stdout('CDN detected: ' . ($plugin->cdn->detected() ?? 'none') . "\n");
        $this->stdout('CDN clearing: ' . (implode(', ', $plugin->cdn->configured()) ?: 'not set up') . "\n");
        $usage = $plugin->cdn->cloudfrontUsage();
        $this->stdout("CloudFront:   {$usage['paths']} paths this month\n");
        $this->stdout("\nRecent clears:\n");
        foreach ($plugin->log->recent(10) as $entry) {
            $this->stdout('  ' . date('Y-m-d H:i', $entry['time']) . "  {$entry['count']} pages  {$entry['reason']}\n", Console::FG_GREY);
        }

        return ExitCode::OK;
    }
}
