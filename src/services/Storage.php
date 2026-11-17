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
use craft\helpers\FileHelper;
use craft\helpers\Json;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use suprim\speedy\models\Settings;
use suprim\speedy\Plugin;
use Throwable;

/**
 * Cached pages and their dependencies, all as files. Nothing is stored in the database.
 *
 *   <cachePath>/<host>/<path>/index.html   the page, served by the web server
 *   <dataPath>/meta/<host>/<path>.json      its URL, tags and expiry
 *   <dataPath>/tags/<ab>/<hash>.txt         pages that use a tag, one key per line
 *   <dataPath>/expiry/<hour>.txt            pages that expire in that hour
 */
class Storage extends Component
{
    /** Tag index files larger than this are rewritten without duplicate or stale lines. */
    private const COMPACT_BYTES = 262144;

    private ?CacheStore $_cacheStore = null;

    /** The folder inside a page’s folder that holds its query string versions, such as `?page=2`. */
    public const QUERY_FOLDER = '~query';

    /**
     * A page’s key, such as `example.com/news/summer-sale`, or null when the URL can’t be cached safely. Query
     * parameters, when given, make a separate page: `example.com/news/~query/page%3D2`.
     *
     * @param array<string, mixed> $query
     */
    public function key(string $host, string $path, array $query = []): ?string
    {
        $host = strtolower($host);
        // Dot-separated labels of letters, digits and hyphens: never `.` or `..`, which would leave the cache folder.
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*(:\d+)?$/', $host)) {
            return null;
        }
        $path = trim($path, '/');
        if (strlen($path) > 400 || preg_match('/[\x00-\x1f\\\\]/', $path)) {
            return null;
        }
        foreach ($path === '' ? [] : explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || $segment === self::QUERY_FOLDER) {
                return null;
            }
        }

        $key = $path === '' ? $host : "$host/$path";
        if ($query) {
            ksort($query);
            $string = http_build_query($query);
            if ($string === '' || strlen($string) > 200) {
                return null;
            }
            $folder = rawurlencode($string);
            // A folder name can’t be longer than 255 bytes.
            if (strlen($folder) > 240) {
                return null;
            }
            $key .= '/' . self::QUERY_FOLDER . '/' . $folder;
        }

        return $key;
    }

    /**
     * How many query string versions of a page are cached, such as `?page=2`.
     */
    public function countQueryPages(string $key): int
    {
        if ($this->usesCache()) {
            return $this->cache()->countQueryPages($key);
        }
        $dir = $this->cacheRoot() . '/' . $key . '/' . self::QUERY_FOLDER;

        return is_dir($dir) ? count(glob($dir . '/*', GLOB_ONLYDIR | GLOB_NOSORT) ?: []) : 0;
    }

    /**
     * The keys of a page’s query string versions, such as `example.com/news/~query/page%3D2` for `example.com/news`.
     *
     * @return string[]
     */
    public function queryPageKeys(string $key): array
    {
        if ($this->usesCache()) {
            return $this->cache()->queryPageKeys($key);
        }
        $dir = $this->cacheRoot() . '/' . $key . '/' . self::QUERY_FOLDER;

        return is_dir($dir) ? array_map(fn($d) => $key . '/' . self::QUERY_FOLDER . '/' . basename($d), glob($dir . '/*', GLOB_ONLYDIR | GLOB_NOSORT) ?: []) : [];
    }

    /**
     * The key of a page by its full URL, such as one from the log or the API.
     */
    public function keyForUrl(string $url): ?string
    {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        unset($query[Cacher::WARM_PARAM]);

        // Keys are made from the decoded path, as Craft gives it, so `/caf%C3%A9` is the page `café`.
        return $this->key($parts['host'] ?? '', rawurldecode($parts['path'] ?? ''), array_map('strval', array_filter($query, 'is_scalar')));
    }

    public function htmlFile(string $key): string
    {
        return $this->cacheRoot() . '/' . $key . '/index.html';
    }

    public function exists(string $key): bool
    {
        if ($this->usesCache()) {
            return $this->cache()->exists($key);
        }

        return is_file($this->htmlFile($key));
    }

    /**
     * @return array{url: string, tags: string[], expires: ?int, created: int, headers?: array<string, string>}|null
     */
    public function meta(string $key): ?array
    {
        if ($this->usesCache()) {
            return $this->cache()->meta($key);
        }

        $file = $this->metaFile($key);
        if (!is_file($file)) {
            return null;
        }
        $meta = Json::decodeIfJson((string)@file_get_contents($file));

        return is_array($meta) ? $meta : null;
    }

    /**
     * Saves a page and records what it depends on.
     *
     * @param string[] $tags
     * @param array<string, string> $headers Response headers to send again when PHP serves the page.
     */
    public function write(string $key, string $html, string $url, array $tags, ?int $expires, array $headers = []): void
    {
        $meta = ['url' => $url, 'tags' => array_values($tags), 'expires' => $expires, 'created' => time(), 'headers' => $headers];
        if ($this->usesCache()) {
            $this->cache()->write($key, $html, $meta, $tags, $expires);
            return;
        }

        if ($this->unsafeFolder() !== null) {
            return;
        }
        $file = $this->htmlFile($key);
        try {
            $this->protectCacheFolder();
            // Compressed copies, so the web server (gzip_static) and PHP send them without compressing each time.
            $this->atomicWrite("$file.gz", (string)gzencode($html, 9));
            if (function_exists('brotli_compress')) {
                $this->atomicWrite("$file.br", (string)brotli_compress($html, 11));
            }
            $this->atomicWrite($file, $html);
            $this->atomicWrite($this->metaFile($key), Json::encode($meta));

            foreach ($tags as $tag) {
                $this->appendLine($this->tagFile($tag), $key);
            }
            if ($expires !== null) {
                $this->appendLine($this->dataRoot() . '/expiry/' . intdiv($expires, 3600) . '.txt', $key);
            }
        } catch (Throwable $e) {
            // Such as a full disk. A page that’s only partly saved, or wouldn’t clear when its content changes,
            // mustn’t be sent: take it out, so the next visitor gets a page built for them.
            foreach (["$file.gz", "$file.br", $file, $this->metaFile($key)] as $part) {
                @unlink($part);
            }
            throw $e;
        }
    }

    /** Below this much free space, in bytes, new pages aren’t saved, so the cache never fills the disk. */
    public const MIN_FREE_BYTES = 256 * 1024 * 1024;

    /**
     * Free space on the cache folder’s disk, in bytes, checked at most once a minute. Null when it can’t be told.
     */
    public function freeSpace(): ?int
    {
        if ($this->usesCache()) {
            return null;
        }

        $free = Craft::$app->getCache()->getOrSet('speedy:freeSpace', function() {
            $dir = $this->cacheRoot();
            while (!is_dir($dir) && dirname($dir) !== $dir) {
                $dir = dirname($dir);
            }
            // Some hosts disable disk_free_space(); calling it then throws, which @ doesn’t stop.
            if (!function_exists('disk_free_space')) {
                return -1;
            }
            try {
                $free = @disk_free_space($dir);
            } catch (Throwable) {
                return -1;
            }

            return $free === false ? -1 : (int)$free;
        }, 60);

        return $free >= 0 ? (int)$free : null;
    }

    /**
     * Whether cached pages use the space allowed by `maxCacheSize`, going by the page count kept for five minutes.
     */
    public function overSizeLimit(): bool
    {
        $limit = Plugin::getInstance()->getSettings()->maxCacheSize;

        return $limit !== null && $limit > 0 && $this->cachedStats()['bytes'] >= $limit * 1024 * 1024;
    }

    /**
     * Whether the disk is nearly full, so new pages shouldn’t be saved.
     */
    public function diskFull(): bool
    {
        $free = $this->freeSpace();

        return $free !== null && $free < self::MIN_FREE_BYTES;
    }

    /**
     * Removes a page. Returns its URL, or null when it wasn’t cached.
     */
    public function delete(string $key): ?string
    {
        if ($this->usesCache()) {
            return $this->cache()->delete($key);
        }

        $meta = $this->meta($key);
        $file = $this->htmlFile($key);
        @unlink($file);
        @unlink("$file.gz");
        @unlink("$file.br");
        @unlink($this->metaFile($key));
        $this->removeEmptyFolders(dirname($file), $this->cacheRoot());
        $this->removeEmptyFolders(dirname($this->metaFile($key)), $this->dataRoot() . '/meta');

        return $meta['url'] ?? null;
    }

    /**
     * The pages that use any of the tags. Each tag’s index is removed, as its pages are about to be cleared.
     *
     * @param string[] $tags
     * @return string[] Page keys
     */
    public function takePagesForTags(array $tags): array
    {
        if ($this->usesCache()) {
            return $this->cache()->takePagesForTags($tags);
        }

        $keys = [];
        foreach (array_unique($tags) as $tag) {
            $file = $this->tagFile($tag);
            if (!is_file($file)) {
                continue;
            }
            // Read and remove it in one go, so a page added meanwhile isn’t lost.
            foreach ($this->withListLock($file, function() use ($file) {
                $lines = $this->readLines($file);
                @unlink($file);
                @unlink("$file.size");
                return $lines;
            }) as $key) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * How many cached pages use a tag.
     */
    public function countPagesForTag(string $tag): int
    {
        if ($this->usesCache()) {
            return $this->cache()->countPagesForTag($tag);
        }

        $file = $this->tagFile($tag);
        if (!is_file($file)) {
            return 0;
        }

        return count(array_filter(array_unique($this->readLines($file)), fn($k) => is_file($this->metaFile($k))));
    }

    /**
     * Pages whose expiry has passed.
     *
     * @return string[]
     */
    public function takeExpired(int $now): array
    {
        if ($this->usesCache()) {
            return $this->cache()->takeExpired($now);
        }

        $dir = $this->dataRoot() . '/expiry';
        if (!is_dir($dir)) {
            return [];
        }
        $keys = [];
        $hour = intdiv($now, 3600);
        foreach (glob($dir . '/*.txt') ?: [] as $file) {
            $bucket = (int)basename($file, '.txt');
            if ($bucket > $hour) {
                continue;
            }
            $this->withListLock($file, function() use ($file, $now, &$keys) {
                $keep = [];
                foreach ($this->readLines($file) as $key) {
                    $expires = $this->meta($key)['expires'] ?? null;
                    if ($expires === null) {
                        continue;
                    }
                    if ($expires <= $now) {
                        $keys[$key] = true;
                    } else {
                        $keep[] = $key;
                    }
                }
                // Through a copy, so a full disk can’t empty the list.
                if ($keep) {
                    $this->atomicWrite($file, implode("\n", $keep) . "\n");
                } else {
                    @unlink($file);
                    @unlink("$file.size");
                }
            });
        }

        return array_keys($keys);
    }

    /**
     * The earliest expiry still waiting, or null.
     */
    public function nextExpiry(): ?int
    {
        if ($this->usesCache()) {
            return $this->cache()->nextExpiry();
        }

        $files = glob($this->dataRoot() . '/expiry/*.txt') ?: [];
        if (!$files) {
            return null;
        }
        $hours = array_map(fn($f) => (int)basename($f, '.txt'), $files);

        return min($hours) * 3600;
    }

    /**
     * Removes every cached page and index.
     */
    public function clearAll(): void
    {
        Craft::$app->getCache()->delete($this->statsCacheKey());
        if ($this->usesCache()) {
            $this->cache()->clearAll();
            return;
        }
        // Never move away a folder that isn't Speedy's, such as the web root.
        if ($this->unsafeFolder() !== null) {
            return;
        }

        // Moving a folder is instant however many pages it holds, so clearing never times out half-way.
        // The old files are deleted afterwards, by deleteTrash().
        $moved = false;
        foreach ([$this->cacheRoot(), $this->dataRoot() . '/meta', $this->dataRoot() . '/tags', $this->dataRoot() . '/expiry'] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $trash = $this->dataRoot() . '/trash/' . bin2hex(random_bytes(6));
            FileHelper::createDirectory(dirname($trash));
            if (@rename($dir, $trash)) {
                $moved = true;
            } else {
                // A different disk, such as a cache folder on shared storage: delete it now instead.
                FileHelper::removeDirectory($dir);
            }
        }
        if ($moved) {
            \craft\helpers\Queue::push(new \suprim\speedy\jobs\DeleteTrashJob(), 2048);
        }
    }

    /**
     * Deletes folders moved aside by clearAll().
     */
    public function deleteTrash(): void
    {
        if (!self::isOwnFolder($this->dataRoot())) {
            return;
        }
        $dir = $this->dataRoot() . '/trash';
        if (is_dir($dir)) {
            FileHelper::removeDirectory($dir);
        }
    }

    /**
     * Deletes cleared pages that have waited in the trash longer than this many seconds, for when the queue job that
     * deletes them hasn’t run: every “Clear all” would otherwise leave another full copy of the cache on the disk.
     */
    public function deleteOldTrash(int $age = 600): void
    {
        if (!self::isOwnFolder($this->dataRoot())) {
            return;
        }
        foreach (glob($this->dataRoot() . '/trash/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if ((int)@filemtime($dir) < time() - $age) {
                try {
                    FileHelper::removeDirectory($dir);
                } catch (\Throwable $e) {
                    // The queue job may be deleting it at the same moment; it's tried again next time.
                    Craft::warning("Couldn’t delete $dir: " . $e->getMessage(), 'speedy');
                }
            }
        }
    }

    /**
     * A lock file for building a page, so visitors who arrive together don’t all build it.
     */
    public function lockFile(string $key): string
    {
        $file = $this->dataRoot() . '/locks/' . md5($key) . '.lock';
        FileHelper::createDirectory(dirname($file));

        return $file;
    }

    /**
     * Removes build lock files nobody has used for an hour, so one per address ever requested doesn’t pile up.
     */
    public function pruneLocks(): void
    {
        if (!Craft::$app->getCache()->add('speedy:prunedLocks', true, 3600)) {
            return;
        }
        $dir = $this->dataRoot() . '/locks';
        $old = time() - 3600;
        foreach (glob("$dir/*.lock", GLOB_NOSORT) ?: [] as $file) {
            if (@filemtime($file) >= $old) {
                continue;
            }
            $handle = @fopen($file, 'c');
            // Only if no request holds it.
            if ($handle !== false && flock($handle, LOCK_EX | LOCK_NB)) {
                @unlink($file);
                flock($handle, LOCK_UN);
            }
            if ($handle !== false) {
                fclose($handle);
            }
        }
    }

    /**
     * Every cached page’s key.
     *
     * @return iterable<string>
     */
    public function allKeys(): iterable
    {
        if ($this->usesCache()) {
            yield from $this->cache()->allKeys();
            return;
        }

        $root = $this->dataRoot() . '/meta';
        if (!is_dir($root)) {
            return;
        }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() === 'json') {
                $relative = substr($file->getPathname(), strlen($root) + 1, -5);
                yield preg_replace('#/_index$#', '', $relative);
            }
        }
    }

    private const STATS_CACHE = 'speedy:stats';

    /**
     * How many pages are cached and their size, counted at most every five minutes. Counting reads the whole cache
     * folder, which takes seconds on a site with a hundred thousand pages, so the control panel uses this.
     *
     * @return array{pages: int, bytes: int}
     */
    public function cachedStats(): array
    {
        $cache = Craft::$app->getCache();
        $stats = $cache->get($this->statsCacheKey());
        if (!is_array($stats) || !isset($stats['pages'], $stats['bytes'])) {
            $stats = $this->stats();
            $cache->set($this->statsCacheKey(), $stats, 300);
        }

        return ['pages' => (int)$stats['pages'], 'bytes' => (int)$stats['bytes']];
    }

    private function statsCacheKey(): string
    {
        return self::STATS_CACHE . ':' . md5($this->usesCache() ? 'craftCache' : $this->cacheRoot());
    }

    /**
     * How many pages are cached and their size on disk.
     *
     * @return array{pages: int, bytes: int}
     */
    public function stats(): array
    {
        if ($this->usesCache()) {
            return $this->cache()->stats();
        }

        $pages = 0;
        $bytes = 0;
        $root = $this->cacheRoot();
        if (is_dir($root)) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getFilename() === 'index.html') {
                    $pages++;
                    $bytes += $file->getSize();
                }
            }
        }

        return ['pages' => $pages, 'bytes' => $bytes];
    }

    /**
     * The page to send, compressed when the visitor accepts gzip and a compressed copy exists.
     *
     * @return array{content: string, gzip: bool}|null
     */
    public function read(string $key, bool $gzip): ?array
    {
        if ($this->usesCache()) {
            return $this->cache()->read($key, $gzip);
        }
        $file = $this->htmlFile($key);
        if ($gzip && is_file("$file.gz")) {
            return ['content' => (string)file_get_contents("$file.gz"), 'gzip' => true];
        }
        if (!is_file($file)) {
            return null;
        }

        return ['content' => (string)file_get_contents($file), 'gzip' => false];
    }

    /**
     * Whether pages are kept in Craft’s cache, such as Redis, rather than in files.
     */
    public function usesCache(): bool
    {
        return Plugin::getInstance()->getSettings()->storage === Settings::STORAGE_CRAFT_CACHE;
    }

    public function cache(): CacheStore
    {
        return $this->_cacheStore ??= new CacheStore();
    }

    /**
     * The cachePath or dataPath set to a folder that isn’t Speedy’s own, such as the web root or Craft’s storage
     * folder, or null when both are fine. Speedy clears its folders whole and keeps them out of git, so it only ever
     * does that to a folder of its own.
     */
    public function unsafeFolder(): ?string
    {
        $folders = [$this->dataRoot()];
        if (!$this->usesCache()) {
            $folders[] = $this->cacheRoot();
        }
        foreach ($folders as $folder) {
            if (!self::isOwnFolder($folder)) {
                return $folder;
            }
        }

        return null;
    }

    /**
     * Whether a folder can be Speedy's: not one of Craft's own, such as the web root, nor a folder that holds one.
     */
    public static function isOwnFolder(string $folder): bool
    {
        $folder = rtrim(FileHelper::normalizePath($folder), '/');
        if ($folder === '' || $folder === '.' || substr_count($folder, '/') < 1) {
            return false;
        }
        foreach (['@root', '@webroot', '@storage', '@vendor', '@templates', '@config', '@contentMigrations', '@translations'] as $alias) {
            $path = Craft::getAlias($alias, false);
            if (!is_string($path) || $path === '') {
                continue;
            }
            $path = rtrim(FileHelper::normalizePath($path), '/');
            if ($folder === $path || str_starts_with($path . '/', $folder . '/')) {
                return false;
            }
        }

        return true;
    }

    public function cacheRoot(): string
    {
        return rtrim(FileHelper::normalizePath(Craft::getAlias(Plugin::getInstance()->getSettings()->cachePath)), '/');
    }

    public function dataRoot(): string
    {
        return rtrim(FileHelper::normalizePath(Craft::getAlias(Plugin::getInstance()->getSettings()->dataPath)), '/');
    }

    /**
     * Reads a small JSON file from the data folder, such as the CDN details.
     */
    public function readData(string $name): array
    {
        if ($this->usesCache()) {
            return $this->cache()->readData($name);
        }

        $file = $this->dataRoot() . "/$name.json";
        $data = is_file($file) ? Json::decodeIfJson((string)@file_get_contents($file)) : null;

        return is_array($data) ? $data : [];
    }

    public function writeData(string $name, array $data): void
    {
        if ($this->usesCache()) {
            $this->cache()->writeData($name, $data);
            return;
        }

        $this->atomicWrite($this->dataRoot() . "/$name.json", Json::encode($data));
    }

    private function metaFile(string $key): string
    {
        // A host-only key (the homepage) would clash with its folder, so it gets its own name.
        $path = str_contains($key, '/') ? $key : "$key/_index";

        return $this->dataRoot() . '/meta/' . $path . '.json';
    }

    private function tagFile(string $tag): string
    {
        $hash = md5($tag);

        return $this->dataRoot() . '/tags/' . substr($hash, 0, 2) . "/$hash.txt";
    }

    private function appendLine(string $file, string $line): void
    {
        FileHelper::createDirectory(dirname($file));
        $this->withListLock($file, function() use ($file, $line) {
            $handle = @fopen($file, 'ab');
            if ($handle === false) {
                throw new \RuntimeException("Couldn’t open $file.");
            }
            try {
                clearstatcache(true, $file);
                $size = (int)@filesize($file);
                $line .= "\n";
                if (@fwrite($handle, $line) !== strlen($line)) {
                    // A full disk can stop part-way through the line: take it back off, so the next line isn’t joined to it.
                    @ftruncate($handle, $size);
                    throw new \RuntimeException("Couldn’t write to $file. Is the disk full?");
                }
            } finally {
                fclose($handle);
            }

            clearstatcache(true, $file);
            $size = (int)@filesize($file);
            // Tidied again only once it has doubled since it was last tidied: a list of many pages that are all
            // still cached stays big, and tidying it on every save would get slower with every page.
            $tidied = (int)@file_get_contents("$file.size");
            if ($size > self::COMPACT_BYTES && $size > 2 * $tidied) {
                // Keep each page once, and only pages that are still cached. Written to a copy first, so a full disk
                // can’t leave the list empty.
                $keep = array_filter(array_unique($this->readLines($file)), fn($k) => is_file($this->metaFile($k)));
                $contents = $keep ? implode("\n", $keep) . "\n" : '';
                $this->atomicWrite($file, $contents);
                @file_put_contents("$file.size", (string)strlen($contents));
            }
        }, true);
    }

    /**
     * Runs $fn while no other request adds to, compacts or takes the list in $file. A lock file per folder, as a
     * compacted list replaces the file and a lock on the file itself would go with it.
     */
    private function withListLock(string $file, callable $fn, bool $required = false): mixed
    {
        $lock = @fopen(dirname($file) . '/.lock', 'c');
        $locked = false;
        if ($lock !== false) {
            // Up to five seconds: a lock that never comes, such as on some network drives, mustn’t hang the request.
            $until = microtime(true) + 5;
            while (!($locked = flock($lock, LOCK_EX | LOCK_NB)) && microtime(true) < $until) {
                usleep(20000);
            }
        }
        if (!$locked && $required) {
            if ($lock !== false) {
                fclose($lock);
            }
            throw new \RuntimeException("Couldn’t lock the list $file.");
        }
        try {
            return $fn();
        } finally {
            if ($lock !== false) {
                if ($locked) {
                    flock($lock, LOCK_UN);
                }
                fclose($lock);
            }
        }
    }

    /**
     * @return string[]
     */
    private function readLines(string $file): array
    {
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return is_array($lines) ? $lines : [];
    }

    /**
     * Removes the folder and its parents while they’re empty, up to the root, so cleared pages leave nothing behind.
     * A folder that still holds another page, or gets one meanwhile, is left alone.
     */
    private function removeEmptyFolders(string $dir, string $root): void
    {
        while (str_starts_with($dir, $root . '/') && @rmdir($dir)) {
            $dir = dirname($dir);
        }
    }

    /**
     * Stops visitors opening the cache folder directly on Apache, while the server rules still send pages from it.
     * Only rewrites are used, which every Craft site already allows in .htaccess files. Nginx ignores the file; its
     * rules mark the folder `internal`.
     */
    private function protectCacheFolder(): void
    {
        // Cached pages are built on each server, so they're kept out of git: `git add .` mustn't commit them.
        $ignore = $this->cacheRoot() . '/.gitignore';
        if (!is_file($ignore)) {
            $this->atomicWrite($ignore, "# Speedy Cache: pages here are built on each server. Don’t commit or deploy them.\n*\n");
        }
        $file = $this->cacheRoot() . '/.htaccess';
        if (is_file($file)) {
            return;
        }
        $this->atomicWrite($file, <<<APACHE
# Speedy Cache: pages here are only sent through the site’s rewrite rules, not opened directly.
# Rewrite rules here replace the site’s for this folder, so compressed pages are marked here too.
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{ENV:REDIRECT_STATUS} ^$
    RewriteRule ^ - [F]
    RewriteRule \.html\.gz$ - [T=text/html,E=no-gzip:1,E=SPEEDY_GZ:1,L]
</IfModule>

APACHE);
    }

    private function atomicWrite(string $file, string $contents): void
    {
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        try {
            // A folder can be removed by a page being cleared at the same moment, so make it again if so.
            FileHelper::createDirectory(dirname($file));
            $written = @file_put_contents($tmp, $contents);
            if ($written === false) {
                FileHelper::createDirectory(dirname($file));
                $written = @file_put_contents($tmp, $contents);
            }
            // A full disk can write part of the file and stop: never put that in place.
            if ($written !== strlen($contents)) {
                throw new \RuntimeException("Couldn’t write $file. Is the disk full?");
            }
            rename($tmp, $file);
        } catch (Throwable $e) {
            @unlink($tmp);
            throw $e;
        }
    }
}
