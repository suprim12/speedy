<?php
/**
 * Speedy Cache plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\speedy\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\Json;
use suprim\speedy\Plugin;
use suprim\speedy\services\BlitzImport;
use yii\console\ExitCode;

/**
 * Reads another page cache’s settings and templates and shows what each becomes in Speedy, for switching over.
 *
 *   php craft speedy/migrate           show the settings and template changes
 *   php craft speedy/migrate --apply   also save the settings to Speedy
 */
class MigrateController extends Controller
{
    public $defaultAction = 'index';

    /** @var bool Save the settings to Speedy, as well as showing them. */
    public bool $apply = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['apply']);
    }

    public function actionIndex(): int
    {
        $import = new BlitzImport();
        $blitz = $import->readSettings();
        if (!$blitz) {
            $this->stdout("No Blitz settings found, in the project config or config/blitz.php.\n", Console::FG_YELLOW);
        }
        ['settings' => $settings, 'notes' => $notes] = $import->map($blitz);

        if ($settings) {
            $this->stdout("Speedy settings:\n\n", Console::BOLD);
            foreach ($settings as $name => $value) {
                $shown = in_array($name, ['cloudflareToken', 'apiKey', 'warmPassword'], true) && is_string($value) && !str_starts_with($value, '$')
                    ? '(hidden)'
                    : Json::encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $this->stdout('  ' . str_pad($name, 22) . $shown . "\n");
            }
            $this->stdout("\n");
        }
        if ($notes) {
            $this->stdout("To check:\n\n", Console::BOLD);
            foreach ($notes as $note) {
                $this->stdout("  - $note\n");
            }
            $this->stdout("\n");
        }

        $calls = $import->scanTemplates();
        if ($calls) {
            $this->stdout("Templates:\n\n", Console::BOLD);
            foreach ($calls as $call) {
                $this->stdout("  {$call['file']}:{$call['line']}  {$call['call']} → {$call['instead']}\n");
            }
            $this->stdout("\n");
        }

        $this->stdout("Then:\n\n", Console::BOLD);
        $this->stdout("  - Disable Blitz, and remove its rules from your server config or .htaccess.\n");
        $this->stdout("  - Add Speedy’s rules: php craft speedy/server-rules\n");
        $this->stdout("  - Warm the cache: php craft speedy/warm\n\n");

        if (!$this->apply) {
            if ($settings) {
                $this->stdout("Run again with --apply to save these settings to Speedy.\n", Console::FG_GREEN);
            }
            return ExitCode::OK;
        }

        $plugin = Plugin::getInstance();
        if (!$settings) {
            $this->stdout("Nothing to save.\n");
            return ExitCode::OK;
        }
        $current = $plugin->getSettings()->toArray();
        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, array_merge($current, $settings))) {
            $this->stderr('Couldn’t save the settings: ' . Json::encode($plugin->getSettings()->getErrors()) . "\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout("Saved to Speedy’s settings. Settings in config/speedy.php still take priority.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
