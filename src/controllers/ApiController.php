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
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Clear or rebuild pages over HTTP with the API key, such as from a deploy pipeline or another system.
 *
 *   POST actions/speedy/api/clear               everything
 *   POST actions/speedy/api/clear  urls[]=…     those pages
 *   POST actions/speedy/api/clear  tags[]=…     pages tagged with craft.speedy.tag()
 *   POST actions/speedy/api/rebuild             every page, keeping old copies until the new ones are ready
 *   GET  actions/speedy/api/status              for uptime monitors: 200 while all is well, 503 with the problems
 *
 * Send the key in an `Authorization: Bearer …` header, or as a `key` field in the POST body. Never in the URL. The
 * status can also be read with `?statusToken=…`, a token made from the key that can't do anything else. (Craft keeps
 * `token` for its own share links.)
 */
class ApiController extends Controller
{
    protected array|int|bool $allowAnonymous = ['clear', 'rebuild', 'status'];

    // Requests come from other systems, authenticated by the key rather than a session.
    public $enableCsrfValidation = false;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $settings = Plugin::getInstance()->getSettings();
        $key = $settings->getApiKey();
        if (strlen($key) < 16) {
            throw new NotFoundHttpException();
        }
        if ($action->id === 'status') {
            if (!in_array($this->request->getMethod(), ['GET', 'HEAD'], true)) {
                throw new \yii\web\BadRequestHttpException('Use GET.');
            }
            $given = (string)preg_replace('/^Bearer\s+/i', '', (string)$this->request->getHeaders()->get('Authorization'));
            $token = (string)$this->request->getQueryParam('statusToken', '');
            if (!($given !== '' && hash_equals($key, $given)) && !($token !== '' && hash_equals($settings->getStatusToken(), $token))) {
                throw new ForbiddenHttpException('Invalid API key or status token.');
            }
            $this->response->getHeaders()->set('Cache-Control', 'private, no-store');

            return true;
        }
        $this->requirePostRequest();
        $given = (string)preg_replace('/^Bearer\s+/i', '', (string)$this->request->getHeaders()->get('Authorization'));
        if ($given === '') {
            // For senders that can't set headers, such as some webhooks. Never from the query string, which ends up in logs.
            $given = (string)$this->request->getBodyParam('key', '');
        }
        if ($given === '' || !hash_equals($key, $given)) {
            throw new ForbiddenHttpException('Invalid API key.');
        }
        $this->response->getHeaders()->set('Cache-Control', 'private, no-store');

        return true;
    }

    /**
     * Whether caching works, for uptime monitors: 200 while all is well, 503 with the problems that need fixing.
     */
    public function actionStatus(): Response
    {
        $status = Plugin::getInstance()->insights->status();
        $this->response->setStatusCode($status['ok'] ? 200 : 503);

        return $this->asJson($status);
    }

    /** The most URLs with `*` in one call. */
    private const MOST_PATTERNS = 10;

    public function actionClear(): Response
    {
        $clearer = Plugin::getInstance()->clearer;
        $tags = $this->request->getBodyParam('tags');
        if (is_array($tags) && $tags) {
            $names = array_slice(array_filter($tags, 'is_string'), 0, 100);
            $count = $clearer->clearCustomTags($names, Craft::t('speedy', 'Cleared tags {tags}, with the API key', ['tags' => implode(', ', $names)]));
            return $this->asJson(['success' => true, 'cleared' => $count]);
        }
        $urls = $this->request->getBodyParam('urls');
        if (is_array($urls) && $urls) {
            $count = 0;
            $patterns = 0;
            foreach (array_slice(array_filter($urls, 'is_string'), 0, 1000) as $url) {
                // Each pattern looks through every cached page, so a call takes a few.
                if (str_contains($url, '*') && ++$patterns > self::MOST_PATTERNS) {
                    return $this->asJson(['success' => false, 'error' => 'At most ' . self::MOST_PATTERNS . ' URLs with * at a time.'])->setStatusCode(400);
                }
            }
            foreach (array_slice(array_filter($urls, 'is_string'), 0, 1000) as $url) {
                $count += $clearer->clearUrl($url);
            }
            return $this->asJson(['success' => true, 'cleared' => $count]);
        }
        $count = Plugin::getInstance()->storage->stats()['pages'];
        if (!$clearer->clearAll(Craft::t('speedy', 'Cleared with the API key'))) {
            return $this->asJson(['success' => false, 'error' => 'The cache folder isn’t a folder of Speedy’s own, so nothing was cleared.'])->setStatusCode(500);
        }

        return $this->asJson(['success' => true, 'cleared' => $count]);
    }

    public function actionRebuild(): Response
    {
        $count = Plugin::getInstance()->clearer->rebuildAll(Craft::t('speedy', 'Rebuilding everything, with the API key'));

        return $this->asJson(['success' => true, 'rebuilding' => $count]);
    }
}
