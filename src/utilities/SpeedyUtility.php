<?php
/**
 * Speedy Cache plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\speedy\utilities;

use Craft;
use craft\base\Utility;
use suprim\speedy\Plugin;

/**
 * Utilities → Speedy Cache: status, stats, actions and recent clears.
 */
class SpeedyUtility extends Utility
{
    public static function displayName(): string
    {
        return Craft::t('speedy', 'Speedy Cache');
    }

    public static function id(): string
    {
        return 'speedy';
    }

    public static function icon(): ?string
    {
        return Craft::getAlias('@suprim/speedy/icon-mask.svg');
    }

    public static function contentHtml(): string
    {
        $plugin = Plugin::getInstance();

        $request = Craft::$app->getRequest();
        $search = (string)$request->getQueryParam('speedySearch', '');
        $page = max(1, (int)$request->getQueryParam('speedyPage', 1));
        $perPage = 50;
        $list = $plugin->insights->pages($search, ($page - 1) * $perPage, $perPage);

        return Craft::$app->getView()->renderTemplate('speedy/_utility', [
            'checks' => $plugin->insights->checks(),
            'list' => $list,
            'search' => $search,
            'page' => $page,
            'perPage' => $perPage,
            'settings' => $plugin->getSettings(),
            'stats' => $plugin->storage->cachedStats(),
            'detected' => $plugin->cdn->detected(),
            'configured' => $plugin->cdn->configured(),
            'cdnLast' => $plugin->cdn->lastResults(),
            'cdnNames' => $plugin->cdn->names(),
            'report' => $plugin->insights->report(),
            'cloudfront' => $plugin->cdn->cloudfrontUsage(),
            'log' => $plugin->log->recent(30),
            'queries' => $plugin->queries->report(),
            'slowest' => $plugin->queries->slowest(),
            'broad' => $plugin->queries->broad(),
            'state' => $plugin->storage->readData('state'),
            'webPath' => $plugin->serverRules->webPath(),
            'nginx' => $plugin->serverRules->nginx(),
            'apache' => $plugin->serverRules->apache(),
        ]);
    }
}
