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

    /**
     * A page’s key, such as `example.com/news/summer-sale`, or null when the URL can’t be cached safely.
     */
    public function key(string $host, string $path): ?string
    {
        $host = strtolower($host);
        if (!preg_match('/^[a-z0-9.-]+(:\d+)?$/', $host)) {
            return null;
        }
        $path = trim($path, '/');
        if (strlen($path) > 400 || preg_match('/[\x00-\x1f\\\\]/', $path)) {
            return null;
        }
        foreach ($path === '' ? [] : explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $path === '' ? $host : "$host/$path";
    }

    public function htmlFile(string $key): string
    {
        return $this->cacheRoot() . '/' . $key . '/index.html';
    }

    public function exists(string $key): bool
    {
        return is_file($this->htmlFile($key));
    }

    /**
     * @return array{url: string, tags: string[], expires: ?int, created: int, headers?: array<string, string>}|null
     */
    public function meta(string $key): ?array
    {
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

        $this->atomicWrite($this->htmlFile($key), $html);
        $this->atomicWrite($this->metaFile($key), Json::encode($meta));

        foreach ($tags as $tag) {
            $this->appendLine($this->tagFile($tag), $key);
        }
        if ($expires !== null) {
            $this->appendLine($this->dataRoot() . '/expiry/' . intdiv($expires, 3600) . '.txt', $key);
        }
    }

    /**
     * Removes a page. Returns its URL, or null when it wasn’t cached.
     */
    public function delete(string $key): ?string
    {
        $meta = $this->meta($key);
        @unlink($this->htmlFile($key));
        @unlink($this->metaFile($key));

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
        $keys = [];
        foreach (array_unique($tags) as $tag) {
            $file = $this->tagFile($tag);
            if (!is_file($file)) {
                continue;
            }
            foreach ($this->readLines($file) as $key) {
                $keys[$key] = true;
            }
            @unlink($file);
        }

        return array_keys($keys);
    }

    /**
     * How many cached pages use a tag.
     */
    public function countPagesForTag(string $tag): int
    {
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
            $keep ? file_put_contents($file, implode("\n", $keep) . "\n", LOCK_EX) : @unlink($file);
        }

        return array_keys($keys);
    }

    /**
     * The earliest expiry still waiting, or null.
     */
    public function nextExpiry(): ?int
    {
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
        $dir = $this->dataRoot() . '/trash';
        if (is_dir($dir)) {
            FileHelper::removeDirectory($dir);
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
     * Every cached page’s key.
     *
     * @return iterable<string>
     */
    public function allKeys(): iterable
    {
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

    /**
     * How many pages are cached and their size on disk.
     *
     * @return array{pages: int, bytes: int}
     */
    public function stats(): array
    {
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
        $file = $this->dataRoot() . "/$name.json";
        $data = is_file($file) ? Json::decodeIfJson((string)@file_get_contents($file)) : null;

        return is_array($data) ? $data : [];
    }

    public function writeData(string $name, array $data): void
    {
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
        file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);

        clearstatcache(true, $file);
        if (@filesize($file) > self::COMPACT_BYTES) {
            // Keep each page once, and only pages that are still cached.
            $keep = array_filter(array_unique($this->readLines($file)), fn($k) => is_file($this->metaFile($k)));
            file_put_contents($file, $keep ? implode("\n", $keep) . "\n" : '', LOCK_EX);
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

    private function atomicWrite(string $file, string $contents): void
    {
        FileHelper::createDirectory(dirname($file));
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        try {
            file_put_contents($tmp, $contents);
            rename($tmp, $file);
        } catch (Throwable $e) {
            @unlink($tmp);
            throw $e;
        }
    }
}
