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
use craft\helpers\App;
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

    /** Set while a visitor has something in their session, such as a form step or a flash message. */
    public const STATE_COOKIE = 'speedy_state';

    /** How long an expired page may still be sent while its new copy is built, in seconds. */
    private const STALE_GRACE = 600;

    /** Query parameter that makes warming requests miss CDN caches. */
    public const WARM_PARAM = 'speedy-warm';

    /** Response headers saved with a page and sent again when PHP serves it. */
    private const KEPT_HEADERS = ['X-Robots-Tag', 'Content-Language', 'Link', 'Content-Security-Policy', 'Referrer-Policy', 'Permissions-Policy', 'X-Frame-Options', 'X-Content-Type-Options'];

    /**
     * Session keys some plugins set for every visitor who sees a form, such as Formie’s. Pages that show them aren’t
     * cached, but they don’t stop the visitor getting cached copies of other pages.
     */
    private const AMBIENT_SESSION_KEYS = ['formie:authority'];

    /** Request headers sent by Ajax libraries, whose requests often get a partial page at the same URL. */
    private const AJAX_HEADERS = ['X-Requested-With', 'HX-Request', 'X-Up-Target'];

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

    /** @var resource|null The lock held while this request builds its page. */
    private $_lock = null;

    /** The page whose build lock is held in Craft’s mutex, when pages are kept in Craft’s cache. */
    private ?string $_mutexKey = null;

    /** How long a visitor waits for another request to finish building the same page, in seconds. */
    private const BUILD_WAIT = 5;

    /** Whether this request is rebuilding a page for Speedy itself (warming). */
    private bool $_warming = false;

    /** @var string[]|false|null The sites’ hosts, or false when a site answers on any host. */
    private array|false|null $_siteHosts = null;

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
            $headers = Craft::$app->getResponse()->getHeaders();
            $headers->set(self::HEADER, 'SKIP ' . $this->_skipReason);
            if ($this->_skipReason !== 'disabled') {
                // Tell CDNs not to keep it, in case they cache everything. A template can still set its own.
                $headers->set('Cache-Control', 'private, no-cache');
            }
            return;
        }

        Plugin::getInstance()->cdn->detect($request);

        if (!$this->_warming && $this->serve($this->_key)) {
            return;
        }

        // When many visitors ask for the same uncached page at once, such as just after clearing everything,
        // one builds it and the others wait for that copy, instead of every one of them building it.
        if (!$this->_warming && !$this->lockOrWait($this->_key) && $this->serve($this->_key)) {
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
     * Whether this request’s page is going to be cached, as far as is known while it’s being built.
     */
    public function willCache(): bool
    {
        return $this->_key !== null && $this->_collecting && !$this->_templateSkip;
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
            $this->hasCookie($request, self::STATE_COOKIE) => 'session',
            $this->hasCookieContaining($settings->skipCookies) => 'cookie',
            $this->isAjax($request) => 'ajax',
            !$this->isSiteHost((string)$request->getHostName()) => 'unknown host',
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
        if (!$storage->exists($key)) {
            return false;
        }
        $meta = $storage->meta($key);
        $expires = $meta['expires'] ?? null;
        $state = 'HIT';
        if ($expires !== null && $expires <= time()) {
            // Expired, such as a ttl() page or scheduled content going live. Keep sending the old copy for a short
            // while and rebuild it in the background, so this visitor doesn't wait. Past the grace period, or when
            // rebuilding is off, build it now instead.
            if (!Plugin::getInstance()->getSettings()->rebuildCleared || $expires <= time() - self::STALE_GRACE || empty($meta['url'])) {
                $storage->delete($key);
                return false;
            }
            if (Craft::$app->getCache()->add('speedy:rebuilding:' . md5($key), true, 60)) {
                Plugin::getInstance()->warmer->queue([$meta['url']], true);
            }
            $state = 'STALE';
        }

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $headers = $response->getHeaders();
        $request = Craft::$app->getRequest();
        $acceptsGzip = $request instanceof Request && str_contains((string)$request->getHeaders()->get('Accept-Encoding'), 'gzip');
        $page = $storage->read($key, $acceptsGzip);
        if ($page === null) {
            return false;
        }
        $response->content = $page['content'];
        if ($page['gzip']) {
            $headers->set('Content-Encoding', 'gzip');
        }
        $headers->set('Vary', 'Accept-Encoding');
        $headers->set('Content-Type', 'text/html; charset=UTF-8');
        foreach ($meta['headers'] ?? [] as $name => $value) {
            $headers->set($name, $value);
        }
        $headers->set(self::HEADER, $state);
        $headers->set('Cache-Control', $state === 'STALE' ? 'no-cache, s-maxage=0' : Plugin::getInstance()->cdn->cacheControl());
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
        try {
            $this->storeResponse($response);
        } finally {
            $this->unlock();
        }
    }

    private function storeResponse(Response $response): void
    {
        $elements = Craft::$app->getElements();
        // Craft caps the duration at its cacheDuration setting (a day by default) when nothing is scheduled.
        // Speedy only wants real scheduled changes, such as an entry going live, so it reads them first.
        $scheduled = (fn() => $this->_cacheDuration ?? null)->call($elements);
        [$dependency] = $elements->stopCollectingCacheInfo();
        $duration = is_int($scheduled) && $scheduled > 0 ? $scheduled : null;

        $reason = $this->uncacheableReason($response);
        if ($reason !== null) {
            $headers = $response->getHeaders();
            $headers->set(self::HEADER, 'SKIP ' . $reason);
            if (!$headers->has('Cache-Control')) {
                $headers->set('Cache-Control', 'private, no-cache');
            }
            return;
        }

        $tags = $dependency->tags ?? [];
        $settings = Plugin::getInstance()->getSettings();
        $ttls = array_filter([$duration, $this->_templateTtl, $settings->defaultTtl], fn($t) => $t !== null && $t > 0);
        $expires = $ttls ? time() + min($ttls) : null;

        try {
            $html = $this->prepareHtml((string)$response->content);
            if ($settings->cacheComment) {
                $html .= "\n<!-- Cached by Speedy Cache on " . date('c') . ' -->';
            }
            $url = UrlHelper::siteUrl(Craft::$app->getRequest()->getFullPath());
            Plugin::getInstance()->storage->write($this->_key, $html, $url, $tags, $expires, $this->keptHeaders($response));
            if ($expires !== null) {
                Plugin::getInstance()->clearer->scheduleExpiry($expires);
            }
        } catch (Throwable $e) {
            Craft::error('Couldn’t cache ' . $this->_key . ': ' . $e->getMessage(), 'speedy');
            $response->getHeaders()->set(self::HEADER, 'SKIP error');
            return;
        }

        $response->getHeaders()->set(self::HEADER, $this->_warming ? 'WARM' : 'MISS');
        // A warming request has a one-off URL, which CDNs mustn’t keep.
        $response->getHeaders()->set('Cache-Control', $this->_warming ? 'no-store' : Plugin::getInstance()->cdn->cacheControl());
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
            $this->isPrivate($response) => 'private',
            $this->varies($response) => 'vary',
            $this->sessionHasState() => 'session',
            $this->setsCookies($response) => 'sets a cookie',
            !Craft::$app->getUser()->getIsGuest() => 'logged in',
            $request instanceof Request && Plugin::getInstance()->getSettings()->skipWhenCart && $this->hasCookie($request, '_commerce_cart') => 'cart',
            default => null,
        };
    }

    /**
     * Whether the response asks not to be cached. Craft sends `no-cache, no-store, must-revalidate` whenever a page
     * shows a CSRF token, so that the token isn’t cached; Speedy replaces the token, so that case is cacheable.
     */
    private function isPrivate(Response $response): bool
    {
        $cacheControl = strtolower(trim((string)$response->getHeaders()->get('Cache-Control')));
        if (!str_contains($cacheControl, 'private') && !str_contains($cacheControl, 'no-store')) {
            return false;
        }
        $request = Craft::$app->getRequest();
        $craftCsrfHeader = $cacheControl === 'no-cache, no-store, must-revalidate';
        $showsToken = $request->enableCsrfValidation && is_string($response->content) && str_contains($response->content, $request->getCsrfToken());

        return !($craftCsrfHeader && $showsToken && Plugin::getInstance()->getSettings()->refreshCsrf);
    }

    /**
     * Whether the response sets a cookie other than Craft’s CSRF and session cookies, which aren’t personal.
     */
    private function setsCookies(Response $response): bool
    {
        $request = Craft::$app->getRequest();
        $harmless = [$request->csrfParam, Craft::$app->getConfig()->getGeneral()->phpSessionName, self::STATE_COOKIE];
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

    /**
     * Pages built from session data, such as the second step of a multi-page form or a “Thanks” flash message,
     * differ per visitor. While a visitor has session data, a cookie tells Speedy and the web server rules to
     * build their pages fresh; it’s removed once the session is empty again.
     */
    public function markState(): void
    {
        $request = Craft::$app->getRequest();
        if (!$request instanceof Request) {
            return;
        }
        $session = Craft::$app->getSession();
        $marked = $this->hasCookie($request, self::STATE_COOKIE);
        if (!$session->getIsActive()) {
            if (!$marked || !$this->hasCookie($request, Craft::$app->getConfig()->getGeneral()->phpSessionName)) {
                return;
            }
            $session->open();
        }
        $hasState = $this->sessionHasState(self::AMBIENT_SESSION_KEYS);
        if ($hasState === $marked) {
            return;
        }
        $cookies = Craft::$app->getResponse()->getCookies();
        if ($hasState) {
            $cookies->add(new \yii\web\Cookie(['name' => self::STATE_COOKIE, 'value' => '1', 'httpOnly' => true, 'secure' => $request->getIsSecureConnection(), 'sameSite' => \yii\web\Cookie::SAME_SITE_LAX]));
        } else {
            $cookies->remove(new \yii\web\Cookie(['name' => self::STATE_COOKIE]), true);
        }
    }

    /**
     * Whether this visitor’s session holds anything, such as a form step or a flash message.
     *
     * @param string[] $alsoIgnore
     */
    private function sessionHasState(array $alsoIgnore = []): bool
    {
        $session = Craft::$app->getSession();
        if (!$session->getIsActive()) {
            return false;
        }
        // Flash messages are stored as their own keys; the flash key only counts them. The CSRF token is kept in
        // the session when Craft’s enableCsrfCookie is off, and Speedy refreshes it on cached pages anyway.
        $ignored = [$session->flashParam, Craft::$app->getRequest()->csrfParam, ...$alsoIgnore];
        foreach ($_SESSION ?? [] as $name => $value) {
            if (!in_array($name, $ignored, true) && $value !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Takes the build lock for a page. When another request holds it, waits for that request to save the page and
     * returns false, so the caller can serve it; if it takes too long, the caller builds the page itself.
     */
    private function lockOrWait(string $key): bool
    {
        $storage = Plugin::getInstance()->storage;
        if ($storage->usesCache()) {
            // Several servers share the cache, so the lock is Craft’s mutex rather than a local file.
            $until = microtime(true) + self::BUILD_WAIT;
            do {
                if ($storage->cache()->lock($key)) {
                    $this->_mutexKey = $key;
                    return true;
                }
                usleep(100000);
                if ($storage->exists($key)) {
                    return false;
                }
            } while (microtime(true) < $until);

            return true;
        }
        $handle = @fopen($storage->lockFile($key), 'c');
        if ($handle === false) {
            return true;
        }
        if (flock($handle, LOCK_EX | LOCK_NB)) {
            $this->_lock = $handle;
            return true;
        }
        $file = Plugin::getInstance()->storage->htmlFile($key);
        $until = microtime(true) + self::BUILD_WAIT;
        while (microtime(true) < $until) {
            usleep(100000);
            clearstatcache(true, $file);
            if (is_file($file)) {
                fclose($handle);
                return false;
            }
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                // The other request finished without saving the page, such as a 404. Build it here.
                $this->_lock = $handle;
                return true;
            }
        }
        fclose($handle);

        return true;
    }

    private function unlock(): void
    {
        if ($this->_mutexKey !== null) {
            Plugin::getInstance()->storage->cache()->unlock($this->_mutexKey);
            $this->_mutexKey = null;
        }
        if ($this->_lock) {
            flock($this->_lock, LOCK_UN);
            fclose($this->_lock);
            $this->_lock = null;
        }
    }

    /**
     * Whether the response varies by something other than compression, such as `Vary: User-Agent` or `Vary: Cookie`.
     */
    private function varies(Response $response): bool
    {
        $vary = array_map('trim', explode(',', strtolower((string)$response->getHeaders()->get('Vary'))));

        return (bool)array_diff(array_filter($vary), ['accept-encoding']);
    }

    /**
     * @return array<string, string>
     */
    private function keptHeaders(Response $response): array
    {
        $kept = [];
        foreach (self::KEPT_HEADERS as $name) {
            $value = $response->getHeaders()->get($name);
            if (is_string($value) && $value !== '') {
                $kept[$name] = $value;
            }
        }

        return $kept;
    }

    private function isAjax(Request $request): bool
    {
        foreach (self::AJAX_HEADERS as $name) {
            if ($request->getHeaders()->has($name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the host belongs to a site, so a forged Host header can’t fill the disk with copies.
     * Sites whose base URL is `@web` answer on any host, so any host is accepted then.
     */
    private function isSiteHost(string $host): bool
    {
        $hosts = $this->_siteHosts;
        if ($hosts === null) {
            $hosts = [];
            foreach (Craft::$app->getSites()->getAllSites() as $site) {
                $raw = (string)App::parseEnv((string)$site->getBaseUrl(false));
                if ($raw === '' || str_contains($raw, '@web')) {
                    $hosts = false;
                    break;
                }
                $siteHost = parse_url((string)Craft::getAlias($raw), PHP_URL_HOST);
                if ($siteHost) {
                    $hosts[] = strtolower($siteHost);
                }
            }
            $this->_siteHosts = $hosts;
        }

        return $hosts === false || in_array(strtolower($host), $hosts, true);
    }

    /**
     * @param string[] $parts
     */
    private function hasCookieContaining(array $parts): bool
    {
        foreach (array_keys($_COOKIE) as $name) {
            foreach ($parts as $part) {
                if ($part !== '' && str_contains((string)$name, $part)) {
                    return true;
                }
            }
        }

        return false;
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

        return array_values(array_filter($params, fn($p) => $p !== $pathParam && !($this->_warming && $p === self::WARM_PARAM) && !$this->matchesAny((string)$p, $ignored)));
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
