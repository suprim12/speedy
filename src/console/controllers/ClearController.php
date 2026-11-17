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
 *   php craft speedy/clear --url="https://example.com/news/*"
 *   php craft speedy/clear --site=german
 *   php craft speedy/clear --tag=weather
 *   php craft speedy/clear --rebuild
 */
class ClearController extends Controller
{
    public $defaultAction = 'index';

    /** @var string|null One URL to clear, or every page matching it with `*`, such as `https://example.com/news/*`. */
    public ?string $url = null;

    /** @var string|null The handle of a site whose pages to clear. */
    public ?string $site = null;

    /** @var string|null Pages tagged with `craft.speedy.tag()`, such as `weather`, or several separated by commas. */
    public ?string $tag = null;

    /** @var bool Rebuild every page in the background instead, keeping the old copies until the new ones are ready. */
    public bool $rebuild = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['url', 'site', 'tag', 'rebuild']);
    }

    public function actionIndex(): int
    {
        $clearer = Plugin::getInstance()->clearer;
        if ($this->url) {
            if (str_contains($this->url, '*')) {
                if (\suprim\speedy\services\Clearer::patternRegex($this->url) === null) {
                    $this->stderr("Use an address such as https://example.com/news/*, or a path such as /news/*.\n", Console::FG_RED);
                    return ExitCode::USAGE;
                }
                $count = $clearer->clearMatching($this->url);
                $this->stdout("Cleared $count pages matching {$this->url}.\n", Console::FG_GREEN);
                return ExitCode::OK;
            }
            $count = $clearer->clearUrl($this->url);
            $this->stdout($count ? "Cleared {$this->url}.\n" : "{$this->url} wasn’t cached.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }
        if ($this->site) {
            $site = \Craft::$app->getSites()->getSiteByHandle($this->site);
            $count = $site ? $clearer->clearSite((int)$site->id) : null;
            if ($count === null) {
                $this->stderr("There’s no site with the handle {$this->site} and pages.\n", Console::FG_RED);
                return ExitCode::USAGE;
            }
            $this->stdout("Cleared $count pages of the {$site->getName()} site.\n", Console::FG_GREEN);
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
        if (!$clearer->clearAll('Cleared from the command line')) {
            $this->stderr("Nothing was cleared: the cache folder isn’t a folder of Speedy’s own. Set cachePath to one, such as @webroot/speedy.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout("Cleared every cached page.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
