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
use suprim\speedy\Plugin;
use Throwable;

/**
 * Rules that let Nginx or Apache serve cached pages without PHP, and a check that they work.
 */
class ServerRules extends Component
{
    /**
     * The cached pages’ folder as a URL path from the web root, such as `/speedy`, or null when it isn’t a folder
     * below the web root. Everything that names the folder uses this, so none can name the whole site.
     */
    public function webPath(): ?string
    {
        $webroot = rtrim(str_replace('\\', '/', (string)Craft::getAlias('@webroot')), '/');
        $root = rtrim(str_replace('\\', '/', Plugin::getInstance()->storage->cacheRoot()), '/');

        return str_starts_with($root, $webroot . '/') ? self::folderPath(substr($root, strlen($webroot))) : null;
    }

    /**
     * A folder path below the web root, such as `/speedy` or `/cache/speedy`, or null for anything that could name
     * more than it, such as `/`, an empty path, `..` or a wildcard. In `Disallow: /`, `location ^~ / { internal; }` or
     * a rewrite rule, a path like that would hide or break the whole site.
     */
    public static function folderPath(string $path): ?string
    {
        if (!preg_match('#^(/[A-Za-z0-9_~-][A-Za-z0-9._~-]*)+$#', $path)) {
            return null;
        }
        foreach (explode('/', substr($path, 1)) as $part) {
            if (trim($part, '.') === '') {
                return null;
            }
        }

        return $path;
    }

    /**
     * Parts of the names of cookies that mean a visitor gets pages built for them.
     *
     * @return string[]
     */
    public function bypassCookies(): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $names = ['_identity', Cacher::STATE_COOKIE, ...$settings->skipCookies];
        if ($settings->skipWhenCart) {
            $names[] = '_commerce_cart';
        }

        return array_values(array_unique(array_filter(array_map('strval', $names))));
    }

    /**
     * Cookies that mean a visitor gets pages built for them, as a regular expression.
     */
    private function cookiePattern(): string
    {
        return implode('|', array_map(fn($n) => preg_quote($n, '/'), $this->bypassCookies()));
    }

    public function nginx(): string
    {
        $path = $this->webPath() ?? '/speedy';
        $cacheControl = Plugin::getInstance()->cdn->cacheControl();
        $cookies = $this->cookiePattern();

        return <<<NGINX
        # Speedy Cache: serve cached pages without PHP. Add to the server block, before “location /”.
        set \$speedy_page "$path/\$host\$uri/index.html";
        if (\$request_method !~ ^(GET|HEAD)$) { set \$speedy_page ""; }
        if (\$args != "") { set \$speedy_page ""; }
        if (\$http_cookie ~* "$cookies") { set \$speedy_page ""; }
        if (\$http_x_requested_with != "") { set \$speedy_page ""; }
        if (\$http_hx_request != "") { set \$speedy_page ""; }
        if (\$http_x_up_target != "") { set \$speedy_page ""; }
        if (\$http_x_speedy_warm != "") { set \$speedy_page ""; }
        if (-f \$document_root\$speedy_page) { rewrite ^ \$speedy_page last; }

        location ^~ $path/ {
            internal;
            default_type text/html;
            gzip_static on;
            # brotli_static on;  # Sends the Brotli copy, if nginx has the Brotli module (ngx_brotli).
            add_header X-Speedy "STATIC" always;
            add_header Cache-Control "$cacheControl" always;
            # nginx doesn’t pass on the server block’s add_header lines here: repeat your security headers, such as
            # add_header Strict-Transport-Security "max-age=31536000" always;
        }
        NGINX;
    }

    public function apache(): string
    {
        $path = $this->webPath() ?? '/speedy';
        $cacheControl = Plugin::getInstance()->cdn->cacheControl();
        $cookies = $this->cookiePattern();

        $dir = ltrim($path, '/');
        // The cache folder’s own .htaccess must know the copies these rules send, such as Brotli ones.
        Plugin::getInstance()->storage->protectFolder();

        return <<<APACHE
        # Speedy Cache: serve cached pages without PHP.
        # In web/.htaccess, after “RewriteEngine On” and before Craft’s rules:

        # The cache folder can’t be opened directly, only through the rules below.
        RewriteCond %{ENV:REDIRECT_STATUS} ^$
        RewriteRule ^$dir/ - [F]
        RewriteRule ^$dir/.+\.html\.br$ - [T=text/html,E=no-gzip:1,E=no-brotli:1,E=SPEEDY_BR:1,L]
        RewriteRule ^$dir/.+\.html\.gz$ - [T=text/html,E=no-gzip:1,E=no-brotli:1,E=SPEEDY_GZ:1,L]

        # Requests that must reach Craft skip the next three rules.
        RewriteCond %{REQUEST_METHOD} !^(GET|HEAD)$ [OR]
        RewriteCond %{QUERY_STRING} !^$ [OR]
        RewriteCond %{HTTP_COOKIE} ($cookies) [NC,OR]
        RewriteCond %{HTTP:X-Requested-With} !^$ [OR]
        RewriteCond %{HTTP:HX-Request} !^$ [OR]
        RewriteCond %{HTTP:X-Up-Target} !^$ [OR]
        RewriteCond %{HTTP:X-Speedy-Warm} !^$
        RewriteRule .* - [S=3]

        # The Brotli or gzip copy, for browsers that accept it, or the page itself.
        RewriteCond %{HTTP:Accept-Encoding} \bbr\b
        RewriteCond %{DOCUMENT_ROOT}$path/%{HTTP_HOST}%{REQUEST_URI}/index.html.br -s
        RewriteRule .* $path/%{HTTP_HOST}%{REQUEST_URI}/index.html.br [L,E=SPEEDY:1]
        RewriteCond %{HTTP:Accept-Encoding} gzip
        RewriteCond %{DOCUMENT_ROOT}$path/%{HTTP_HOST}%{REQUEST_URI}/index.html.gz -s
        RewriteRule .* $path/%{HTTP_HOST}%{REQUEST_URI}/index.html.gz [L,E=SPEEDY:1]
        RewriteCond %{DOCUMENT_ROOT}$path/%{HTTP_HOST}%{REQUEST_URI}/index.html -s
        RewriteRule .* $path/%{HTTP_HOST}%{REQUEST_URI}/index.html [L,E=SPEEDY:1]

        Header set X-Speedy "STATIC" env=REDIRECT_SPEEDY
        Header set Cache-Control "$cacheControl" env=REDIRECT_SPEEDY
        Header set Vary "Accept-Encoding" env=REDIRECT_SPEEDY
        Header set Content-Encoding "gzip" env=SPEEDY_GZ
        Header set Content-Encoding "br" env=SPEEDY_BR
        APACHE;
    }

    /**
     * Requests the homepage twice and works out who served the second copy.
     *
     * @return array{ok: bool, message: string, ms: ?int, folderOpen?: ?bool, robotsKeepsOut?: ?bool}
     */
    public function check(): array
    {
        $url = UrlHelper::siteUrl('/');
        $client = Craft::createGuzzleClient(array_filter([
            'timeout' => 20,
            'http_errors' => false,
            'verify' => !Craft::$app->getConfig()->getGeneral()->devMode,
            'auth' => Plugin::getInstance()->cacher->certainHosts() !== null ? Plugin::getInstance()->getSettings()->getWarmAuth() : null,
        ], fn($v) => $v !== null));
        try {
            $client->get($url);
            $start = microtime(true);
            $response = $client->get($url);
            $ms = (int)round((microtime(true) - $start) * 1000);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => Craft::t('speedy', 'Couldn’t load {url}: {error}', ['url' => $url, 'error' => $e->getMessage()]), 'ms' => null];
        }

        $result = $this->describe($response, $ms);
        $result['folderOpen'] = $this->folderIsOpen($client, $url);
        // Only asked when it matters: an open folder that robots.txt keeps search engines out of is less pressing.
        $result['robotsKeepsOut'] = $result['folderOpen'] ? $this->robotsKeepsOut($client, $url) : null;

        return $result;
    }

    /**
     * @return array{ok: bool, message: string, ms: ?int}
     */
    private function describe(\Psr\Http\Message\ResponseInterface $response, int $ms): array
    {
        $state = $response->getHeaderLine(Cacher::HEADER);
        if ($state === 'STATIC' || ($state === '' && !$response->hasHeader('X-Powered-By'))) {
            return ['ok' => true, 'message' => Craft::t('speedy', 'Cached pages are served without PHP, in {ms} ms.', ['ms' => $ms]), 'ms' => $ms];
        }
        if ($state === 'HIT') {
            return ['ok' => false, 'message' => Craft::t('speedy', 'Cached pages are served by PHP, in {ms} ms. Add the server rules to serve them without PHP, several times faster.', ['ms' => $ms]), 'ms' => $ms];
        }

        return ['ok' => false, 'message' => Craft::t('speedy', 'The homepage isn’t cached ({state}).', ['state' => $state ?: Craft::t('speedy', 'no Speedy header')]), 'ms' => $ms];
    }

    /**
     * Whether the homepage’s cached file can be opened at its own address, such as /speedy/example.com/index.html.
     * Apache refuses it from the start; nginx once the server rules mark the folder internal. Null when unknown.
     */
    public function folderIsOpen(\GuzzleHttp\ClientInterface $client, string $url): ?bool
    {
        $storage = Plugin::getInstance()->storage;
        $webPath = $this->webPath();
        $key = $storage->keyForUrl($url);
        if ($storage->usesCache() || $webPath === null || $key === null || !$storage->exists($key)) {
            return null;
        }
        $parts = parse_url($url);
        $direct = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '') . $webPath . '/' . $key . '/index.html';
        try {
            $response = $client->request('GET', $direct, ['allow_redirects' => false, 'http_errors' => false]);
        } catch (Throwable) {
            return null;
        }

        return $response->getStatusCode() === 200 && str_contains((string)$response->getBody(), '<');
    }

    /**
     * Whether the site’s robots.txt tells search engines to keep out of the cache folder. Null when it can’t be read.
     */
    public function robotsKeepsOut(\GuzzleHttp\ClientInterface $client, string $url): ?bool
    {
        $webPath = $this->webPath();
        if ($webPath === null) {
            return null;
        }
        $parts = parse_url($url);
        $robots = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/robots.txt';
        try {
            $response = $client->request('GET', $robots, ['allow_redirects' => true, 'http_errors' => false]);
        } catch (Throwable) {
            return null;
        }
        if ($response->getStatusCode() !== 200) {
            return false;
        }

        return self::robotsDisallows((string)$response->getBody(), $webPath . '/');
    }

    /**
     * Whether robots.txt keeps every search engine out of a path, such as `/speedy/`. A search engine follows only
     * the group naming it, or `User-agent: *` if none does, so there must be a `*` group and every group must disallow it.
     */
    public static function robotsDisallows(string $robots, string $path): bool
    {
        $groups = [];
        $inAgents = false;
        foreach (preg_split('/\R/', $robots) ?: [] as $line) {
            $line = trim((string)preg_replace('/#.*/', '', $line));
            if (!str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);
            if ($field === 'user-agent') {
                // A run of User-agent lines starts one group.
                if (!$inAgents) {
                    $groups[] = ['all' => false, 'out' => false];
                }
                $groups[count($groups) - 1]['all'] = $groups[count($groups) - 1]['all'] || $value === '*';
                $inAgents = true;
                continue;
            }
            $inAgents = false;
            if ($groups && $field === 'disallow' && $value !== '') {
                $prefix = rtrim(rtrim($value, '$'), '*');
                if ($prefix === '' || str_starts_with($path, $prefix)) {
                    $groups[count($groups) - 1]['out'] = true;
                }
            }
        }

        return in_array(true, array_column($groups, 'all'), true) && !in_array(false, array_column($groups, 'out'), true);
    }
}
