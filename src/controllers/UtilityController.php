<?php
/**
 * Speedy Cache plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\speedy\controllers;

use Craft;
use craft\web\Controller;
use suprim\speedy\Plugin;
use Throwable;
use yii\web\Response;

/**
 * Actions on Utilities → Speedy Cache.
 */
class UtilityController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requirePermission('utility:speedy');

        return true;
    }

    public function actionClearAll(): ?Response
    {
        if (!Plugin::getInstance()->clearer->clearAll(Craft::t('speedy', 'Cleared by {user}', ['user' => (string)Craft::$app->getUser()->getIdentity()]))) {
            return $this->asFailure(Craft::t('speedy', 'Nothing was cleared: the cache folder isn’t a folder of Speedy’s own. Set `cachePath` to one, such as `@webroot/speedy`.'));
        }

        return $this->asSuccess(Craft::t('speedy', 'Every cached page was cleared.'));
    }

    public function actionRebuildAll(): ?Response
    {
        $plugin = Plugin::getInstance();
        $count = $plugin->clearer->rebuildAll(Craft::t('speedy', 'Rebuilding everything, by {user}', ['user' => (string)Craft::$app->getUser()->getIdentity()]));
        if (!$count) {
            // Nothing is cached, such as just after clearing: build the pages to warm instead, if there are any.
            $urls = $plugin->warmer->warmList();
            if (!$urls) {
                return $this->asFailure(Craft::t('speedy', 'No pages are cached yet, so there’s nothing to rebuild. Pages are cached as visitors open them, or set “warm” to “sitemap” to build them all.'));
            }
            $plugin->warmer->queue($urls, false);

            return $this->asSuccess(Craft::t('speedy', 'No pages were cached, so building {count, plural, =1{# page} other{# pages}} to warm in the background.', ['count' => count($urls)]));
        }

        return $this->asSuccess(Craft::t('speedy', 'Rebuilding {count, plural, =1{# page} other{# pages}} in the background.', ['count' => $count]));
    }

    public function actionClearUrl(): ?Response
    {
        $url = trim((string)$this->request->getRequiredBodyParam('url'));
        $count = Plugin::getInstance()->clearer->clearUrl($url);

        return $count ? $this->asSuccess(Craft::t('speedy', 'Cleared {url}.', ['url' => $url])) : $this->asFailure(Craft::t('speedy', '{url} isn’t cached.', ['url' => $url]));
    }

    /**
     * Opens a page as a logged-out visitor and shows why it is or isn’t cached, under the form.
     */
    public function actionCheckPage(): ?Response
    {
        $url = trim((string)$this->request->getRequiredBodyParam('url'));
        Craft::$app->getSession()->set('speedy:pageCheck', Plugin::getInstance()->pageCheck->check($url));

        return $this->redirectToPostedUrl();
    }

    public function actionClearSection(): ?Response
    {
        $count = Plugin::getInstance()->clearer->clearSection((int)$this->request->getRequiredBodyParam('sectionId'));
        if ($count === null) {
            return $this->asFailure(Craft::t('speedy', 'That section doesn’t exist.'));
        }

        if ($count === \suprim\speedy\services\Clearer::QUEUED) {
            return $this->asSuccess(Craft::t('speedy', 'Clearing the section’s pages in the background. See Recent clears for how many.'));
        }

        return $count ? $this->asSuccess(Craft::t('speedy', '{count, plural, =1{# page} other{# pages}} cleared.', ['count' => $count])) : $this->asFailure(Craft::t('speedy', 'No cached pages show that section.'));
    }

    public function actionWarm(): ?Response
    {
        $warmer = Plugin::getInstance()->warmer;
        $urls = $warmer->warmList();
        if (!$urls) {
            return $this->asFailure(Craft::t('speedy', 'Nothing to warm. Set “warm” in config/speedy.php to “sitemap” or a list of URLs.'));
        }
        $warmer->queue($urls, false);

        return $this->asSuccess(Craft::t('speedy', 'Warming {count} pages in the background.', ['count' => count($urls)]));
    }

    /**
     * Builds the pages of entries, categories and other elements that aren’t cached yet.
     */
    public function actionBuildMissing(): ?Response
    {
        $plugin = Plugin::getInstance();
        // Pages that couldn’t be built last time too, as they may work now.
        $groups = $plugin->insights->coverage(true)['groups'];
        $urls = array_merge(...array_column($groups, 'missing'), ...array_map(fn($g) => array_keys($g['failed']), $groups));
        if (!$urls) {
            return $this->asSuccess(Craft::t('speedy', 'Every page is cached.'));
        }
        $plugin->warmer->queue($plugin->warmer->importantFirst($urls), false);

        return $this->asSuccess(Craft::t('speedy', 'Building {count, plural, =1{# page} other{# pages}} in the background.', ['count' => count($urls)]));
    }

    public function actionSetUpCloudflare(): ?Response
    {
        try {
            return $this->asSuccess(Plugin::getInstance()->cdn->setUpCloudflare());
        } catch (Throwable $e) {
            return $this->asFailure($e->getMessage());
        }
    }

    public function actionCheckServer(): ?Response
    {
        $storage = Plugin::getInstance()->storage;
        $result = Plugin::getInstance()->serverRules->check();
        $state = $storage->readData('state');
        $state['serverChecked'] = $result['ok'];
        $state['folderOpen'] = $result['folderOpen'] ?? null;
        $storage->writeData('state', $state);

        return $result['ok'] ? $this->asSuccess($result['message']) : $this->asFailure($result['message']);
    }

    public function actionTestCdn(): ?Response
    {
        $cdn = (string)$this->request->getRequiredBodyParam('cdn');
        try {
            return $this->asSuccess(Plugin::getInstance()->cdn->test($cdn));
        } catch (Throwable $e) {
            return $this->asFailure($e->getMessage());
        }
    }
}
