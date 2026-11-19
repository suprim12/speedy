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
use suprim\speedy\events\CacheableRequestEvent;
use suprim\speedy\events\CachePageEvent;
use suprim\speedy\helpers\InstantLinks;
use suprim\speedy\Plugin;
use Throwable;
use yii\base\Event;

/**
 * Serves cached pages and saves new ones.
 */
class Cacher extends Component
{
    /** Before a page is cached: set `isValid` to false to skip it, or change its HTML or tags. */
    public const EVENT_BEFORE_CACHE = 'beforeCache';

    /** Before a request is served from the cache or built for it: stop it being cached. */
    public const EVENT_IS_CACHEABLE = 'isCacheable';

    /** After a page is saved. */
    public const EVENT_AFTER_CACHE = 'afterCache';

    /** Response header showing what Speedy did: HIT, MISS or SKIP. */
    public const HEADER = 'X-Speedy';

    /** Set while a visitor has something in their session, such as a form step or a flash message. */
    public const STATE_COOKIE = 'speedy_state';

    /** How long an expired page may still be sent while its new copy is built, in seconds. */
    private const STALE_GRACE = 600;

    /** Query parameter that makes warming requests miss CDN caches. */
    public const WARM_PARAM = 'speedy-warm';

    /** Marks a page that was just built without being saved, in Craft’s cache. */
    private const NOT_SAVED = 'speedy:notSaved:';

    /** Response headers saved with a page and sent again when PHP serves it. */
    private const KEPT_HEADERS = ['X-Robots-Tag', 'Content-Language', 'Link', 'Content-Security-Policy', 'Referrer-Policy', 'Permissions-Policy', 'X-Frame-Options', 'X-Content-Type-Options'];

    /**
     * Session keys some plugins set for every visitor who sees a form, such as Formie’s. Pages that show them aren’t
     * cached, but they don’t stop the visitor getting cached copies of other pages.
     */
    private const AMBIENT_SESSION_KEYS = ['formie:authority'];

    /** Request headers sent by Ajax libraries, whose requests often get a partial page at the same URL. */
    private const AJAX_HEADERS = ['X-Requested-With', 'HX-Request', 'X-Up-Target'];

    /**
     * Cookies that never change what a page shows: analytics and bot-protection IDs, read by scripts in the browser.
     * Matched by the start of the name.
     */
    private const IGNORED_COOKIES = ['_ga', '_gid', '_gat', '_gcl', '__utm', '_fbp', '_fbc', '_hj', '_clck', '_clsk', '_uet', '_pk_', '__hs', 'hubspotutk', '_ttp', '__cf_bm', 'cf_clearance', '_cfuvid', '__stripe_'];

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

    private bool $_stateChecked = false;

    /** The page whose build lock is held in Craft’s mutex, when pages are kept in Craft’s cache. */
    private ?string $_mutexKey = null;

    /** How long a visitor waits for another request to finish building the same page, in seconds. */
    private const BUILD_WAIT = 5;

    /** Whether this request is rebuilding a page for Speedy itself (warming). */
    private bool $_warming = false;

    /** @var array<string, string> The query parameters this page is cached with, from the `cachedParams` setting. */
    private array $_query = [];

    /** @var string[] Tags a template gave this page with `craft.speedy.tag()`. */
    private array $_customTags = [];

    /** Whether the request has cookies that could change the page, so it’s built for this visitor alone. */
    private bool $_ownCookies = false;

    /** Whether this page can’t be cached however often it’s built, such as one that sets a cookie. */
    private bool $_neverSaved = false;

    /** The page’s address, as stored: built from its key, so it always rebuilds the same page. */
    private ?string $_url = null;

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

        if ($this->hasEventHandlers(self::EVENT_IS_CACHEABLE)) {
            $event = new CacheableRequestEvent(['request' => $request, 'key' => $this->_key]);
            $this->trigger(self::EVENT_IS_CACHEABLE, $event);
            if (!$event->isValid) {
                $this->_key = null;
                $headers = Craft::$app->getResponse()->getHeaders();
                $headers->set(self::HEADER, 'SKIP event');
                $headers->set('Cache-Control', 'private, no-cache');
                return;
            }
        }

        Plugin::getInstance()->cdn->detect($request);

        if (!$this->_warming && $this->serve($this->_key)) {
            return;
        }

        // When many visitors ask for the same uncached page at once, such as just after clearing everything,
        // one builds it and the others wait for that copy, instead of every one of them building it.
        // A page is only saved from a request without cookies of its own, so nobody can put something into the
        // copy everyone gets by sending a made-up cookie. This visitor gets a page built for them.
        $this->_ownCookies = !$this->_warming && $this->hasOwnCookies();
        if (!$this->_warming && !$this->_ownCookies && !$this->lockOrWait($this->_key) && $this->serve($this->_key)) {
            return;
        }

        Craft::$app->getElements()->startCollectingCacheInfo();
        $this->_collecting = true;
        if ($this->_warming) {
            Plugin::getInstance()->queries->start();
        }
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
     * For templates: `{% do craft.speedy.tag('weather') %}` lets the page be cleared by that name, from the console,
     * the API or PHP, such as when data from outside Craft changes.
     */
    public function tag(string ...$names): void
    {
        foreach ($names as $name) {
            $tag = self::customTag($name);
            if ($tag !== null && !in_array($tag, $this->_customTags, true)) {
                $this->_customTags[] = $tag;
            }
        }
    }

    /**
     * The cache tag for a name given to `craft.speedy.tag()`, or null when it isn’t usable.
     */
    public static function customTag(string $name): ?string
    {
        $name = trim($name);

        return $name !== '' && strlen($name) <= 100 ? 'speedy:' . strtolower($name) : null;
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
            !$this->plainHost($request) => 'unknown host',
            // The port the server really took the request on: X-Forwarded-Port can say anything.
            !$this->isSiteHost((string)$request->getHostName(), parse_url($request->getHostInfo() ?? '', PHP_URL_PORT), (int)($_SERVER['SERVER_PORT'] ?? 0) ?: null) => 'unknown host',
            default => null,
        };
        $this->_query = [];
        if ($reason === null && $settings->queryStrings === 'skip' && ($params = $this->remainingParams($request))) {
            $query = $this->cachedQuery($request, $params);
            if ($query === null) {
                $reason = 'query string';
                $this->noteSkippedParams($request, $params);
            } else {
                $this->_query = $query;
            }
        }
        $path = $request->getFullPath();
        // A site’s index.php can change the address before Craft starts, such as taking a prefix off to show the
        // page another way. The page is kept under the address the visitor asked for, which is also where the web
        // server looks for it, so the two versions never share a copy.
        $rewritten = $reason === null ? $this->rewrittenPath($request) : null;
        if ($rewritten === false) {
            $reason = 'rewritten URL';
        } elseif ($rewritten !== null) {
            $path = $rewritten;
        }
        // Patterns match the path within the site as well, so `/` and `news*` work for a site in a folder such as /de/.
        if ($reason === null && ($never = $settings->getNeverCache()) && ($this->matchesAny($path, $never) || $this->matchesAny($request->getPathInfo(), $never))) {
            $reason = 'never cache';
        }
        if ($reason === null && ($only = $settings->getOnlyCache()) && !$this->matchesAny($path, $only) && !$this->matchesAny($request->getPathInfo(), $only)) {
            $reason = 'not in only cache';
        }
        if ($reason !== null) {
            $this->_skipReason = $reason;
            return null;
        }

        $storage = Plugin::getInstance()->storage;
        $key = $storage->key($request->getHostName() ?? '', $path, $this->_query);
        if ($key === null) {
            $this->_skipReason = 'url';
        } elseif ($this->_query && !$storage->exists($key) && $storage->countQueryPages((string)$storage->key($request->getHostName() ?? '', $path)) >= $settings->maxQueryPages) {
            // Enough versions of this page are kept already; more are built for each visit instead.
            $this->_skipReason = 'too many query strings';
            $key = null;
        }

        if ($key !== null && $this->_warming && !$this->warmTokenMatches($request, $rewritten === null ? null : rtrim($request->getBaseUrl(), '/') . '/' . $path)) {
            // A rebuild token for another page, or an old one.
            $this->_warming = false;
            $this->_skipReason = 'rebuild token';
            $key = null;
        }
        $this->_url = $key !== null ? rtrim($request->getHostInfo() . $request->getBaseUrl(), '/') . '/' . ($path === '' ? '' : implode('/', array_map('rawurlencode', explode('/', $path)))) . ($this->_query ? '?' . http_build_query($this->_query) : '') : null;

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
            // A stuck queue would never rebuild it.
            if (!Plugin::getInstance()->getSettings()->rebuildCleared || $expires <= time() - self::STALE_GRACE || empty($meta['url']) || Plugin::getInstance()->insights->queueStalledFor() > 0) {
                $storage->delete($key);
                return false;
            }
            if (Craft::$app->getCache()->add('speedy:rebuilding:' . md5($key), true, 60)) {
                Plugin::getInstance()->warmer->queue([$meta['url']], true, [$meta['url'] => $key]);
            }
            $state = 'STALE';
        }

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $headers = $response->getHeaders();
        $request = Craft::$app->getRequest();
        $headers->set('Vary', 'Accept-Encoding');
        $headers->set('Content-Type', 'text/html; charset=UTF-8');
        foreach ($meta['headers'] ?? [] as $name => $value) {
            $headers->set($name, $value);
        }
        $headers->set(self::HEADER, $state);
        $headers->set('Cache-Control', $state === 'STALE' ? 'no-cache, s-maxage=0' : Plugin::getInstance()->cdn->cacheControl());
        $created = (int)($meta['created'] ?? 0);
        if ($created > 0) {
            $headers->set('ETag', self::etag($key, $created));
            $headers->set('Last-Modified', gmdate('D, d M Y H:i:s', $created) . ' GMT');
            // The browser has this copy already: say so, without sending it again.
            if ($request instanceof Request && $this->notModified($request, $key, $created)) {
                $response->setStatusCode(304);
                $response->content = '';
                Craft::$app->end(0, $response);
            }
        }
        $encodings = self::acceptedEncodings($request instanceof Request ? (string)$request->getHeaders()->get('Accept-Encoding') : '');
        $page = $storage->read($key, isset($encodings['gzip']), isset($encodings['br']));
        if ($page === null) {
            return false;
        }
        $response->content = $page['content'];
        $encoding = $page['encoding'] ?? ($page['gzip'] ? 'gzip' : null);
        if ($encoding !== null) {
            $headers->set('Content-Encoding', $encoding);
        }
        Craft::$app->end(0, $response);
    }

    /**
     * The compressions a browser accepts, from its Accept-Encoding header, such as `gzip, br`. One it marks `q=0`,
     * such as `br;q=0`, is refused, so it isn’t one of them.
     *
     * @return array<string, true>
     */
    public static function acceptedEncodings(string $header): array
    {
        $accepted = [];
        foreach (explode(',', strtolower($header)) as $part) {
            $bits = array_map('trim', explode(';', $part));
            $name = array_shift($bits);
            $q = 1.0;
            foreach ($bits as $bit) {
                if (preg_match('/^q\s*=\s*([0-9.]+)$/', $bit, $m)) {
                    $q = (float)$m[1];
                }
            }
            if ($name !== '' && $q > 0) {
                $accepted[$name] = true;
            }
        }

        return $accepted;
    }

    /**
     * A page’s ETag: the same for its plain and compressed copies, and new each time it’s built.
     */
    public static function etag(string $key, int $created): string
    {
        return 'W/"' . substr(md5("$key|$created"), 0, 16) . '"';
    }

    /**
     * Whether the browser’s copy is this one, by its ETag, or by its date when it sent no ETag.
     */
    private function notModified(Request $request, string $key, int $created): bool
    {
        $match = (string)$request->getHeaders()->get('If-None-Match');
        if ($match !== '') {
            $etag = self::etag($key, $created);
            foreach (explode(',', $match) as $tag) {
                // Apache adds -gzip or -br to the tag of a response it compressed itself.
                $tag = (string)preg_replace('/-(gzip|br)"$/', '"', trim($tag));
                // Weak comparison: W/"x" matches "x", as the copies only differ in compression.
                if ($tag === '*' || $tag === $etag || 'W/' . $tag === $etag) {
                    return true;
                }
            }
            return false;
        }
        $since = strtotime((string)$request->getHeaders()->get('If-Modified-Since'));

        return $since !== false && $since >= $created;
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
            // Built but not saved, such as a page that sets a cookie or calls skip(): for a minute, visitors build it
            // side by side instead of waiting in turn for a copy that won’t come.
            if ($this->_neverSaved) {
                Craft::$app->getCache()->set(self::NOT_SAVED . md5($this->_key), true, 60);
            }
            $this->unlock();
            if ($this->_warming) {
                try {
                    Plugin::getInstance()->queries->finish((string)$this->_url);
                } catch (Throwable $e) {
                    // The report is extra: never let it fail a rebuild.
                    Craft::warning('Couldn’t update the repeated queries report: ' . $e->getMessage(), 'speedy');
                }
            }
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
        // The page itself can’t be cached, rather than this copy: no visitor will save it either. Not for errors, which
        // may be gone in a moment.
        $this->_neverSaved = $reason !== null && (in_array($reason, ['template', 'url case', 'not html', 'too large', 'private', 'vary', 'sets a cookie'], true) || in_array($response->getStatusCode(), [301, 302, 303, 307, 308, 404, 410], true));
        if ($reason !== null) {
            $headers = $response->getHeaders();
            $headers->set(self::HEADER, 'SKIP ' . $reason);
            if (!$headers->has('Cache-Control')) {
                $headers->set('Cache-Control', 'private, no-cache');
            }
            return;
        }

        if ($this->_ownCookies) {
            // Build the copy for everyone in the background, from a request without cookies. Only for hosts known
            // for certain, so a made-up host can’t make the server request it.
            $url = (string)$this->_url;
            if ($this->certainHosts() !== null && Craft::$app->getCache()->add('speedy:building:' . md5((string)$this->_key), true, 60)) {
                Plugin::getInstance()->warmer->queue([$url], false);
            }
            $response->getHeaders()->set(self::HEADER, 'SKIP request cookies');
            $response->getHeaders()->set('Cache-Control', 'private, no-cache');
            return;
        }

        if (Plugin::getInstance()->storage->unsafeFolder() !== null) {
            $response->getHeaders()->set(self::HEADER, 'SKIP cache folder');
            $response->getHeaders()->set('Cache-Control', 'private, no-cache');
            return;
        }
        if (Plugin::getInstance()->storage->diskFull()) {
            $response->getHeaders()->set(self::HEADER, 'SKIP disk full');
            $response->getHeaders()->set('Cache-Control', 'private, no-cache');
            return;
        }
        if (Plugin::getInstance()->storage->overSizeLimit()) {
            $response->getHeaders()->set(self::HEADER, 'SKIP cache full');
            $response->getHeaders()->set('Cache-Control', 'private, no-cache');
            return;
        }

        $tags = $dependency->tags ?? [];
        $settings = Plugin::getInstance()->getSettings();
        $ttls = array_filter([$duration, $this->_templateTtl, $settings->defaultTtl, $this->sectionLifetime()], fn($t) => $t !== null && $t > 0);
        $expires = $ttls ? time() + min($ttls) : null;

        try {
            $html = $this->prepareHtml((string)$response->content);
            if ($settings->instantLinks) {
                $sitePath = (string)parse_url((string)Craft::$app->getSites()->getCurrentSite()->getBaseUrl(), PHP_URL_PATH);
                $html = InstantLinks::add($html, $response->getHeaders()->toArray(), $settings->getNeverCache(), $settings->getOnlyCache(), $sitePath);
            }
            // The address as requested, without the query string. (Building it from the site’s base URL would
            // repeat a subfolder such as /de/ on multi-site.)
            $url = (string)$this->_url;
            $tags = array_merge($tags, $this->_customTags);
            $event = new CachePageEvent(['key' => $this->_key, 'url' => $url, 'html' => $html, 'tags' => $tags, 'expires' => $expires]);
            if ($this->hasEventHandlers(self::EVENT_BEFORE_CACHE)) {
                $this->trigger(self::EVENT_BEFORE_CACHE, $event);
                if (!$event->isValid) {
                    $response->getHeaders()->set(self::HEADER, 'SKIP event');
                    return;
                }
            }
            $html = $event->html;
            if (Plugin::getInstance()->clearer->clearedSince((float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)), $event->tags)) {
                // Its content changed while it was being built, so this copy may be out of date already.
                $response->getHeaders()->set(self::HEADER, 'SKIP changed while building');
                $response->getHeaders()->set('Cache-Control', 'private, no-cache');
                return;
            }
            if ($settings->cacheComment) {
                $html .= "\n<!-- Cached by Speedy Cache on " . date('c') . ' -->';
            }
            $expires = $event->expires;
            Plugin::getInstance()->storage->write($this->_key, $html, $url, $event->tags, $expires, $this->keptHeaders($response));
            $this->rebuildAfterTransforms($html, $url, $this->_key);
            if ($expires !== null) {
                Plugin::getInstance()->clearer->scheduleExpiry($expires);
            }
            if ($this->hasEventHandlers(self::EVENT_AFTER_CACHE)) {
                $this->trigger(self::EVENT_AFTER_CACHE, new CachePageEvent(['key' => $this->_key, 'url' => $url, 'html' => $html, 'tags' => $event->tags, 'expires' => $expires]));
            }
        } catch (Throwable $e) {
            Craft::error('Couldn’t cache ' . $this->_key . ': ' . $e->getMessage(), 'speedy');
            $response->getHeaders()->set(self::HEADER, 'SKIP error');
            return;
        }

        $response->getHeaders()->set(self::HEADER, $this->_warming ? 'WARM' : 'MISS');
        // The same ETag the cached copy is sent with, so this visitor’s next visit can be answered with 304.
        // Not when this copy has the visitor’s own CSRF token, where the saved one has a fresh one each time: their
        // browser mustn’t keep using it once the token has expired.
        $created = (int)(Plugin::getInstance()->storage->meta((string)$this->_key)['created'] ?? 0);
        if (!$this->_warming && $created > 0 && !str_contains($html, self::CSRF_PLACEHOLDER)) {
            $response->getHeaders()->set('ETag', self::etag((string)$this->_key, $created));
            $response->getHeaders()->set('Last-Modified', gmdate('D, d M Y H:i:s', $created) . ' GMT');
        }
        // A warming request has a one-off URL, which CDNs mustn’t keep.
        // The saved copy has no cookies, but this response may set one, such as the CSRF cookie: a CDN mustn’t keep it.
        $setsCookie = $response->getCookies()->count() > 0 || $response->getHeaders()->has('Set-Cookie');
        $response->getHeaders()->set('Cache-Control', $this->_warming ? 'no-store' : ($setsCookie ? 'private, no-cache' : Plugin::getInstance()->cdn->cacheControl()));
    }

    private function uncacheableReason(Response $response): ?string
    {
        $headers = $response->getHeaders();
        $request = Craft::$app->getRequest();

        return match (true) {
            $this->_templateSkip => 'template',
            $response->getStatusCode() !== 200 => 'status ' . $response->getStatusCode(),
            $request instanceof Request && $this->inOtherCase($request) => 'url case',
            !str_contains(strtolower((string)$headers->get('Content-Type', 'text/html')), 'text/html') && $response->format !== Response::FORMAT_HTML => 'not html',
            !is_string($response->content) || $response->content === '' => 'empty',
            strlen((string)$response->content) > 5 * 1024 * 1024 => 'too large',
            $this->isPrivate($response) => 'private',
            $this->varies($response) => 'vary',
            // Formie 4 keeps an ID in the session for the form tokens it refreshes on each visitor’s copy.
            $this->sessionHasState(self::formieRefreshes() ? self::AMBIENT_SESSION_KEYS : []) => 'session',
            $this->setsCookies($response) => 'sets a cookie',
            !Craft::$app->getUser()->getIsGuest() => 'logged in',
            $request instanceof Request && Plugin::getInstance()->getSettings()->skipWhenCart && $this->hasCookie($request, '_commerce_cart') => 'cart',
            default => null,
        };
    }

    /**
     * The lifetime set for the section of the entry whose page this is, in seconds, or null.
     */
    private function sectionLifetime(): ?int
    {
        $settings = Plugin::getInstance()->getSettings();
        if (!$settings->sectionLifetimes) {
            return null;
        }
        $element = Craft::$app->getUrlManager()->getMatchedElement();
        $section = $element instanceof \craft\elements\Entry ? $element->getSection() : null;

        return $section ? $settings->getSectionLifetime((string)$section->handle) : null;
    }

    /**
     * Whether the address is an element’s page with its letters in another case, such as `/News` for `/news`. MySQL
     * finds the element either way, so each spelling, made up by anyone, would be one more copy of the same page.
     */
    private function inOtherCase(Request $request): bool
    {
        $element = Craft::$app->getUrlManager()->getMatchedElement();
        if (!$element || $element->uri === null) {
            return false;
        }
        $uri = $element->uri === \craft\base\Element::HOMEPAGE_URI ? '' : trim($element->uri, '/');
        $path = trim($request->getPathInfo(), '/');

        return $path !== $uri && mb_strtolower($path) === mb_strtolower($uri);
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
        // Cookies set with a raw header, by PHP’s setcookie() or another plugin.
        $raw = (array)$response->getHeaders()->get('Set-Cookie', [], false);
        foreach (headers_list() as $header) {
            if (stripos($header, 'Set-Cookie:') === 0) {
                $raw[] = ltrim(substr($header, 11));
            }
        }
        foreach ($raw as $header) {
            if (preg_match('/^([^=;\s]+)=/', (string)$header, $m) && !in_array($m[1], $harmless, true)) {
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
        $html = $this->addFormieRefresh($html);
        $script = '<script>(function(){var p=' . json_encode(self::CSRF_PLACEHOLDER) . ';fetch(' . json_encode(self::relativeActionUrl('speedy/csrf')) . ',{headers:{Accept:"application/json"},credentials:"same-origin"}).then(function(r){return r.json()}).then(function(d){document.querySelectorAll("input[value=\'"+p+"\']").forEach(function(i){i.value=d.token});document.querySelectorAll("meta[content=\'"+p+"\']").forEach(function(m){m.content=d.token});window.csrfTokenValue=d.token})})();</script>';

        return str_contains($html, '</body>') ? substr_replace($html, $script . '</body>', strrpos($html, '</body>'), 7) : $html . $script;
    }

    /** Rebuilds of one page for its image transforms in a day, in case they’re never made, such as for a missing file. */
    public const TRANSFORM_REBUILDS = 3;

    /**
     * A page built before its image transforms were made links to Craft’s action that makes them, so every visitor’s
     * images would go through PHP until the page is next cleared. Craft makes them in the queue, so rebuild the page
     * a minute later, when it can link to the images themselves. (Craft’s own template caches aren’t saved at all.)
     */
    private function rebuildAfterTransforms(string $html, string $url, string $key): void
    {
        if (!str_contains(stripslashes($html), 'assets/generate-transform')) {
            return;
        }
        $cache = Craft::$app->getCache();
        $tries = 'speedy:transformRebuilds:' . md5($url);
        $count = (int)$cache->get($tries);
        if ($count >= self::TRANSFORM_REBUILDS || !$cache->add(Clearer::REBUILD_LATER . md5($url), true, 60 + Clearer::REBUILD_LATER_GRACE)) {
            return;
        }
        $cache->set($tries, $count + 1, 86400);
        Plugin::getInstance()->warmer->queue([$url], true, [$url => $key], 60);
    }

    /**
     * Whether Formie refreshes its form tokens on each visitor’s copy of a cached page (Formie 4, told so by Speedy).
     */
    public static function formieRefreshes(): bool
    {
        $formie = Craft::$app->getPlugins()->getPlugin('formie');
        $settings = $formie?->getSettings();

        return $settings !== null && method_exists($settings, 'hasStaticCache') && $settings->hasStaticCache();
    }

    /**
     * Formie 3 forms on a cached page carry captcha tokens from when the page was built. Formie refreshes them
     * itself when asked, once each form starts, so ask it. (Formie 4 is told directly; see Plugin.)
     */
    private function addFormieRefresh(string $html): string
    {
        if (!str_contains($html, 'data-fui-form')) {
            return $html;
        }
        $formie = Craft::$app->getPlugins()->getPlugin('formie');
        if ($formie === null || version_compare($formie->getVersion(), '4.0.0', '>=')) {
            return $html;
        }
        // Formie starts its forms once the page has loaded, after this has run.
        $script = '<script>document.addEventListener("onFormieInit",function(e){var d=e.detail||{};if(d.formie&&d.formId&&d.formie.refreshForCache){d.formie.refreshForCache(d.formId)}});</script>';

        return str_contains($html, '</body>') ? substr_replace($html, $script . '</body>', strrpos($html, '</body>'), 7) : $html . $script;
    }

    /**
     * Pages built from session data, such as the second step of a multi-page form or a “Thanks” flash message,
     * differ per visitor. While a visitor has session data, a cookie tells Speedy and the web server rules to
     * build their pages fresh; it’s removed once the session is empty again.
     */
    public function markState(): void
    {
        if ($this->_stateChecked) {
            return;
        }
        $this->_stateChecked = true;
        $request = Craft::$app->getRequest();
        if (!$request instanceof Request) {
            return;
        }
        $session = Craft::$app->getSession();
        $marked = $this->hasCookie($request, self::STATE_COOKIE);
        if (!$session->getIsActive()) {
            // Craft may have saved and closed the session already, which is exactly when a form step or flash
            // message has just been stored. Reopen it by its ID to look: from the visitor’s cookie, or, for a
            // session started during this request, from the cookie PHP is about to send.
            $id = $this->sessionId(Craft::$app->getConfig()->getGeneral()->phpSessionName);
            if ($id === null) {
                return;
            }
            $session->setId($id);
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
     * The visitor’s session ID, from their cookie or from the session cookie this response is about to set.
     */
    private function sessionId(string $name): ?string
    {
        if (!empty($_COOKIE[$name]) && is_string($_COOKIE[$name])) {
            return $_COOKIE[$name];
        }
        foreach (headers_list() as $header) {
            if (preg_match('/^Set-Cookie:\s*' . preg_quote($name, '/') . '=([^;]+)/i', $header, $m)) {
                return urldecode($m[1]);
            }
        }

        return null;
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
        if ($this->markedNotSaved($key)) {
            return true;
        }
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
        $lockFile = $storage->lockFile($key);
        $handle = @fopen($lockFile, 'c');
        if ($handle === false) {
            return true;
        }
        // Marks it as in use, so old ones can be tidied away.
        @touch($lockFile);
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
                // The other request finished without saving the page. If the page can never be saved, such as one
                // that sets a cookie, build it without holding the others up; otherwise build it here, and the others
                // wait for this copy.
                if ($this->markedNotSaved($key)) {
                    flock($handle, LOCK_UN);
                    fclose($handle);
                    return true;
                }
                $this->_lock = $handle;
                return true;
            }
        }
        fclose($handle);

        return true;
    }

    /**
     * Whether the page was just built and couldn’t be saved, by this or another request.
     *
     * @phpstan-impure
     */
    private function markedNotSaved(string $key): bool
    {
        return (bool)Craft::$app->getCache()->get(self::NOT_SAVED . md5($key));
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
     * Whether pages are cached for this host, and port when it isn’t 80 or 443: a site’s, or one in `allowedHosts`.
     * When a site’s base URL is `@web`, which answers to any host, any host is cached unless `allowedHosts` lists them.
     *
     * @param int|null $port The port in the address, such as `8080` in `example.com:8080`. Null when there isn’t one.
     * @param int|null $serverPort The port the server really took the request on.
     */
    public function isSiteHost(string $host, ?int $port = null, ?int $serverPort = null): bool
    {
        $host = strtolower($host);
        $withPort = $port !== null && !in_array($port, [80, 443], true) ? "$host:$port" : $host;
        $hosts = $this->siteHosts();
        if ($hosts === false) {
            $allowed = Plugin::getInstance()->getSettings()->getAllowedHosts();
            if ($allowed) {
                return in_array($withPort, $allowed, true);
            }
            // Any host, on the usual ports or the one the server is on: a made-up port in the Host header would end
            // up in the page’s links.
            return $withPort === $host || $port === $serverPort;
        }

        return in_array($withPort, $hosts, true);
    }

    /**
     * Whether a host is one of the sites’ own, known for certain: from their base URLs or `allowedHosts`. Never any
     * host, unlike isSiteHost(), so it’s safe for requests Speedy makes itself, such as to an address someone entered.
     */
    public function isKnownSiteHost(string $host, ?int $port = null): bool
    {
        $host = strtolower($host);
        $withPort = $port !== null && !in_array($port, [80, 443], true) ? "$host:$port" : $host;

        return in_array($withPort, $this->certainHosts() ?? [], true);
    }

    /**
     * The hosts known for certain: the sites’ base URLs and `allowedHosts`. Null when a base URL is `@web` and
     * `allowedHosts` is empty, so the site answers to whatever host a request names.
     *
     * @return string[]|null
     */
    public function certainHosts(): ?array
    {
        $hosts = $this->siteHosts();
        if ($hosts === false) {
            return Plugin::getInstance()->getSettings()->getAllowedHosts() ?: null;
        }

        return $hosts;
    }

    /**
     * Whether the request’s host is a plain host and port, as the page is built with it: a forwarded host such as
     * `example.com?x` would build every link wrong, then be saved as example.com’s page. And when any host is
     * answered, it must be the host the request was really sent to, not one named by a forwarding header.
     */
    private function plainHost(Request $request): bool
    {
        if (!preg_match('#^https?://([a-z0-9.-]+|\[[0-9a-f:.]+\])(?::\d{1,5})?$#i', (string)$request->getHostInfo(), $m)) {
            return false;
        }
        if ($this->certainHosts() === null) {
            $real = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')) ?? '');
            return $real !== '' && $real === strtolower($m[1]);
        }

        return true;
    }

    /**
     * The sites’ hosts, with their port when it isn’t the usual one, or false when a site’s base URL is `@web`.
     *
     * @return string[]|false
     */
    private function siteHosts(): array|false
    {
        if ($this->_siteHosts !== null) {
            return $this->_siteHosts;
        }
        $hosts = [];
        foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
            // Sites without pages have no base URL, and say nothing about which hosts are fine.
            if (!$site->hasUrls) {
                continue;
            }
            $raw = (string)App::parseEnv((string)$site->getBaseUrl(false));
            if ($raw === '') {
                continue;
            }
            if (str_contains($raw, '@web')) {
                return $this->_siteHosts = false;
            }
            $parts = parse_url((string)Craft::getAlias($raw));
            if (!empty($parts['host'])) {
                $port = $parts['port'] ?? null;
                $hosts[] = strtolower($parts['host']) . ($port !== null && !in_array($port, [80, 443], true) ? ":$port" : '');
            }
        }
        $hosts = array_merge($hosts, Plugin::getInstance()->getSettings()->getAllowedHosts());

        return $this->_siteHosts = array_values(array_unique($hosts));
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

    /**
     * An action URL without the scheme and host, so cached pages work on every address the site answers on, such as
     * with and without www, or a CDN’s hostname.
     */
    public static function relativeActionUrl(string $action): string
    {
        $url = UrlHelper::actionUrl($action);
        $parts = parse_url($url);

        return ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * Whether the request carries a rebuild token that hasn’t expired. Which page it’s for is checked once the page’s
     * key is known, in warmTokenMatches().
     */
    /**
     * How the site’s own index.php changed the address before Craft started: null when it didn’t, the path to keep
     * the page under when it only took part of the address off (such as a prefix that shows the page another way),
     * or false for any other change, such as adding a language, as the address alone might not decide the page.
     *
     * The server’s own copy of the address is still in getenv() with PHP-FPM, and Apache adds the path it was asked
     * for as REDIRECT_URL when it rewrites to index.php. With neither, nothing is known to have changed.
     */
    public function rewrittenPath(Request $request): string|false|null
    {
        $asked = getenv('REQUEST_URI');
        $asked = is_string($asked) && $asked !== '' ? parse_url($asked, PHP_URL_PATH) : ($_SERVER['REDIRECT_URL'] ?? null);
        $now = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (!is_string($asked) || !is_string($now)) {
            return null;
        }
        $segments = fn(string $path) => array_values(array_filter(explode('/', rawurldecode($path)), fn($s) => $s !== ''));
        $asked = $segments($asked);
        $now = $segments($now);
        if ($asked === $now) {
            return null;
        }

        // Only part of the address taken off: what’s left is the start and end of the address asked for.
        $kept = count($now);
        if ($kept >= count($asked)) {
            return false;
        }
        $start = 0;
        while ($start < $kept && $asked[$start] === $now[$start]) {
            $start++;
        }
        if (array_slice($asked, count($asked) - ($kept - $start)) !== array_slice($now, $start)) {
            return false;
        }

        // Within the site, as Craft’s own path is, when Craft is in a subfolder.
        $base = $segments($request->getBaseUrl());
        if (array_slice($asked, 0, count($base)) !== $base) {
            return false;
        }

        return implode('/', array_slice($asked, count($base)));
    }

    private function isWarmRequest(Request $request): bool
    {
        $given = (string)$request->getHeaders()->get('X-Speedy-Warm');

        return (bool)preg_match('/^(\d+)\.[a-f0-9]{64}$/', $given, $m) && (int)$m[1] >= time();
    }

    /**
     * @param string|null $path The address the visitor asked for, when the site changed it before Craft started.
     */
    private function warmTokenMatches(Request $request, ?string $path = null): bool
    {
        $given = (string)$request->getHeaders()->get('X-Speedy-Warm');
        [$expires] = explode('.', $given, 2);
        $path ??= (string)parse_url($request->getUrl(), PHP_URL_PATH);
        $query = $request->getQueryParams();
        // Servers that rewrite to index.php?p=… (Craft’s path parameter) add it to every page. It’s left out only
        // when it names the page that was asked for, so it can’t point a token at another page.
        $pathParam = Craft::$app->getConfig()->getGeneral()->pathParam;
        if ($pathParam && isset($query[$pathParam]) && is_string($query[$pathParam])) {
            $named = trim(rawurldecode($query[$pathParam]), '/');
            $asked = '/' . trim(rawurldecode($path), '/');
            if ($named === '' || str_ends_with($asked, '/' . $named)) {
                unset($query[$pathParam]);
            }
        }

        return hash_equals(Plugin::getInstance()->warmer->tokenFor((string)$request->getHostName(), $path, $query, (int)$expires), $given);
    }

    /**
     * Whether the request has a cookie that could change what the page shows: any but Craft’s session and CSRF
     * cookies, analytics IDs and the ones in the `ignoredCookies` setting.
     */
    private function hasOwnCookies(): bool
    {
        $request = Craft::$app->getRequest();
        $ignored = array_merge(
            [$request->csrfParam, Craft::$app->getConfig()->getGeneral()->phpSessionName],
            self::IGNORED_COOKIES,
            Plugin::getInstance()->getSettings()->ignoredCookies,
        );
        foreach (array_keys($_COOKIE) as $name) {
            $name = (string)$name;
            $known = false;
            foreach ($ignored as $prefix) {
                if ($prefix !== '' && str_starts_with($name, $prefix)) {
                    $known = true;
                    break;
                }
            }
            if (!$known) {
                return true;
            }
        }

        return false;
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
    /**
     * The query parameters to cache the page with, sorted, or null when any of them isn’t in `cachedParams`.
     *
     * @param string[] $params
     * @return array<string, string>|null
     */
    private function cachedQuery(Request $request, array $params): ?array
    {
        $allowed = Plugin::getInstance()->getSettings()->cachedParams;
        $pageParam = self::pageParam();
        if ($pageParam !== null) {
            $allowed[] = $pageParam;
        }
        $query = [];
        foreach ($params as $param) {
            $value = $request->getQueryParam($param);
            // One plain value each, so `?page[]=` and long values can’t make endless copies.
            if (!$allowed || !$this->matchesAny((string)$param, $allowed) || !is_scalar($value) || strlen((string)$value) > 100) {
                return null;
            }
            $query[(string)$param] = (string)$value;
        }
        ksort($query);

        return $query;
    }

    /** Craft cache key for the query parameters that kept pages out of the cache, for the utility page. */
    public const SKIPPED_PARAMS = 'speedy:skippedParams';

    /** One skipped request in this many is counted, so a busy site writes rarely. */
    public const SKIPPED_SAMPLE = 10;

    /**
     * Counts the parameters that kept a page out of the cache, such as `view` in `?view=split`, so the utility page
     * can suggest listing them in `cachedParams` or `ignoredParams`. Sampled, and capped at 20 names.
     *
     * @param string[] $params
     */
    private function noteSkippedParams(Request $request, array $params): void
    {
        if (mt_rand(1, self::SKIPPED_SAMPLE) !== 1) {
            return;
        }
        $allowed = Plugin::getInstance()->getSettings()->cachedParams;
        $cache = Craft::$app->getCache();
        $seen = $cache->get(self::SKIPPED_PARAMS);
        $seen = is_array($seen) ? $seen : [];
        foreach ($params as $param) {
            $param = (string)$param;
            // Plain names only: made-up parameters can’t fill the list with junk.
            // Speedy’s own rebuild parameter is never one to suggest.
            if ($param === self::WARM_PARAM || ($allowed && $this->matchesAny($param, $allowed)) || !preg_match('/^[A-Za-z][A-Za-z0-9_\-]{0,39}$/', $param)) {
                continue;
            }
            if (!isset($seen[$param]) && count($seen) >= 20) {
                continue;
            }
            $seen[$param] = ['count' => ($seen[$param]['count'] ?? 0) + 1, 'path' => '/' . ltrim(mb_substr($request->getFullPath(), 0, 100), '/')];
        }
        $cache->set(self::SKIPPED_PARAMS, $seen, 7 * 86400);
    }

    /**
     * Craft’s pagination parameter when it paginates with a query string (`pageTrigger` set to `'?page='`), so those
     * pages are cached without setting anything. Path pagination (`/news/p2`, the default) is cached like any page.
     */
    public static function pageParam(): ?string
    {
        $trigger = Craft::$app->getConfig()->getGeneral()->pageTrigger;
        if (!str_starts_with($trigger, '?')) {
            return null;
        }
        $param = trim(substr($trigger, 1), '=');

        return $param !== '' ? $param : null;
    }

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
        return self::matchingPattern($value, $patterns) !== null;
    }

    /**
     * The first pattern a URL path matches, such as `news/*`, or null. `*` matches anything.
     *
     * @param string[] $patterns
     */
    public static function matchingPattern(string $path, array $patterns): ?string
    {
        $path = trim($path, '/');
        foreach ($patterns as $pattern) {
            $regex = '#^' . str_replace('\\*', '.*', preg_quote(trim((string)$pattern, '/'), '#')) . '$#i';
            if (preg_match($regex, $path)) {
                return (string)$pattern;
            }
        }

        return null;
    }
}
