<?php
/**
 * Speedy Cache plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\speedy\services;

use Craft;
use craft\base\Component;
use craft\helpers\UrlHelper;
use craft\web\Request;
use craft\web\Response;
use suprim\speedy\Plugin;
use Throwable;
use yii\base\Event;

/**
 * Serves cached pages and saves new ones.
 */
class Cacher extends Component
{
    /** Response header showing what Speedy did: HIT, MISS or SKIP. */
    public const HEADER = 'X-Speedy';

    /** Placeholder for the CSRF token in cached pages; the refresh script replaces it. */
    public const CSRF_PLACEHOLDER = '__SPEEDY_CSRF__';

    /** The cache key for this request, when it can be cached. */
    private ?string $_key = null;

    /** Why this request isn’t cached, shown in the X-Speedy header. */
    private ?string $_skipReason = null;

    /** Set by `craft.speedy.skip()` or `craft.speedy.ttl()` in templates. */
    private bool $_templateSkip = false;
    private ?int $_templateTtl = null;

    private bool $_collecting = false;

    /** Whether this request is rebuilding a page for Speedy itself (warming). */
    private bool $_warming = false;

    /**
     * Called once per site request, early: serves a cached copy, or starts recording what the page uses.
     */
    public function handleRequest(): void
    {
        $request = Craft::$app->getRequest();
        if (!$request instanceof Request) {
            return;
        }

        $this->_warming = $this->isWarmRequest($request);
        $this->_key = $this->cacheKey($request);
        if ($this->_key === null) {
            Craft::$app->getResponse()->getHeaders()->set(self::HEADER, 'SKIP ' . $this->_skipReason);
            return;
        }

        Plugin::getInstance()->cdn->detect($request);

        if (!$this->_warming && $this->serve($this->_key)) {
            return;
        }

        Craft::$app->getElements()->startCollectingCacheInfo();
        $this->_collecting = true;
        Event::on(Response::class, Response::EVENT_AFTER_PREPARE, function(Event $event) {
            /** @var Response $response */
            $response = $event->sender;
            $this->store($response);
        });
    }

    /**
     * For templates: `{% do craft.speedy.skip() %}` stops this page being cached.
     */
    public function skip(): void
    {
        $this->_templateSkip = true;
    }

    /**
     * For templates: `{% do craft.speedy.ttl(600) %}` clears this page after the given number of seconds.
     */
    public function ttl(int $seconds): void
    {
        $this->_templateTtl = $this->_templateTtl === null ? $seconds : min($this->_templateTtl, $seconds);
    }

    /**
     * The cache key for a request, or null with a reason when it shouldn’t be cached.
     */
    public function cacheKey(Request $request): ?string
    {
        $settings = Plugin::getInstance()->getSettings();
        $reason = match (true) {
            !$settings->isEnabled() => 'disabled',
            !$request->getIsSiteRequest() => 'not a site request',
            !in_array($request->getMethod(), ['GET', 'HEAD'], true) => 'method',
            $request->getIsActionRequest() => 'action request',
            $request->getIsPreview() || $request->getToken() !== null => 'preview',
            $this->hasCookie($request, '_identity') || !Craft::$app->getUser()->getIsGuest() => 'logged in',
            $settings->skipWhenCart && $this->hasCookie($request, '_commerce_cart') => 'cart',
            default => null,
        };
        if ($reason === null && $settings->queryStrings === 'skip' && $this->remainingParams($request)) {
            $reason = 'query string';
        }
        $path = $request->getFullPath();
        if ($reason === null && $this->matchesAny($path, $settings->getNeverCache())) {
            $reason = 'never cache';
        }
        if ($reason !== null) {
            $this->_skipReason = $reason;
            return null;
        }

        $key = Plugin::getInstance()->storage->key($request->getHostName() ?? '', $path);
        if ($key === null) {
            $this->_skipReason = 'url';
        }

        return $key;
    }

    /**
     * Sends a cached copy, if there is a fresh one. Used when the web server isn’t serving cached files itself.
     */
    private function serve(string $key): bool
    {
        $storage = Plugin::getInstance()->storage;
        $file = $storage->htmlFile($key);
        if (!is_file($file)) {
            return false;
        }
        $meta = $storage->meta($key);
        if (($meta['expires'] ?? null) !== null && $meta['expires'] <= time()) {
            $storage->delete($key);
            return false;
        }

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->content = (string)file_get_contents($file);
        $headers = $response->getHeaders();
        $headers->set('Content-Type', 'text/html; charset=UTF-8');
        $headers->set(self::HEADER, 'HIT');
        $headers->set('Cache-Control', Plugin::getInstance()->cdn->cacheControl());
        Craft::$app->end(0, $response);
    }

    /**
     * Saves the response, if it can be cached.
     */
    private function store(Response $response): void
    {
        if (!$this->_collecting || $this->_key === null) {
            return;
        }
        $this->_collecting = false;
        $elements = Craft::$app->getElements();
        // Craft caps the duration at its cacheDuration setting (a day by default) when nothing is scheduled.
        // Speedy only wants real scheduled changes, such as an entry going live, so it reads them first.
        $scheduled = (fn() => $this->_cacheDuration ?? null)->call($elements);
        [$dependency] = $elements->stopCollectingCacheInfo();
        $duration = is_int($scheduled) && $scheduled > 0 ? $scheduled : null;

        $reason = $this->uncacheableReason($response);
        if ($reason !== null) {
            $response->getHeaders()->set(self::HEADER, 'SKIP ' . $reason);
            return;
        }

        $tags = $dependency->tags ?? [];
        $settings = Plugin::getInstance()->getSettings();
        $ttls = array_filter([$duration, $this->_templateTtl, $settings->defaultTtl], fn($t) => $t !== null && $t > 0);
        $expires = $ttls ? time() + min($ttls) : null;

        try {
            $html = $this->prepareHtml((string)$response->content);
            $url = UrlHelper::siteUrl(Craft::$app->getRequest()->getFullPath());
            Plugin::getInstance()->storage->write($this->_key, $html, $url, $tags, $expires);
            if ($expires !== null) {
                Plugin::getInstance()->clearer->scheduleExpiry($expires);
            }
        } catch (Throwable $e) {
            Craft::warning('Couldn’t cache ' . $this->_key . ': ' . $e->getMessage(), 'speedy');
            $response->getHeaders()->set(self::HEADER, 'SKIP error');
            return;
        }

        $response->getHeaders()->set(self::HEADER, $this->_warming ? 'WARM' : 'MISS');
        $response->getHeaders()->set('Cache-Control', Plugin::getInstance()->cdn->cacheControl());
    }

    private function uncacheableReason(Response $response): ?string
    {
        $headers = $response->getHeaders();
        $request = Craft::$app->getRequest();

        return match (true) {
            $this->_templateSkip => 'template',
            $response->getStatusCode() !== 200 => 'status ' . $response->getStatusCode(),
            !str_contains(strtolower((string)$headers->get('Content-Type', 'text/html')), 'text/html') && $response->format !== Response::FORMAT_HTML => 'not html',
            !is_string($response->content) || $response->content === '' => 'empty',
            strlen((string)$response->content) > 5 * 1024 * 1024 => 'too large',
            str_contains(strtolower((string)$headers->get('Cache-Control')), 'private') || str_contains(strtolower((string)$headers->get('Cache-Control')), 'no-store') => 'private',
            $this->setsCookies($response) => 'sets a cookie',
            !Craft::$app->getUser()->getIsGuest() => 'logged in',
            $request instanceof Request && Plugin::getInstance()->getSettings()->skipWhenCart && $this->hasCookie($request, '_commerce_cart') => 'cart',
            default => null,
        };
    }

    /**
     * Whether the response sets a cookie other than Craft’s CSRF and session cookies, which aren’t personal.
     */
    private function setsCookies(Response $response): bool
    {
        $request = Craft::$app->getRequest();
        $harmless = [$request->csrfParam, Craft::$app->getConfig()->getGeneral()->phpSessionName];
        foreach ($response->getCookies() as $cookie) {
            if (!in_array($cookie->name, $harmless, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Swaps this visitor’s CSRF token for a placeholder and adds the script that fills in a fresh one.
     */
    private function prepareHtml(string $html): string
    {
        $request = Craft::$app->getRequest();
        if (!Plugin::getInstance()->getSettings()->refreshCsrf || !$request->enableCsrfValidation || !str_contains($html, $request->csrfParam)) {
            return $html;
        }
        $token = $request->getCsrfToken();
        if ($token === '' || !str_contains($html, $token)) {
            return $html;
        }
        $html = str_replace($token, self::CSRF_PLACEHOLDER, $html);
        $script = '<script>(function(){var p=' . json_encode(self::CSRF_PLACEHOLDER) . ';fetch(' . json_encode(UrlHelper::actionUrl('speedy/csrf')) . ',{headers:{Accept:"application/json"},credentials:"same-origin"}).then(function(r){return r.json()}).then(function(d){document.querySelectorAll("input[value=\'"+p+"\']").forEach(function(i){i.value=d.token});document.querySelectorAll("meta[content=\'"+p+"\']").forEach(function(m){m.content=d.token});window.csrfTokenValue=d.token})})();</script>';

        return str_contains($html, '</body>') ? substr_replace($html, $script . '</body>', strrpos($html, '</body>'), 7) : $html . $script;
    }

    private function isWarmRequest(Request $request): bool
    {
        $given = $request->getHeaders()->get('X-Speedy-Warm');

        return is_string($given) && hash_equals(Plugin::getInstance()->warmer->secret(), $given);
    }

    private function hasCookie(Request $request, string $suffix): bool
    {
        foreach (array_keys($_COOKIE) as $name) {
            if (str_ends_with((string)$name, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Query parameters left after ignoring tracking parameters.
     *
     * @return string[]
     */
    private function remainingParams(Request $request): array
    {
        $ignored = Plugin::getInstance()->getSettings()->ignoredParams;
        $params = array_keys($request->getQueryParams());
        $pathParam = Craft::$app->getConfig()->getGeneral()->pathParam;

        return array_values(array_filter($params, fn($p) => $p !== $pathParam && !$this->matchesAny((string)$p, $ignored)));
    }

    /**
     * @param string[] $patterns
     */
    private function matchesAny(string $value, array $patterns): bool
    {
        $value = trim($value, '/');
        foreach ($patterns as $pattern) {
            $regex = '#^' . str_replace('\*', '.*', preg_quote(trim((string)$pattern, '/'), '#')) . '$#i';
            if (preg_match($regex, $value)) {
                return true;
            }
        }

        return false;
    }
}
