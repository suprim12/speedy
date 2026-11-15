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
 * Clears pages whose scheduled content has gone live or expired. Run it from cron every few minutes.
 *
 *   php craft speedy/expire
 */
class ExpireController extends Controller
{
    public $defaultAction = 'index';

    public function actionIndex(): int
    {
        $count = Plugin::getInstance()->clearer->clearExpired();
        $this->stdout("Cleared $count pages.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
