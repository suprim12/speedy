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
use craft\helpers\App;
use craft\helpers\FileHelper;
use craft\helpers\Html;
use craft\helpers\Json;
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
use suprim\speedy\services\CacheStore;
use suprim\speedy\services\Cdn;
use suprim\speedy\services\Clearer;
use suprim\speedy\services\Dynamic;
use suprim\speedy\services\Insights;
use suprim\speedy\services\Log;
use suprim\speedy\services\PageCheck;
use suprim\speedy\services\Queries;
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
 * @property-read Queries $queries
 * @property-read PageCheck $pageCheck
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    /** Clearing cached pages from element indexes, for people without Speedy’s utility. */
    public const PERMISSION_CLEAR = 'speedy-clearpages';

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
                'pageCheck' => PageCheck::class,
                'dynamic' => Dynamic::class,
                'queries' => Queries::class,
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
        // Craft's garbage collection runs by itself now and then, and from cron: clear out old trash then too.
        Event::on(\craft\services\Gc::class, \craft\services\Gc::EVENT_RUN, function() {
            // Craft runs it at the end of a visitor’s request now and then: don’t make them wait for the deleting.
            if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
                if ($this->storage->hasOldTrash()) {
                    \craft\helpers\Queue::push(new \suprim\speedy\jobs\DeleteTrashJob());
                }
                return;
            }
            $this->storage->deleteOldTrash();
        });
        $this->registerControlPanel();

        $this->registerLog();

        Craft::$app->onInit(function() {
            if (!Craft::$app instanceof WebApplication) {
                return;
            }
            $this->refreshFormieTokens();
            // Control panel requests too, so logging in marks the visitor before they see the site.
            // After the response is prepared (Craft renders templates then, so a form step or flash message has just
            // been stored) and before its headers go out.
            Event::on(\yii\web\Response::class, \yii\web\Response::EVENT_AFTER_PREPARE, fn() => $this->cacher->markState());
            $request = Craft::$app->getRequest();
            if ($request->getIsSiteRequest()) {
                $this->cacher->handleRequest();
            } elseif ($request->getIsCpRequest() && !$request->getIsAjax() && $request->getIsGet()) {
                // A daily rebuild set in config/speedy.php is scheduled the first time someone opens the control panel.
                $this->clearer->scheduleDailyRebuild();
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

    public function getSettingsResponse(): mixed
    {
        return $this->tabbedSettingsResponse(false);
    }

    public function getReadOnlySettingsResponse(): mixed
    {
        return $this->tabbedSettingsResponse(true);
    }

    /**
     * Formie 4 refreshes its captcha and submission tokens on cached pages when it knows pages are cached, which it
     * only works out by itself for one other cache plugin. Tell it, for this request, while caching is on.
     * Formie 3 gets a script on cached pages instead ({@see Cacher}).
     */
    private function refreshFormieTokens(): void
    {
        $settings = $this->getSettings();
        if (!$settings->isEnabled() || !$settings->refreshCsrf) {
            return;
        }
        $formie = Craft::$app->getPlugins()->getPlugin('formie');
        if ($formie === null || version_compare($formie->getVersion(), '4.0.0', '<')) {
            return;
        }
        $formieSettings = $formie->getSettings();
        if ($formieSettings !== null && property_exists($formieSettings, 'staticCacheRefreshOnLoad')) {
            $formieSettings->staticCacheRefreshOnLoad = true;
        }
    }

    /**
     * Craft’s own plugin settings page, with tabs.
     */
    private function tabbedSettingsResponse(bool $readOnly): mixed
    {
        $html = Craft::$app->getView()->namespaceInputs(
            fn() => $readOnly ? (string)Html::disableInputs(fn() => $this->settingsHtml()) : (string)$this->settingsHtml(),
            'settings',
        );
        /** @var \craft\web\Controller $controller */
        $controller = Craft::$app->controller;

        return $controller->renderTemplate('speedy/_settings/page', [
            'plugin' => $this,
            'settingsHtml' => $html,
            'readOnly' => $readOnly,
        ]);
    }

    /**
     * The options that are only set in config/speedy.php, with their values, for the Config file tab.
     *
     * @return array<int, array{name: string, value: string, info: string}>
     */
    private function configRows(): array
    {
        $s = $this->getSettings();
        $show = fn($v) => match (true) {
            is_bool($v) => $v ? 'true' : 'false',
            $v === null => 'null',
            is_array($v) => $v ? implode(', ', $v) : '[]',
            default => (string)$v,
        };
        $rows = [
            ['ignoredCookies', $s->ignoredCookies, Craft::t('speedy', 'Cookies that never change pages. Requests with other cookies are sent cached pages but don’t save them.')],
            ['maxQueryPages', $s->maxQueryPages, Craft::t('speedy', 'The most query string versions of one page to keep.')],
            ['maxCacheSize', $s->maxCacheSize, Craft::t('speedy', 'The most disk space cached pages may use, in MB. Empty for no limit.')],
            ['broadRebuildDelay', $s->broadRebuildDelay, Craft::t('speedy', 'Seconds a page cleared by any change waits before it’s rebuilt, so an import rebuilds it once.')],
            ['ignoredElementTypes', $s->ignoredElementTypes, Craft::t('speedy', 'Element types whose changes never clear pages.')],
            ['clearInRequest', $s->clearInRequest, Craft::t('speedy', 'Clear pages without the queue: auto does so only while the queue isn’t running.')],
            ['ignoreUnchangedSaves', $s->ignoreUnchangedSaves, Craft::t('speedy', 'Saves that change nothing don’t clear pages.')],
            ['skipCookies', $s->skipCookies, Craft::t('speedy', 'Cookies that mean a visitor sees their own pages, such as a consent banner.')],
            ['skipWhenCart', $s->skipWhenCart, Craft::t('speedy', 'Don’t cache for visitors with a Commerce cart.')],
            ['refreshCsrf', $s->refreshCsrf, Craft::t('speedy', 'Refresh CSRF tokens on cached pages, so forms work.')],
            ['defaultTtl', $s->defaultTtl, Craft::t('speedy', 'A maximum age for every page, in seconds.')],
            ['rebuildAfterDeploy', $s->rebuildAfterDeploy, Craft::t('speedy', 'Rebuild every cached page when a deploy applies project config changes.')],
            ['queryReport', $s->queryReport, Craft::t('speedy', 'Count the queries of rebuilt pages and list repeated ones (N+1 queries).')],
            ['warmConcurrency', $s->warmConcurrency, Craft::t('speedy', 'How many pages are rebuilt at once.')],
            ['warmBusyLoad', $s->warmBusyLoad, Craft::t('speedy', 'Rebuilding waits while the load for each CPU core is at least this. 0 never waits.')],
            ['sitemap', $s->sitemap, Craft::t('speedy', 'The sitemap read when rebuilding everything.')],
            ['cacheComment', $s->cacheComment, Craft::t('speedy', 'Add a “Cached by Speedy Cache” comment to cached pages.')],
            ['cloudflareZones', $s->getCloudflareZoneDomains(), Craft::t('speedy', 'Cloudflare zone IDs by domain, for sites on several domains.')],
            ['cdnMaxAge', $s->cdnMaxAge, Craft::t('speedy', 'How long a CDN keeps pages, in seconds.')],
            ['wildcardThreshold', $s->wildcardThreshold, Craft::t('speedy', 'CloudFront: more paths than this are grouped into wildcards.')],
            ['cachePath', $s->cachePath, Craft::t('speedy', 'Where pages are saved.')],
            ['dataPath', $s->dataPath, Craft::t('speedy', 'Where Speedy keeps tags, the log and CDN details.')],
        ];

        return array_map(fn($r) => ['name' => $r[0], 'value' => $show($r[1]), 'info' => $r[2]], $rows);
    }

    public function afterSaveSettings(): void
    {
        parent::afterSaveSettings();
        $this->checkInstantLinks();
        $this->checkNeverCache();
        $this->clearer->scheduleDailyRebuild();
    }

    /**
     * Pages already cached for entry types or categories just added to Never cache are removed, as the web server
     * would keep sending them: after the settings are saved in the control panel, or a deploy applies them.
     */
    public function checkNeverCache(): void
    {
        $settings = $this->savedSettings();
        $now = md5(Json::encode([array_values($settings->neverCacheEntryTypes), array_values($settings->neverCacheCategories)]));
        $state = $this->storage->readData('state');
        if (($state['neverCache'] ?? md5(Json::encode([[], []]))) === $now) {
            return;
        }
        $state['neverCache'] = $now;
        $this->storage->writeData('state', $state);
        $this->clearer->clearNeverCached($settings);
    }

    /**
     * The settings as saved now, with config/speedy.php over them. While a deploy applies project config, the
     * plugin still has the settings it started with.
     */
    private function savedSettings(): Settings
    {
        $file = Craft::$app->getConfig()->getConfigFromFile('speedy');
        if (!is_array($file)) {
            return $this->getSettings();
        }
        $settings = new Settings();
        $settings->setAttributes(array_merge((array)Craft::$app->getProjectConfig()->get('plugins.speedy.settings'), $file), false);

        return $settings;
    }

    /**
     * Instant links are part of each cached page, so turning them on or off rebuilds the pages: after the settings
     * are saved in the control panel, or a deploy applies them. Compared with what the cached pages were built with,
     * so it doesn’t matter how the change arrived.
     */
    public function checkInstantLinks(): void
    {
        $file = Craft::$app->getConfig()->getConfigFromFile('speedy');
        $value = match (true) {
            is_array($file) && array_key_exists('instantLinks', $file) => $file['instantLinks'],
            // A config file that returns a closure or config object: read through the settings.
            !is_array($file) => $this->getSettings()->instantLinks,
            default => Craft::$app->getProjectConfig()->get('plugins.speedy.settings.instantLinks'),
        };
        if ($this->instantLinksChanged(filter_var($value, FILTER_VALIDATE_BOOLEAN))) {
            $on = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            $this->clearer->rebuildAllLater($on ? Craft::t('speedy', 'Instant links turned on') : Craft::t('speedy', 'Instant links turned off'));
        }
    }

    /**
     * Whether the cached pages were built with instant links the other way, noting that they’re now as given.
     */
    public function instantLinksChanged(bool $on): bool
    {
        $state = $this->storage->readData('state');
        // Pages cached before there were instant links don’t have them.
        $was = (bool)($state['instantLinks'] ?? false);
        if ($was === $on) {
            return false;
        }
        $state['instantLinks'] = $on;
        $this->storage->writeData('state', $state);

        return true;
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('speedy/_settings', [
            'settings' => $this->getSettings(),
            'overrides' => is_array($overrides = Craft::$app->getConfig()->getConfigFromFile('speedy')) ? array_keys($overrides) : [],
            'detected' => $this->cdn->detected(),
            'configured' => $this->cdn->configured(),
            'cdnLast' => $this->cdn->lastResults(),
            'cdnNames' => $this->cdn->names(),
            'stats' => $this->storage->cachedStats(),
            'configRows' => $this->configRows(),
            'ephemeral' => App::isEphemeral(),
            'cacheInfo' => CacheStore::describe(),
            'skippedParams' => $this->insights->skippedParams(),
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
        // Saves that change nothing, such as clicking Save twice, don’t clear pages.
        Event::on(Elements::class, Elements::EVENT_BEFORE_SAVE_ELEMENT, fn(\craft\events\ElementEvent $event) => $this->clearer->beforeSave($event->element));
        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, fn(\craft\events\ElementEvent $event) => $this->clearer->afterSave($event->element));
        Event::on(Elements::class, Elements::EVENT_BEFORE_BULK_OP, fn() => $this->clearer->beginBulk());
        Event::on(Elements::class, Elements::EVENT_AFTER_BULK_OP, fn() => $this->clearer->endBulk());

        // SEOmatic’s settings, such as its tracking scripts and default meta tags, are on every page. Saving them
        // rebuilds every page in the background. Its per-element events are left alone: Craft’s cache tags cover those.
        if (class_exists(\nystudio107\seomatic\services\MetaContainers::class)) {
            Event::on(\nystudio107\seomatic\services\MetaContainers::class, 'invalidateContainerCaches', function($event) {
                if ($event->uri === null && $event->siteId === null && $event->sourceId === null) {
                    $this->clearer->rebuildAllLater(Craft::t('speedy', 'SEOmatic settings changed'));
                }
            });
        }

        // A deploy that applies project config changes, such as a new field in a template’s section, rebuilds pages.
        Event::on(\craft\services\ProjectConfig::class, \craft\services\ProjectConfig::EVENT_AFTER_APPLY_CHANGES, function() {
            $this->checkInstantLinks();
            $this->checkNeverCache();
            if ($this->getSettings()->rebuildAfterDeploy) {
                $this->clearer->rebuildAllLater(Craft::t('speedy', 'Project config changes applied'));
            }
        });

        // Commerce changes stock without telling Craft that pages showing it changed: on orders, control panel
        // edits and transfers between locations.
        if (class_exists(\craft\commerce\services\Inventory::class)) {
            foreach ([\craft\commerce\services\Inventory::EVENT_AFTER_EXECUTE_UPDATE_INVENTORY_LEVEL => 'updateInventoryLevel', \craft\commerce\services\Inventory::EVENT_AFTER_EXECUTE_INVENTORY_MOVEMENT => 'inventoryMovement'] as $name => $property) {
                Event::on(\craft\commerce\services\Inventory::class, $name, function($event) use ($property) {
                    $this->clearer->stockChanged($event->$property?->getInventoryItem()?->getPurchasable());
                });
            }
        }

        // Commerce works out catalog prices in a queue job, such as after a pricing rule is saved, without saying the
        // prices changed.
        if (class_exists(\craft\commerce\queue\jobs\CatalogPricing::class)) {
            Event::on(Queue::class, Queue::EVENT_BEFORE_EXEC, function(\yii\queue\ExecEvent $event) {
                if ($event->job instanceof \craft\commerce\queue\jobs\CatalogPricing) {
                    $this->clearer->beforePriceJob();
                }
            });
            Event::on(Queue::class, Queue::EVENT_AFTER_EXEC, function(\yii\queue\ExecEvent $event) {
                if ($event->job instanceof \craft\commerce\queue\jobs\CatalogPricing) {
                    $this->clearer->afterPriceJob($event->job->purchasableIds, $event->job->catalogPricingRuleIds);
                    $this->clearer->flush();
                }
            });
            // Deleting a pricing rule deletes its prices too, without that job.
            $ruleIds = [];
            $rule = 'craft\\commerce\\records\\CatalogPricingRule';
            Event::on($rule, \yii\db\BaseActiveRecord::EVENT_BEFORE_DELETE, function(\yii\base\ModelEvent $event) use (&$ruleIds) {
                $ruleIds[(int)$event->sender->id] = $this->clearer->purchasablesPricedBy((int)$event->sender->id);
            });
            Event::on($rule, \yii\db\BaseActiveRecord::EVENT_AFTER_DELETE, function(\yii\base\Event $event) use (&$ruleIds) {
                $ids = $ruleIds[(int)$event->sender->id] ?? [];
                unset($ruleIds[(int)$event->sender->id]);
                if ($ids) {
                    $this->clearer->pricesRecalculated($ids);
                }
            });
        }

        // A replaced image keeps its address, so the CDN would keep sending the old one.
        Event::on(\craft\services\Assets::class, \craft\services\Assets::EVENT_BEFORE_REPLACE_ASSET, fn(\craft\events\ReplaceAssetEvent $event) => $this->cdn->rememberAssetUrls($event->asset));
        Event::on(\craft\services\Assets::class, \craft\services\Assets::EVENT_AFTER_REPLACE_ASSET, fn(\craft\events\ReplaceAssetEvent $event) => $this->cdn->purgeAssetUrls($event->asset));

        // One clear job per request or queue job, not one per saved element.
        // New pages saved in the request or job are built once it ends, after its clearing.
        Craft::$app->on(Application::EVENT_AFTER_REQUEST, function() {
            $this->clearer->flush();
            $this->clearer->buildNoted();
        });
        Event::on(Queue::class, Queue::EVENT_AFTER_EXEC, function() {
            $this->clearer->flush();
            $this->clearer->buildNoted();
        });
        Event::on(Queue::class, Queue::EVENT_AFTER_ERROR, function() {
            $this->clearer->flush();
            $this->clearer->buildNoted();
        });
    }

    private function registerControlPanel(): void
    {
        Event::on(Utilities::class, Utilities::EVENT_REGISTER_UTILITIES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = SpeedyUtility::class;
        });

        Event::on(\craft\services\Dashboard::class, \craft\services\Dashboard::EVENT_REGISTER_WIDGET_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = \suprim\speedy\widgets\SpeedyWidget::class;
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

                // Clears the pages that show it, for people allowed to; handled by the script below.
                if (($status['state'] === 'cached' || $status['pages']) && ClearPages::allowed()) {
                    $lines[] = Html::button(Craft::t('speedy', 'Clear its cached pages'), [
                        'type' => 'button',
                        'class' => ['btn', 'small', 'speedy-clear-element'],
                        'data' => ['element-id' => $element->getCanonicalId() ?? $element->id],
                    ]);
                }

                return Html::tag('div', implode('', array_map(fn($line) => Html::tag('div', $line), $lines)));
            };
        });

        // A “Page cache” column that element indexes can show, such as the entries table: cached or not, and when.
        $indexed = [\craft\elements\Entry::class, \craft\elements\Category::class];
        if (class_exists(\craft\commerce\elements\Product::class)) {
            $indexed[] = \craft\commerce\elements\Product::class;
        }
        foreach ($indexed as $class) {
            Event::on($class, Element::EVENT_REGISTER_TABLE_ATTRIBUTES, function(\craft\events\RegisterElementTableAttributesEvent $event) {
                $event->tableAttributes['speedyCache'] = ['label' => Craft::t('speedy', 'Page cache')];
            });
            Event::on($class, Element::EVENT_DEFINE_ATTRIBUTE_HTML, function(\craft\events\DefineAttributeHtmlEvent $event) {
                if ($event->attribute !== 'speedyCache') {
                    return;
                }
                /** @var ElementInterface $element */
                $element = $event->sender;
                $status = $this->insights->forElement($element, false);
                if ($status === null) {
                    $event->html = '';
                    return;
                }
                $colour = match ($status['state']) {
                    'cached' => 'green',
                    'uncached' => 'orange',
                    default => 'disabled',
                };
                $event->html = Html::tag('span', '', ['class' => ['status', $colour], 'role' => 'img', 'aria-hidden' => 'true'])
                    . Html::encode($status['label'])
                    . ($status['detail'] ? Html::tag('span', ' · ' . Html::encode($status['detail']), ['class' => 'light']) : '');
            });
        }

        // The Clear button in element details, which can open in a slideout, so the script listens on the page.
        Event::on(\craft\web\View::class, \craft\web\View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE, function(\craft\events\TemplateEvent $event) {
            if ($event->templateMode !== \craft\web\View::TEMPLATE_MODE_CP || Craft::$app->getRequest()->getIsConsoleRequest() || Craft::$app->getUser()->getIsGuest() || !ClearPages::allowed()) {
                return;
            }
            Craft::$app->getView()->registerJs(<<<'JS'
document.addEventListener('click', function (e) {
    var button = e.target.closest('.speedy-clear-element');
    if (!button || button.disabled) { return; }
    button.disabled = true;
    Craft.sendActionRequest('POST', 'speedy/elements/clear', { data: { elementId: button.dataset.elementId } })
        .then(function (response) { Craft.cp.displaySuccess(response.data.message); })
        .catch(function (error) { Craft.cp.displayError(error.response && error.response.data && error.response.data.message); })
        .finally(function () { button.disabled = false; });
});
JS);
        });

        // A control panel alert while the queue isn’t running, as pages then wouldn’t clear without Speedy stepping in.
        Event::on(\craft\helpers\Cp::class, \craft\helpers\Cp::EVENT_REGISTER_ALERTS, function(\craft\events\RegisterCpAlertsEvent $event) {
            if (!Craft::$app->getUser()->checkPermission('utility:speedy')) {
                return;
            }
            $stalled = $this->insights->queueStuckFor();
            if ($stalled) {
                $event->alerts[] = Craft::t('speedy', 'The queue hasn’t run for {minutes} minutes. Speedy Cache is clearing pages without it until it does. <a href="{url}">Details</a>', [
                    'minutes' => intdiv($stalled, 60),
                    'url' => \craft\helpers\UrlHelper::cpUrl('utilities/speedy'),
                ]);
            }
        });

        Event::on(\craft\services\UserPermissions::class, \craft\services\UserPermissions::EVENT_REGISTER_PERMISSIONS, function(\craft\events\RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => Craft::t('speedy', 'Speedy Cache'),
                'permissions' => [
                    self::PERMISSION_CLEAR => ['label' => Craft::t('speedy', 'Clear cached pages from entry, category and product indexes')],
                ],
            ];
        });

        // “Clear cached pages” in the actions menu of element indexes, for elements with pages.
        $types = [\craft\elements\Entry::class, \craft\elements\Category::class];
        if (class_exists(\craft\commerce\elements\Product::class)) {
            $types[] = \craft\commerce\elements\Product::class;
        }
        foreach ($types as $type) {
            Event::on($type, Element::EVENT_REGISTER_ACTIONS, function(RegisterElementActionsEvent $event) {
                if (ClearPages::allowed()) {
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
