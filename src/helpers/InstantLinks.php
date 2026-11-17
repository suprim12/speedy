<?php
/**
 * Speedy Cache plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\speedy\helpers;

use Craft;

/**
 * Instant links: browsers that support speculation rules fetch a page as soon as the visitor points at its link, so it
 * opens straight away. Only the HTML is fetched (no scripts run, so analytics don’t count a visit), and only for links
 * that are safe to open without a click.
 */
class InstantLinks
{
    /** Links never fetched ahead: new windows (an empty target or `_self` is the same window), downloads, links the site marks, and any with a query string. */
    private const SKIPPED_LINKS = '[data-no-prefetch], [data-no-prefetch] [href], [download], [target]:not([target=""], [target="_self"], [target="_top"], [target="_parent"]), [rel~="nofollow"], [href*="?"], [href^="#"]';

    /**
     * The page with the rules added before `</body>`, or as it was when they can’t be added safely.
     *
     * @param array<string, string|string[]> $headers The response headers, to respect its Content-Security-Policy.
     * @param string[] $neverCache
     * @param string[] $onlyCache
     */
    public static function add(string $html, array $headers, array $neverCache, array $onlyCache, string $sitePath = ''): string
    {
        $body = strripos($html, '</body>');
        if ($body === false || self::hasOwnRules($html) || !self::allowedBy($headers) || preg_match('/<meta[^>]+http-equiv\s*=\s*["\']?content-security-policy/i', $html)) {
            return $html;
        }

        return substr_replace($html, self::script($neverCache, $onlyCache, $sitePath), $body, 0);
    }

    /**
     * @param string[] $neverCache
     * @param string[] $onlyCache
     */
    public static function script(array $neverCache, array $onlyCache, string $sitePath = ''): string
    {
        $general = Craft::$app->getConfig()->getGeneral();
        $skipped = ['/index.php', '/index.php/*', '/' . trim($general->actionTrigger, '/') . '/*'];
        if ($general->cpTrigger) {
            array_push($skipped, ...self::paths([$general->cpTrigger, $general->cpTrigger . '/*'], $sitePath));
        }
        // Signing in and out, and links sent by email, do something when they’re opened.
        foreach (['loginPath', 'logoutPath', 'setPasswordPath', 'verifyEmailPath'] as $setting) {
            $value = $general->$setting;
            foreach ((array)$value as $path) {
                if (is_string($path) && trim($path, '/') !== '') {
                    array_push($skipped, ...self::paths([$path], $sitePath));
                }
            }
        }
        // Pages that aren’t cached would be built for nothing if the visitor doesn’t click.
        array_push($skipped, ...self::paths($neverCache, $sitePath));
        // A dot in the path is usually a file, such as a PDF, which can be large.
        $skipped[] = '/*.*';

        $where = [
            ['href_matches' => '/*'],
            ['not' => ['href_matches' => array_values(array_unique($skipped))]],
            ['not' => ['selector_matches' => self::SKIPPED_LINKS]],
        ];
        if ($onlyCache) {
            $where[] = ['href_matches' => self::paths($onlyCache, $sitePath)];
        }
        $rules = ['prefetch' => [['source' => 'document', 'where' => ['and' => $where], 'eagerness' => 'moderate']]];

        return '<script type="speculationrules">' . json_encode($rules, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) . '</script>';
    }

    /**
     * Speedy’s path patterns as URL patterns: `checkout/*` becomes `/checkout/*`, for the site’s own folder too.
     *
     * @param string[] $patterns
     * @return string[]
     */
    public static function paths(array $patterns, string $sitePath = ''): array
    {
        $sitePath = trim($sitePath, '/');
        $paths = [];
        foreach ($patterns as $pattern) {
            $pattern = trim((string)$pattern, '/');
            // Characters URL patterns read as syntax are matched as they are; `*` still matches anything.
            $escaped = preg_replace('/([\\\\:(){}+?#\[\]])/', '\\\\$1', $pattern);
            foreach (array_unique([$escaped, $sitePath !== '' ? ($escaped === '' ? $sitePath : "$sitePath/$escaped") : $escaped]) as $path) {
                $paths[] = '/' . $path;
                if ($path !== '' && !str_ends_with($path, '*')) {
                    $paths[] = "/$path/";
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Whether the page’s Content-Security-Policy lets its inline rules run. When it doesn’t, they’re left out rather
     * than blocked, so the browser doesn’t report a violation on every page.
     *
     * @param array<string, string|string[]> $headers
     */
    public static function allowedBy(array $headers): bool
    {
        $policies = [];
        foreach ($headers as $name => $value) {
            if (in_array(strtolower((string)$name), ['content-security-policy', 'content-security-policy-report-only'], true)) {
                array_push($policies, ...(array)$value);
            }
        }
        foreach ($policies as $header) {
            foreach (explode(',', (string)$header) as $policy) {
                $directives = [];
                foreach (explode(';', $policy) as $directive) {
                    $parts = preg_split('/\s+/', trim($directive)) ?: [];
                    $name = strtolower((string)array_shift($parts));
                    if ($name !== '') {
                        $directives[$name] ??= array_map('strtolower', $parts);
                    }
                }
                $sources = $directives['script-src-elem'] ?? $directives['script-src'] ?? $directives['default-src'] ?? null;
                if ($sources === null || in_array("'inline-speculation-rules'", $sources, true)) {
                    continue;
                }
                // A nonce, hash or strict-dynamic turns 'unsafe-inline' off.
                $strict = (bool)array_filter($sources, fn($s) => str_starts_with($s, "'nonce-") || str_starts_with($s, "'sha") || $s === "'strict-dynamic'");
                if (!in_array("'unsafe-inline'", $sources, true) || $strict) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function hasOwnRules(string $html): bool
    {
        return (bool)preg_match('/<script[^>]+type\s*=\s*["\']?speculationrules/i', $html);
    }
}
