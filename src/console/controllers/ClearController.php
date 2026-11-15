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
 * Clears cached pages.
 *
 *   php craft speedy/clear
 *   php craft speedy/clear --url=https://example.com/news
 */
class ClearController extends Controller
{
    public $defaultAction = 'index';

    /** @var string|null One URL to clear. */
    public ?string $url = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['url']);
    }

    public function actionIndex(): int
    {
        $clearer = Plugin::getInstance()->clearer;
        if ($this->url) {
            $count = $clearer->clearUrl($this->url);
            $this->stdout($count ? "Cleared {$this->url}.\n" : "{$this->url} wasn’t cached.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }
        $clearer->clearAll('Cleared from the command line');
        $this->stdout("Cleared every cached page.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
