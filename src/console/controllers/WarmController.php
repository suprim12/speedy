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
 * Builds the pages in the warm list: the sitemap, or the list in config/speedy.php.
 *
 *   php craft speedy/warm
 */
class WarmController extends Controller
{
    public $defaultAction = 'index';

    public function actionIndex(): int
    {
        $warmer = Plugin::getInstance()->warmer;
        $urls = $warmer->warmList();
        if (!$urls) {
            $this->stderr("Nothing to warm. Set 'warm' in config/speedy.php to 'sitemap' or a list of URLs.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }
        $this->stdout('Warming ' . count($urls) . " pages…\n");
        $result = $warmer->warm($urls, false, function(int $done, int $total) {
            Console::updateProgress($done, $total);
        });
        Console::endProgress();
        $this->stdout("Built {$result['built']}, failed {$result['failed']}.\n", $result['failed'] ? Console::FG_YELLOW : Console::FG_GREEN);

        return ExitCode::OK;
    }
}
