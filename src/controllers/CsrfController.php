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
use yii\web\Response;

/**
 * A fresh CSRF token for forms on cached pages. Never cached.
 */
class CsrfController extends Controller
{
    protected array|int|bool $allowAnonymous = ['index'];

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $this->response->getHeaders()->set('Cache-Control', 'private, no-store');

        return $this->asJson(['name' => $request->csrfParam, 'token' => $request->getCsrfToken()]);
    }
}
