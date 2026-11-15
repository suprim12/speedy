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
 *   php craft speedy/clear --tag=weather
 *   php craft speedy/clear --rebuild
 */
class ClearController extends Controller
{
    public $defaultAction = 'index';

    /** @var string|null One URL to clear. */
    public ?string $url = null;

    /** @var string|null Pages tagged with `craft.speedy.tag()`, such as `weather`, or several separated by commas. */
    public ?string $tag = null;

    /** @var bool Rebuild every page in the background instead, keeping the old copies until the new ones are ready. */
    public bool $rebuild = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['url', 'tag', 'rebuild']);
    }

    public function actionIndex(): int
    {
        $clearer = Plugin::getInstance()->clearer;
        if ($this->url) {
            $count = $clearer->clearUrl($this->url);
            $this->stdout($count ? "Cleared {$this->url}.\n" : "{$this->url} wasn’t cached.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }
        if ($this->tag) {
            $count = $clearer->clearCustomTags(explode(',', $this->tag), "Cleared tag {$this->tag}, from the command line");
            $this->stdout("Cleared $count pages tagged {$this->tag}.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }
        if ($this->rebuild) {
            $count = $clearer->rebuildAll('Rebuilding everything, from the command line');
            $this->stdout("Rebuilding $count pages in the background. Visitors get the old copies until then.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }
        $clearer->clearAll('Cleared from the command line');
        $this->stdout("Cleared every cached page.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
