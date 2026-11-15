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
use suprim\speedy\Plugin;
use yii\console\ExitCode;

/**
 * Prints the web server rules that serve cached pages without PHP.
 *
 *   php craft speedy/server-rules
 *   php craft speedy/server-rules --apache
 */
class ServerRulesController extends Controller
{
    public $defaultAction = 'index';

    /** @var bool Print Apache rules instead of Nginx. */
    public bool $apache = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['apache']);
    }

    public function actionIndex(): int
    {
        $rules = Plugin::getInstance()->serverRules;
        $this->stdout(($this->apache ? $rules->apache() : $rules->nginx()) . "\n");

        return ExitCode::OK;
    }
}
