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
use suprim\speedy\elements\actions\ClearPages;
use suprim\speedy\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The Clear button in an element’s details, such as on an entry’s edit page.
 */
class ElementsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        if (!ClearPages::allowed()) {
            throw new ForbiddenHttpException('User is not permitted to clear cached pages.');
        }

        return true;
    }

    /**
     * Clears the cached pages that show an element, its own page included.
     */
    public function actionClear(): Response
    {
        $id = (int)$this->request->getRequiredBodyParam('elementId');
        $element = Craft::$app->getElements()->getElementById($id, null, '*', ['status' => null]);
        // Only what the user can see, so it can’t tell them about other elements.
        if ($element === null || !Craft::$app->getElements()->canView($element)) {
            return $this->asFailure(Craft::t('speedy', 'That element doesn’t exist.'));
        }
        $id = (int)($element->getCanonicalId() ?? $element->id);
        $count = Plugin::getInstance()->clearer->clearTags(["element::$id"], Craft::t('speedy', 'Cleared “{title}” by {user}', [
            'title' => mb_strimwidth((string)$element, 0, 60, '…'),
            'user' => (string)Craft::$app->getUser()->getIdentity(),
        ]), false);

        return $this->asSuccess(Craft::t('speedy', '{count, plural, =0{No cached pages show it.} =1{# page cleared.} other{# pages cleared.}}', ['count' => $count]));
    }
}
