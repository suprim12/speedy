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
use yii\caching\CacheInterface;

/**
 * Pages and what they depend on, kept in Craft’s cache (such as Redis) instead of files, so that several web servers
 * share them. Used by Storage when the `storage` setting is `craftCache`.
 *
 * Every entry is prefixed with a generation number, so clearing everything is instant: the number goes up, and the
 * old entries expire by themselves. Nothing is kept longer than PAGE_TTL without being written again.
 */
class CacheStore
{
    /** How long a page is kept without being rebuilt, in seconds. */
    private const PAGE_TTL = 2592000;

    /** Lists (tags, the page index) outlive their pages, so they never point at pages that are still there. */
    private const LIST_TTL = self::PAGE_TTL * 2;

    private const LOG_MAX = 1000;

    private CacheInterface $cache;

    public function __construct()
    {
        $this->cache = Craft::$app->getCache();
    }

    public function exists(string $key): bool
    {
        return $this->cache->exists($this->k('html', $key));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function meta(string $key): ?array
    {
        $meta = $this->cache->get($this->k('meta', $key));

        return is_array($meta) ? $meta : null;
    }

    /**
     * The page, compressed when the visitor accepts gzip.
     *
     * @return array{content: string, gzip: bool}|null
     */
    public function read(string $key, bool $gzip): ?array
    {
        $stored = $this->cache->get($this->k('html', $key));
        if (!is_string($stored)) {
            return null;
        }
        if ($gzip) {
            return ['content' => $stored, 'gzip' => true];
        }
        $html = gzdecode($stored);

        return $html === false ? null : ['content' => $html, 'gzip' => false];
    }

    /**
     * @param array<string, mixed> $meta
     * @param string[] $tags
     */
    public function write(string $key, string $html, array $meta, array $tags, ?int $expires): void
    {
        $gz = (string)gzencode($html, 9);
        $this->cache->set($this->k('html', $key), $gz, self::PAGE_TTL);
        $this->cache->set($this->k('meta', $key), $meta, self::PAGE_TTL);

        try {
            $this->addToList($this->k('idx', substr(md5($key), 0, 2)), $key, strlen($gz));
            if (($at = strpos($key, '/' . Storage::QUERY_FOLDER . '/')) !== false) {
                $this->addToList($this->k('qv', md5(substr($key, 0, $at))), $key, true);
            }
            foreach ($tags as $tag) {
                $this->addTag($this->k('tag', md5($tag)), $key);
            }
            if ($expires !== null) {
                $this->addToList($this->k('exp', (string)intdiv($expires, 3600)), $key, true);
            }
        } catch (\Throwable $e) {
            // A page that wouldn’t clear when its content changes mustn’t be sent.
            $this->cache->delete($this->k('html', $key));
            $this->cache->delete($this->k('meta', $key));
            throw $e;
        }
    }

    public function countQueryPages(string $key): int
    {
        return count($this->getList($this->k('qv', md5($key))));
    }

    public function delete(string $key): ?string
    {
        $url = $this->meta($key)['url'] ?? null;
        $this->cache->delete($this->k('html', $key));
        $this->cache->delete($this->k('meta', $key));
        $this->removeFromList($this->k('idx', substr(md5($key), 0, 2)), $key);
        if (($at = strpos($key, '/' . Storage::QUERY_FOLDER . '/')) !== false) {
            $this->removeFromList($this->k('qv', md5(substr($key, 0, $at))), $key);
        }

        return $url;
    }

    /**
     * @param string[] $tags
     * @return string[]
     */
    public function takePagesForTags(array $tags): array
    {
        $keys = [];
        foreach (array_unique($tags) as $tag) {
            foreach ($this->takeTag($this->k('tag', md5($tag))) as $key) {
                $keys[$key] = true;
            }
        }

        return array_map('strval', array_keys($keys));
    }

    public function countPagesForTag(string $tag): int
    {
        return count(array_filter($this->tagMembers($this->k('tag', md5($tag))), fn($k) => $this->exists($k)));
    }

    /**
     * @return string[]
     */
    public function takeExpired(int $now): array
    {
        $keys = [];
        $hour = intdiv($now, 3600);
        foreach ($this->expiryHours() as $bucket) {
            if ($bucket > $hour) {
                continue;
            }
            $name = $this->k('exp', (string)$bucket);
            $this->locked($name, function() use ($name, $now, &$keys) {
                $keep = [];
                foreach (array_keys($this->getList($name)) as $key) {
                    $expires = $this->meta((string)$key)['expires'] ?? null;
                    if ($expires === null) {
                        continue;
                    }
                    if ($expires <= $now) {
                        $keys[$key] = true;
                    } else {
                        $keep[$key] = true;
                    }
                }
                $keep ? $this->cache->set($name, $keep, self::LIST_TTL) : $this->cache->delete($name);
            }, false);
            if (!$this->cache->exists($name)) {
                $this->removeFromList($this->k('exp', 'hours'), (string)$bucket);
            }
        }

        return array_map('strval', array_keys($keys));
    }

    public function nextExpiry(): ?int
    {
        $hours = $this->expiryHours();

        return $hours ? min($hours) * 3600 : null;
    }

    public function clearAll(): void
    {
        $this->locked('speedy:gen', function() {
            $this->cache->set('speedy:gen', $this->generation() + 1);
        });
    }

    /**
     * @return iterable<string>
     */
    public function allKeys(): iterable
    {
        for ($i = 0; $i < 256; $i++) {
            $list = $this->cache->get($this->k('idx', sprintf('%02x', $i)));
            foreach (is_array($list) ? array_keys($list) : [] as $key) {
                yield (string)$key;
            }
        }
    }

    /**
     * @return array{pages: int, bytes: int}
     */
    public function stats(): array
    {
        $pages = 0;
        $bytes = 0;
        for ($i = 0; $i < 256; $i++) {
            $list = $this->cache->get($this->k('idx', sprintf('%02x', $i)));
            if (is_array($list)) {
                $pages += count($list);
                $bytes += array_sum($list);
            }
        }

        return ['pages' => $pages, 'bytes' => $bytes];
    }

    public function readData(string $name): array
    {
        $data = $this->cache->get("speedy:data:$name");

        return is_array($data) ? $data : [];
    }

    public function writeData(string $name, array $data): void
    {
        $this->cache->set("speedy:data:$name", $data);
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function addLog(array $entry): void
    {
        $this->locked('speedy:log', function() use ($entry) {
            $log = $this->getList('speedy:log');
            $log[] = $entry;
            $this->cache->set('speedy:log', array_slice($log, -self::LOG_MAX));
        }, false);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentLog(int $limit): array
    {
        return array_reverse(array_slice($this->getList('speedy:log'), -$limit));
    }

    /**
     * Takes the build lock for a page, without waiting.
     */
    public function lock(string $key): bool
    {
        return Craft::$app->getMutex()->acquire('speedy:build:' . md5($key));
    }

    public function unlock(string $key): void
    {
        Craft::$app->getMutex()->release('speedy:build:' . md5($key));
    }

    /**
     * Which kind of cache Craft uses, for the settings page: whether it’s shared and outside the database.
     */
    public static function describe(): array
    {
        $cache = Craft::$app->getCache();
        $class = get_class($cache);

        $label = match (true) {
            stripos($class, 'redis') !== false => 'Redis',
            stripos($class, 'memcache') !== false => 'Memcached',
            str_contains($class, 'DbCache') => Craft::t('speedy', 'the database'),
            str_contains($class, 'FileCache') => Craft::t('speedy', 'files on this server'),
            default => substr($class, strrpos($class, '\\') + 1),
        };

        return [
            'class' => $class,
            'label' => $label,
            'shared' => !str_contains($class, 'FileCache') && !str_contains($class, 'ApcCache') && !str_contains($class, 'ArrayCache'),
            'database' => str_contains($class, 'DbCache'),
        ];
    }

    /**
     * A stored list, or an empty one. (A cache miss is `false`, which `(array)` would turn into `[false]`.)
     *
     * @return array<mixed>
     */
    private function getList(string $name): array
    {
        $value = $this->cache->get($name);

        return is_array($value) ? $value : [];
    }

    private function generation(): int
    {
        return (int)($this->cache->get('speedy:gen') ?: 1);
    }

    private function k(string $type, string $id): string
    {
        return 'speedy:' . $this->generation() . ":$type:$id";
    }

    /**
     * @return int[]
     */
    private function expiryHours(): array
    {
        $hours = $this->getList($this->k('exp', 'hours'));

        return array_map('intval', array_keys($hours));
    }

    private function addToList(string $name, string $key, mixed $value): void
    {
        $this->locked($name, function() use ($name, $key, $value) {
            $list = $this->getList($name);
            $list[$key] = $value;
            $this->cache->set($name, $list, self::LIST_TTL);
        });
        // Expiry buckets are listed too, so takeExpired() can find them without scanning the cache.
        if (str_contains($name, ':exp:') && !str_ends_with($name, ':hours')) {
            $this->addToList($this->k('exp', 'hours'), substr($name, strrpos($name, ':') + 1), true);
        }
    }

    private function removeFromList(string $name, string $key): void
    {
        // Best effort: an entry left behind only points at a page that’s gone.
        $this->locked($name, function() use ($name, $key) {
            $list = $this->getList($name);
            unset($list[$key]);
            $list ? $this->cache->set($name, $list, self::LIST_TTL) : $this->cache->delete($name);
        }, false);
    }

    /**
     * Redis, when Craft’s cache is Redis: its sets add and take pages in one step, however many pages share a tag,
     * where a list in the cache would be read and written whole for every page.
     */
    private function redis(): ?object
    {
        if (class_exists(\yii\redis\Cache::class) && $this->cache instanceof \yii\redis\Cache) {
            return $this->cache->redis;
        }

        return null;
    }

    /**
     * Runs a Redis command, such as `SADD`.
     *
     * @param mixed[] $params
     */
    private function command(object $redis, string $name, array $params): mixed
    {
        return call_user_func([$redis, 'executeCommand'], $name, $params);
    }

    private function setKey(string $name): string
    {
        /** @var \yii\caching\Cache $cache */
        $cache = $this->cache;

        // In braces, so the set and its renamed copy are on the same Redis Cluster node.
        return $cache->keyPrefix . 'set:{' . $name . '}';
    }

    private function addTag(string $name, string $key): void
    {
        $redis = $this->redis();
        if ($redis === null) {
            $this->addToList($name, $key, true);
            return;
        }
        $set = $this->setKey($name);
        $this->command($redis, 'SADD', [$set, $key]);
        $this->command($redis, 'EXPIRE', [$set, self::LIST_TTL]);
    }

    /**
     * The pages with a tag, removing the tag: a page added at the same moment is kept for the next clear.
     *
     * @return string[]
     */
    private function takeTag(string $name): array
    {
        $redis = $this->redis();
        if ($redis === null) {
            $keys = [];
            $this->locked($name, function() use ($name, &$keys) {
                $keys = array_keys($this->getList($name));
                $this->cache->delete($name);
            });
            return array_map('strval', $keys);
        }
        // Pages cached before 1.1 are in a list in the cache, not a set.
        $legacy = $this->cache->get($name);
        if (is_array($legacy)) {
            $this->cache->delete($name);
        }
        // Renamed first, so it’s taken whole: a page added meanwhile goes into a new set.
        $set = $this->setKey($name);
        $taken = $set . ':taking:' . bin2hex(random_bytes(6));
        $keys = is_array($legacy) ? array_keys($legacy) : [];
        try {
            $this->command($redis, 'RENAME', [$set, $taken]);
            $keys = array_merge($keys, (array)$this->command($redis, 'SMEMBERS', [$taken]));
            $this->command($redis, 'DEL', [$taken]);
        } catch (\Throwable $e) {
            // No set: nothing has this tag yet. Anything else, such as a lost connection, mustn’t look like nothing to clear.
            if (stripos($e->getMessage(), 'no such key') === false) {
                throw $e;
            }
        }

        return array_values(array_unique(array_map('strval', $keys)));
    }

    /**
     * @return string[]
     */
    private function tagMembers(string $name): array
    {
        $redis = $this->redis();
        $legacy = array_map('strval', array_keys($this->getList($name)));
        if ($redis === null) {
            return $legacy;
        }

        return array_values(array_unique(array_merge($legacy, array_map('strval', (array)$this->command($redis, 'SMEMBERS', [$this->setKey($name)])))));
    }

    /**
     * Runs a read-modify-write of a shared list while holding a lock, so two servers can’t overwrite each other.
     */
    private function locked(string $name, callable $fn, bool $required = true): void
    {
        $mutex = Craft::$app->getMutex();
        $lock = 'speedy:list:' . md5($name);
        if (!$mutex->acquire($lock, 5)) {
            // Updating it anyway could lose another server’s change, and a page left off a list wouldn’t clear.
            if ($required) {
                throw new \RuntimeException("Couldn’t lock $name.");
            }
            Craft::warning("Couldn’t lock $name; left as it is.", 'speedy');
            return;
        }
        try {
            $fn();
        } finally {
            $mutex->release($lock);
        }
    }
}
