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

        return Craft::$app->getView()->renderTemplate('speedy/_utility', [
            'settings' => $plugin->getSettings(),
            'stats' => $plugin->storage->stats(),
            'detected' => $plugin->cdn->detected(),
            'configured' => $plugin->cdn->configured(),
            'cdnLast' => $plugin->cdn->lastResults(),
            'cloudfront' => $plugin->cdn->cloudfrontUsage(),
            'log' => $plugin->log->recent(30),
            'state' => $plugin->storage->readData('state'),
            'webPath' => $plugin->serverRules->webPath(),
            'nginx' => $plugin->serverRules->nginx(),
            'apache' => $plugin->serverRules->apache(),
        ]);
    }
}
