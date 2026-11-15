<?php
/**
 * Speedy Cache plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\speedy;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\InvalidateElementCachesEvent;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\FileHelper;
use craft\log\MonologTarget;
use craft\services\Elements;
use craft\services\Utilities;
use craft\utilities\ClearCaches;
use craft\web\Application as WebApplication;
use craft\web\twig\variables\CraftVariable;
use Psr\Log\LogLevel;
use suprim\speedy\models\Settings;
use suprim\speedy\services\Cacher;
use suprim\speedy\services\Cdn;
use suprim\speedy\services\Clearer;
use suprim\speedy\services\Log;
use suprim\speedy\services\ServerRules;
use suprim\speedy\services\Storage;
use suprim\speedy\services\Warmer;
use suprim\speedy\utilities\SpeedyUtility;
use suprim\speedy\variables\SpeedyVariable;
use yii\base\Application;
use yii\base\Event;
use yii\queue\Queue;

/**
 * Speedy Cache: full-page caching that keeps the database clean and clears CloudFront and Cloudflare.
 *
 * @property-read Storage $storage
 * @property-read Cacher $cacher
 * @property-read Clearer $clearer
 * @property-read Cdn $cdn
 * @property-read Warmer $warmer
 * @property-read Log $log
 * @property-read ServerRules $serverRules
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'storage' => Storage::class,
                'cacher' => Cacher::class,
                'clearer' => Clearer::class,
                'cdn' => Cdn::class,
                'warmer' => Warmer::class,
                'log' => Log::class,
                'serverRules' => ServerRules::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            /** @var CraftVariable $variable */
            $variable = $event->sender;
            $variable->set('speedy', SpeedyVariable::class);
        });

        $this->registerClearing();
        $this->registerControlPanel();

        $this->registerLog();

        Craft::$app->onInit(function() {
            if (!Craft::$app instanceof WebApplication) {
                return;
            }
            // Control panel requests too, so logging in marks the visitor before they see the site.
            Craft::$app->on(WebApplication::EVENT_AFTER_REQUEST, fn() => $this->cacher->markState());
            if (Craft::$app->getRequest()->getIsSiteRequest()) {
                $this->cacher->handleRequest();
            }
        });
    }

    /**
     * Speedy’s warnings and errors also go to storage/logs/speedy.log, one file a day, keeping the last five.
     * Details, such as each page that couldn’t be rebuilt, are only logged in devMode.
     */
    private function registerLog(): void
    {
        $dispatcher = Craft::getLogger()->dispatcher;
        $dispatcher->targets['speedy'] = new MonologTarget([
            'name' => 'speedy',
            'categories' => ['speedy'],
            'level' => Craft::$app->getConfig()->getGeneral()->devMode ? LogLevel::INFO : LogLevel::WARNING,
            'maxFiles' => 5,
            'logContext' => false,
            'allowLineBreaks' => false,
        ]);
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('speedy/_settings', [
            'settings' => $this->getSettings(),
            'overrides' => array_keys(Craft::$app->getConfig()->getConfigFromFile('speedy')),
            'detected' => $this->cdn->detected(),
        ]);
    }

    protected function afterUninstall(): void
    {
        foreach ([$this->storage->cacheRoot(), $this->storage->dataRoot()] as $dir) {
            if (is_dir($dir)) {
                FileHelper::removeDirectory($dir);
            }
        }
    }

    private function registerClearing(): void
    {
        Event::on(Elements::class, Elements::EVENT_INVALIDATE_CACHES, function(InvalidateElementCachesEvent $event) {
            $this->clearer->onInvalidate($event);
        });
        Event::on(Elements::class, Elements::EVENT_BEFORE_BULK_OP, fn() => $this->clearer->beginBulk());
        Event::on(Elements::class, Elements::EVENT_AFTER_BULK_OP, fn() => $this->clearer->endBulk());

        // One clear job per request or queue job, not one per saved element.
        Craft::$app->on(Application::EVENT_AFTER_REQUEST, fn() => $this->clearer->flush());
        Event::on(Queue::class, Queue::EVENT_AFTER_EXEC, fn() => $this->clearer->flush());
        Event::on(Queue::class, Queue::EVENT_AFTER_ERROR, fn() => $this->clearer->flush());
    }

    private function registerControlPanel(): void
    {
        Event::on(Utilities::class, Utilities::EVENT_REGISTER_UTILITIES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = SpeedyUtility::class;
        });

        // Craft's Utilities → Caches can clear Speedy too.
        Event::on(ClearCaches::class, ClearCaches::EVENT_REGISTER_CACHE_OPTIONS, function(RegisterCacheOptionsEvent $event) {
            $event->options[] = [
                'key' => 'speedy',
                'label' => Craft::t('speedy', 'Speedy Cache pages'),
                'action' => fn() => $this->clearer->clearAll(Craft::t('speedy', 'Cleared from Utilities → Caches')),
            ];
        });
    }
}
