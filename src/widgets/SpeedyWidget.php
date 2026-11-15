<?php
/**
 * Speedy Cache plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\speedy\widgets;

use Craft;
use craft\base\Widget;
use suprim\speedy\Plugin;

/**
 * A dashboard widget: pages cached, recent clears and the CDN, at a glance.
 */
class SpeedyWidget extends Widget
{
    /** How many recent clears to show. */
    public int $limit = 5;

    public static function displayName(): string
    {
        return Craft::t('speedy', 'Speedy Cache');
    }

    public static function icon(): ?string
    {
        return Craft::getAlias('@suprim/speedy/icon-mask.svg');
    }

    public static function isSelectable(): bool
    {
        return Craft::$app->getUser()->checkPermission('utility:speedy');
    }

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['limit'], 'integer', 'min' => 1, 'max' => 20],
        ]);
    }

    public function getSettingsHtml(): ?string
    {
        return \craft\helpers\Cp::textFieldHtml([
            'label' => Craft::t('speedy', 'Recent clears to show'),
            'id' => 'limit',
            'name' => 'limit',
            'type' => 'number',
            'min' => 1,
            'max' => 20,
            'size' => 3,
            'value' => $this->limit,
            'errors' => $this->getErrors('limit'),
        ]);
    }

    public function getBodyHtml(): ?string
    {
        if (!Craft::$app->getUser()->checkPermission('utility:speedy')) {
            return null;
        }
        $plugin = Plugin::getInstance();
        // Counting pages reads the cache folder, so it’s counted at most once a minute.
        $stats = Craft::$app->getCache()->getOrSet('speedy:widgetStats', fn() => $plugin->storage->stats(), 60);

        return Craft::$app->getView()->renderTemplate('speedy/_widget', [
            'settings' => $plugin->getSettings(),
            'stats' => $stats,
            'problems' => array_values(array_filter($plugin->insights->checks(), fn($c) => $c['level'] === 'error')),
            'log' => $plugin->log->recent($this->limit),
            'detected' => $plugin->cdn->detected(),
            'configured' => $plugin->cdn->configured(),
            'cdnLast' => $plugin->cdn->lastResults(),
            'cdnNames' => $plugin->cdn->names(),
        ]);
    }
}
