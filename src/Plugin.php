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
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\DefineMetadataEvent;
use craft\events\InvalidateElementCachesEvent;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterElementActionsEvent;
use craft\helpers\FileHelper;
use craft\helpers\Html;
use craft\log\MonologTarget;
use craft\services\Elements;
use craft\services\Utilities;
use craft\utilities\ClearCaches;
use craft\web\Application as WebApplication;
use craft\web\twig\variables\CraftVariable;
use Psr\Log\LogLevel;
use suprim\speedy\elements\actions\ClearPages;
use suprim\speedy\models\Settings;
use suprim\speedy\services\Cacher;
use suprim\speedy\services\Cdn;
use suprim\speedy\services\Clearer;
use suprim\speedy\services\Dynamic;
use suprim\speedy\services\Insights;
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
 * @property-read Insights $insights
 * @property-read Dynamic $dynamic
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
                'insights' => Insights::class,
                'dynamic' => Dynamic::class,
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

        // A “Speedy Cache” line in each element’s details, like its status and dates.
        Event::on(Element::class, Element::EVENT_DEFINE_METADATA, function(DefineMetadataEvent $event) {
            /** @var ElementInterface $element */
            $element = $event->sender;
            $event->metadata[Craft::t('speedy', 'Page cache')] = function() use ($element) {
                $status = $this->insights->forElement($element);
                if ($status === null) {
                    return false;
                }
                $colour = match ($status['state']) {
                    'cached' => 'green',
                    'uncached' => 'orange',
                    default => 'disabled',
                };
                $lines = [Html::tag('span', '', ['class' => ['status', $colour], 'role' => 'img', 'aria-hidden' => 'true']) . Html::encode($status['label'])];
                if ($status['detail']) {
                    $lines[] = Html::tag('span', Html::encode($status['detail']), ['class' => 'light smalltext']);
                }
                if ($status['pages']) {
                    $lines[] = Html::tag('span', Html::encode(Craft::t('speedy', 'Shown on {count, plural, =1{# cached page} other{# cached pages}}', ['count' => $status['pages']])), ['class' => 'light smalltext']);
                }

                return Html::tag('div', implode('', array_map(fn($line) => Html::tag('div', $line), $lines)));
            };
        });

        // “Clear cached pages” in the actions menu of element indexes, for elements with pages.
        $types = [\craft\elements\Entry::class, \craft\elements\Category::class];
        if (class_exists(\craft\commerce\elements\Product::class)) {
            $types[] = \craft\commerce\elements\Product::class;
        }
        foreach ($types as $type) {
            Event::on($type, Element::EVENT_REGISTER_ACTIONS, function(RegisterElementActionsEvent $event) {
                if (Craft::$app->getUser()->checkPermission('utility:speedy')) {
                    $event->actions[] = ClearPages::class;
                }
            });
        }

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
