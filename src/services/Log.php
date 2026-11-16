<?php
/**
 * Speedy Cache plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\speedy\services;

use craft\base\Component;
use craft\helpers\Json;
use suprim\speedy\Plugin;

/**
 * A short, readable record of what was cleared and why.
 */
class Log extends Component
{
    private const MAX = 1000;

    /**
     * @param string[] $urls
     */
    public function add(string $reason, int $count, array $urls, ?string $cdn = null): void
    {
        $entry = ['time' => time(), 'reason' => $reason, 'count' => $count, 'urls' => array_slice($urls, 0, 50), 'cdn' => $cdn];
        $storage = Plugin::getInstance()->storage;
        if ($storage->usesCache()) {
            $storage->cache()->addLog($entry);
            return;
        }
        $file = $this->file();
        @mkdir(dirname($file), 0775, true);
        // The log is a record, not something pages depend on: if it can’t be written, such as on a full disk, carry on.
        @file_put_contents($file, Json::encode($entry) . "\n", FILE_APPEND | LOCK_EX);

        clearstatcache(true, $file);
        if (@filesize($file) > 512 * 1024) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $tmp = "$file.tmp";
            $kept = implode("\n", array_slice($lines, -self::MAX)) . "\n";
            if (@file_put_contents($tmp, $kept, LOCK_EX) === strlen($kept)) {
                @rename($tmp, $file);
            } else {
                @unlink($tmp);
            }
        }
    }

    /**
     * Notes the CDN result on the latest entry.
     */
    public function noteCdn(string $result): void
    {
        $this->add('CDN: ' . $result, 0, [], $result);
    }

    /**
     * The newest entries first.
     *
     * @return array<int, array{time: int, reason: string, count: int, urls: string[], cdn: ?string}>
     */
    public function recent(int $limit = 50): array
    {
        $storage = Plugin::getInstance()->storage;
        if ($storage->usesCache()) {
            /** @var array<int, array{time: int, reason: string, count: int, urls: string[], cdn: ?string}> $entries */
            $entries = $storage->cache()->recentLog($limit);
            return $entries;
        }
        $lines = @file($this->file(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $entries = [];
        foreach (array_reverse(array_slice($lines, -$limit)) as $line) {
            $entry = Json::decodeIfJson($line);
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private function file(): string
    {
        return Plugin::getInstance()->storage->dataRoot() . '/log.jsonl';
    }
}
