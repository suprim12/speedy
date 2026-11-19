<?php

namespace suprim\speedy\tests\Http;

use Craft;
use suprim\speedy\Plugin;

/**
 * For http tests that change Speedy’s saved settings, so the site they request sees them: saves every setting with the
 * change (Craft saves only the ones it’s given), and afterwards puts back exactly what was saved before, even nothing.
 */
trait KeepsSettings
{
    private static mixed $savedSettings = null;

    /** @var array<string, mixed> */
    private static array $settingsBefore = [];

    private static bool $keepingSettings = false;

    private static function keepSettings(): void
    {
        self::$savedSettings = Craft::$app->getProjectConfig()->get('plugins.speedy.settings');
        self::$settingsBefore = Plugin::getInstance()->getSettings()->toArray();
        self::$keepingSettings = true;
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function changeSettings(array $values): void
    {
        $plugin = Plugin::getInstance();
        Craft::$app->getPlugins()->savePluginSettings($plugin, array_merge($plugin->getSettings()->toArray(), $values));
        // Craft saves project config changes at the end of a request: now, so the site sees them.
        Craft::$app->getProjectConfig()->saveModifiedConfigData();
    }

    private static function putSettingsBack(): void
    {
        if (!self::$keepingSettings) {
            return;
        }
        $config = Craft::$app->getProjectConfig();
        if (self::$savedSettings === null) {
            $config->remove('plugins.speedy.settings', 'Speedy tests: settings put back');
        } else {
            $config->set('plugins.speedy.settings', self::$savedSettings, 'Speedy tests: settings put back');
        }
        $config->saveModifiedConfigData();
        Plugin::getInstance()->getSettings()->setAttributes(self::$settingsBefore, false);
        self::$keepingSettings = false;
    }
}
