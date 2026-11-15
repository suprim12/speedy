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
        Plugin::getInstance()->clearer->clearAll(Craft::t('speedy', 'Cleared by {user}', ['user' => (string)Craft::$app->getUser()->getIdentity()]));

        return $this->asSuccess(Craft::t('speedy', 'Every cached page was cleared.'));
    }

    public function actionRebuildAll(): ?Response
    {
        $count = Plugin::getInstance()->clearer->rebuildAll(Craft::t('speedy', 'Rebuilding everything, by {user}', ['user' => (string)Craft::$app->getUser()->getIdentity()]));

        return $this->asSuccess(Craft::t('speedy', 'Rebuilding {count, plural, =1{# page} other{# pages}} in the background.', ['count' => $count]));
    }

    public function actionClearUrl(): ?Response
    {
        $url = trim((string)$this->request->getRequiredBodyParam('url'));
        $count = Plugin::getInstance()->clearer->clearUrl($url);

        return $count ? $this->asSuccess(Craft::t('speedy', 'Cleared {url}.', ['url' => $url])) : $this->asFailure(Craft::t('speedy', '{url} isn’t cached.', ['url' => $url]));
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

    public function actionCheckServer(): ?Response
    {
        $storage = Plugin::getInstance()->storage;
        $result = Plugin::getInstance()->serverRules->check();
        $state = $storage->readData('state');
        $state['serverChecked'] = $result['ok'];
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
