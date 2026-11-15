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
     * The cached pages’ folder as a URL path from the web root, such as `/speedy`, or null when it isn’t in the web root.
     */
    public function webPath(): ?string
    {
        $webroot = rtrim(str_replace('\\', '/', (string)Craft::getAlias('@webroot')), '/');
        $root = Plugin::getInstance()->storage->cacheRoot();

        return str_starts_with($root, $webroot . '/') ? substr($root, strlen($webroot)) : null;
    }

    public function nginx(): string
    {
        $path = $this->webPath() ?? '/speedy';
        $cacheControl = Plugin::getInstance()->cdn->cacheControl();

        return <<<NGINX
        # Speedy Cache: serve cached pages without PHP. Add to the server block, before “location /”.
        set \$speedy_page "$path/\$host\$uri/index.html";
        if (\$request_method !~ ^(GET|HEAD)$) { set \$speedy_page ""; }
        if (\$args != "") { set \$speedy_page ""; }
        if (\$http_cookie ~* "_identity|_commerce_cart|speedy_state") { set \$speedy_page ""; }
        if (\$http_x_speedy_warm != "") { set \$speedy_page ""; }
        if (-f \$document_root\$speedy_page) { rewrite ^ \$speedy_page last; }

        location ^~ $path/ {
            internal;
            default_type text/html;
            add_header X-Speedy "STATIC" always;
            add_header Cache-Control "$cacheControl" always;
        }
        NGINX;
    }

    public function apache(): string
    {
        $path = $this->webPath() ?? '/speedy';
        $cacheControl = Plugin::getInstance()->cdn->cacheControl();

        return <<<APACHE
        # Speedy Cache: serve cached pages without PHP.
        # In web/.htaccess, after “RewriteEngine On” and before Craft’s rules:
        RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$
        RewriteCond %{QUERY_STRING} ^$
        RewriteCond %{HTTP_COOKIE} !(_identity|_commerce_cart|speedy_state) [NC]
        RewriteCond %{HTTP:X-Speedy-Warm} ^$
        RewriteCond %{DOCUMENT_ROOT}$path/%{HTTP_HOST}%{REQUEST_URI}/index.html -s
        RewriteRule .* $path/%{HTTP_HOST}%{REQUEST_URI}/index.html [L,E=SPEEDY:1]
        Header set X-Speedy "STATIC" env=REDIRECT_SPEEDY
        Header set Cache-Control "$cacheControl" env=REDIRECT_SPEEDY
        APACHE;
    }

    /**
     * Requests the homepage twice and works out who served the second copy.
     *
     * @return array{ok: bool, message: string, ms: ?int}
     */
    public function check(): array
    {
        $url = UrlHelper::siteUrl('/');
        $client = Craft::createGuzzleClient(['timeout' => 20, 'http_errors' => false, 'verify' => !Craft::$app->getConfig()->getGeneral()->devMode]);
        try {
            $client->get($url);
            $start = microtime(true);
            $response = $client->get($url);
            $ms = (int)round((microtime(true) - $start) * 1000);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => Craft::t('speedy', 'Couldn’t load {url}: {error}', ['url' => $url, 'error' => $e->getMessage()]), 'ms' => null];
        }

        $state = $response->getHeaderLine(Cacher::HEADER);
        if ($state === 'STATIC' || ($state === '' && !$response->hasHeader('X-Powered-By'))) {
            return ['ok' => true, 'message' => Craft::t('speedy', 'Cached pages are served without PHP, in {ms} ms.', ['ms' => $ms]), 'ms' => $ms];
        }
        if ($state === 'HIT') {
            return ['ok' => false, 'message' => Craft::t('speedy', 'Cached pages are served by PHP, in {ms} ms. Add the server rules to serve them without PHP, several times faster.', ['ms' => $ms]), 'ms' => $ms];
        }

        return ['ok' => false, 'message' => Craft::t('speedy', 'The homepage isn’t cached ({state}).', ['state' => $state ?: Craft::t('speedy', 'no Speedy header')]), 'ms' => $ms];
    }
}
