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
use suprim\speedy\services\Dynamic;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Renders the per-visitor parts of cached pages. Only templates placed with craft.speedy.dynamic() can be rendered,
 * as each part is identified by a signed token.
 */
class DynamicController extends Controller
{
    protected array|int|bool $allowAnonymous = ['render'];

    // It only renders templates the site placed, and changes nothing.
    public $enableCsrfValidation = false;

    public function actionRender(): Response
    {
        $this->requirePostRequest();
        $tokens = $this->request->getBodyParam('tokens');
        if (!is_array($tokens) || count($tokens) > Dynamic::MAX_PARTS) {
            throw new BadRequestHttpException('Invalid tokens.');
        }
        $dynamic = Plugin::getInstance()->dynamic;
        $parts = [];
        foreach (array_values($tokens) as $token) {
            try {
                $parts[] = is_string($token) ? $dynamic->render($token) : null;
            } catch (\Throwable $e) {
                // One part with an error mustn’t keep the others off the page: it keeps its placeholder.
                Craft::error('Couldn’t render a dynamic part: ' . $e->getMessage(), 'speedy');
                // Scripts and styles it registered before the error aren’t the next part’s.
                Craft::$app->getView()->getHeadHtml();
                Craft::$app->getView()->getBodyHtml();
                $parts[] = null;
            }
        }
        $this->response->getHeaders()->set('Cache-Control', 'private, no-store');

        return $this->asJson(['parts' => $parts]);
    }
}
