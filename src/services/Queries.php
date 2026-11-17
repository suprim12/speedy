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
use craft\base\ElementInterface;
use craft\elements\db\ElementQuery;
use craft\events\CancelableEvent;
use suprim\speedy\db\CountedCommand;
use suprim\speedy\Plugin;
use yii\base\Event;
use yii\log\Logger;
use yii\log\Target;

/**
 * Counts the database queries each page runs while Speedy rebuilds it in the background, and finds the same query
 * run again and again, such as a related field read inside a loop (an N+1 query). Visitors’ requests aren’t counted.
 */
class Queries extends Component
{
    /** A query run this many times on one page counts as repeated. */
    public const REPEATED = 10;

    /** How many pages the report keeps, the worst first. */
    private const KEEP = 100;

    /**
     * @var array<string, array{count: int, example: string, at: array<string, int>}>|null The queries run so far, grouped
     * by their shape as they run, so a page with thousands of queries doesn’t keep each one. Null when not counting.
     */
    private ?array $_groups = null;

    private int $_count = 0;

    /** @var array{driver: string, class: mixed}|null Craft’s command class, while Speedy’s counts in its place. */
    private ?array $_command = null;

    private bool $_profiling = false;

    /** How many of the slowest rebuilt pages are kept. */
    private const SLOWEST = 20;

    /** Rebuilds faster than this, in milliseconds, aren’t listed as slow. */
    public const SLOW_MS = 500;

    /**
     * @var array<string, array{type: string, at: ?string, count: int}> Element queries without a section or group,
     * which make the page clear whenever any element of their type is saved, by type and where they ran.
     */
    private array $_broad = [];

    /** @var callable|null */
    private $_onPrepare = null;

    /**
     * Starts counting this request’s queries.
     */
    public function start(): void
    {
        if ($this->_groups !== null || !Plugin::getInstance()->getSettings()->queryReport) {
            return;
        }
        $this->_groups = [];
        $this->_count = 0;
        $this->_broad = [];
        // Queries without a section, such as `craft.entries.relatedTo(…)`, clear the page whenever any entry is
        // saved. Note where each one ran, so the report can say which line to narrow.
        $this->_onPrepare = function(CancelableEvent $event) {
            if ($this->_groups !== null && $event->sender instanceof ElementQuery) {
                $this->noteBroad($event->sender);
            }
        };
        Event::on(ElementQuery::class, ElementQuery::EVENT_BEFORE_PREPARE, $this->_onPrepare);
        $db = Craft::$app->getDb();
        $driver = $db->getDriverName();
        if (($db->commandMap[$driver] ?? null) === \craft\db\Command::class) {
            // Craft’s own command, counting each query and where it was run from. Nothing else changes.
            $this->_command = ['driver' => $driver, 'class' => $db->commandMap[$driver]];
            $db->commandMap[$driver] = CountedCommand::class;
            return;
        }

        // Another plugin has its own command class: count the queries Yii profiles instead, without where they ran.
        $logger = Craft::getLogger();
        // Messages logged before now aren’t this page’s.
        $logger->flush();
        $this->_profiling = $db->enableProfiling;
        $db->enableProfiling = true;
        $logger->dispatcher->targets['speedy-queries'] = new class(fn(string $sql) => $this->note($sql)) extends Target {
            /** @var callable */
            private $note;

            public function __construct(callable $note)
            {
                $this->note = $note;
                parent::__construct();
            }

            public function collect($messages, $final): void
            {
                foreach ($messages as $message) {
                    if (($message[2] ?? null) === 'yii\db\Command::query' && ($message[1] ?? null) === Logger::LEVEL_PROFILE_BEGIN) {
                        ($this->note)((string)$message[0]);
                    }
                }
            }

            public function export(): void
            {
            }
        };
    }

    /**
     * Notes a query, with the calls that led to it.
     *
     * @param array<int, array<string, mixed>> $traces
     */
    public function note(string $sql, array $traces = []): void
    {
        if ($this->_groups !== null) {
            $this->_count++;
            $this->group($this->_groups, $sql, $traces ? $this->templateLine($traces) : null);
        }
    }

    /**
     * Stops counting and notes the page in the report.
     */
    public function finish(string $url): void
    {
        if ($this->_groups === null) {
            return;
        }
        $db = Craft::$app->getDb();
        if ($this->_command !== null) {
            $db->commandMap[$this->_command['driver']] = $this->_command['class'];
            $this->_command = null;
        } else {
            $logger = Craft::getLogger();
            $logger->flush();
            unset($logger->dispatcher->targets['speedy-queries']);
            $db->enableProfiling = $this->_profiling;
        }
        if ($this->_onPrepare !== null) {
            Event::off(ElementQuery::class, ElementQuery::EVENT_BEFORE_PREPARE, $this->_onPrepare);
            $this->_onPrepare = null;
        }
        $groups = $this->_groups;
        $this->_groups = null;
        $broad = array_values($this->_broad);
        $this->_broad = [];
        $start = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
        $ms = $start ? (int)round((microtime(true) - (float)$start) * 1000) : null;
        $result = $this->summarise($groups, $this->_count) + ['ms' => $ms, 'broad' => $broad];
        $this->save($url, $result);
        if ($ms !== null) {
            $this->saveSlow($url, $ms, $result['queries']);
        }
    }

    /**
     * Notes a query whose cache tags cover every element of its type, with the template line that ran it.
     */
    private function noteBroad(ElementQuery $query): void
    {
        try {
            $tags = $query->getCacheTags();
        } catch (\Throwable) {
            return;
        }
        foreach ($tags as $tag) {
            if (!preg_match('/^element::(.+)::\*$/', (string)$tag, $m)) {
                continue;
            }
            $at = $this->templateLine(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40));
            // Only queries a template runs: Craft’s and plugins’ own aren’t the site builder’s to change.
            if ($at === null) {
                return;
            }
            $class = $m[1];
            $type = class_exists($class) && is_subclass_of($class, ElementInterface::class) ? $class::pluralDisplayName() : $class;
            $id = "$class|$at";
            $this->_broad[$id] ??= ['type' => $type, 'at' => $at, 'count' => 0];
            $this->_broad[$id]['count']++;
            return;
        }
    }

    /**
     * Keeps the slowest rebuilt pages, with how long they took. Only written when the list changes.
     */
    private function saveSlow(string $url, int $ms, int $queries): void
    {
        $storage = Plugin::getInstance()->storage;
        $slow = $storage->readData('slow');
        $listed = isset($slow[$url]);
        $fastest = $slow ? min(array_column($slow, 'ms')) : 0;
        if (!$listed && ($ms < self::SLOW_MS || (count($slow) >= self::SLOWEST && $ms <= $fastest))) {
            return;
        }
        $mutex = Craft::$app->getMutex();
        if (!$mutex->acquire('speedy:slow', 1)) {
            return;
        }
        try {
            $slow = $storage->readData('slow');
            unset($slow[$url]);
            if ($ms >= self::SLOW_MS) {
                $slow[$url] = ['url' => $url, 'ms' => $ms, 'queries' => $queries, 'time' => time()];
            }
            uasort($slow, fn($a, $b) => $b['ms'] <=> $a['ms']);
            $storage->writeData('slow', array_slice($slow, 0, self::SLOWEST, true));
        } finally {
            $mutex->release('speedy:slow');
        }
    }

    /**
     * The slowest pages rebuilt lately, the slowest first.
     *
     * @return array<int, array{url: string, ms: int, queries: int, time: int}>
     */
    public function slowest(): array
    {
        $slow = array_values(array_filter(Plugin::getInstance()->storage->readData('slow'), fn($p) => is_array($p) && isset($p['ms'], $p['url'])));
        usort($slow, fn($a, $b) => $b['ms'] <=> $a['ms']);

        return $slow;
    }

    /**
     * Pages with queries that have no section or group, so they clear whenever any element of that type is saved.
     *
     * @return array<int, array{url: string, time: int, broad: array<int, array{type: string, at: ?string, count: int}>}>
     */
    public function broad(): array
    {
        $pages = Plugin::getInstance()->storage->readData('queries');

        return array_values(array_filter($pages, fn($p) => is_array($p) && !empty($p['broad'])));
    }

    /**
     * The queries’ count and the ones repeated most, with where they ran and a tip for each.
     *
     * @param array<int, array{sql: string, at: ?string}|string> $queries
     * @return array{queries: int, repeated: array<int, array{count: int, sql: string, at: ?string, tip: string}>}
     */
    public function analyse(array $queries): array
    {
        $groups = [];
        foreach ($queries as $query) {
            $query = is_string($query) ? ['sql' => $query, 'at' => null] : $query;
            $this->group($groups, $query['sql'], $query['at']);
        }

        return $this->summarise($groups, count($queries));
    }

    /**
     * Adds a query to its group.
     *
     * @param array<string, array{count: int, example: string, at: array<string, int>}> $groups
     */
    private function group(array &$groups, string $sql, ?string $at): void
    {
        $shape = $this->shape($sql);
        // One example of each, kept short: only its tables are read.
        $groups[$shape] ??= ['count' => 0, 'example' => substr($sql, 0, 4000), 'at' => []];
        $groups[$shape]['count']++;
        if ($at !== null) {
            $groups[$shape]['at'][$at] = ($groups[$shape]['at'][$at] ?? 0) + 1;
        }
    }

    /**
     * @param array<string, array{count: int, example: string, at: array<string, int>}> $groups
     * @return array{queries: int, repeated: array<int, array{count: int, sql: string, at: ?string, tip: string}>}
     */
    private function summarise(array $groups, int $count): array
    {
        $repeated = [];
        foreach ($groups as $shape => $group) {
            if ($group['count'] >= self::REPEATED) {
                arsort($group['at']);
                $at = array_key_first($group['at']);
                // From FROM on, as the list of columns says little.
                $from = stripos($shape, ' FROM ');
                $sql = $from !== false ? substr($shape, $from + 1) : $shape;
                $repeated[] = ['count' => $group['count'], 'sql' => mb_strimwidth($sql, 0, 300, '…'), 'at' => $at, 'tip' => $this->tip($group['example'], $at)];
            }
        }
        usort($repeated, fn($a, $b) => $b['count'] <=> $a['count']);

        return ['queries' => $count, 'repeated' => array_slice($repeated, 0, 5)];
    }

    /**
     * Pages with repeated queries, the worst first, and when each was checked.
     *
     * @return array<int, array{url: string, time: int, queries: int, ms: ?int, repeated: array<int, array{count: int, sql: string, at: ?string, tip: string}>}>
     */
    public function report(): array
    {
        $pages = Plugin::getInstance()->storage->readData('queries');
        $pages = array_values(array_filter($pages, fn($p) => is_array($p) && !empty($p['repeated'])));
        usort($pages, fn($a, $b) => $this->repeats($b) <=> $this->repeats($a));

        return $pages;
    }

    /**
     * Forgets every page in the report.
     */
    public function clear(): void
    {
        Plugin::getInstance()->storage->writeData('queries', []);
        Plugin::getInstance()->storage->writeData('slow', []);
    }

    /**
     * @param array{queries: int, repeated: array<int, array{count: int, sql: string, at: ?string, tip: string}>, ms: ?int, broad: array<int, array{type: string, at: ?string, count: int}>} $result
     */
    private function save(string $url, array $result): void
    {
        // Most pages have no repeated queries and aren’t on the report: nothing to write.
        $worth = $result['repeated'] || !empty($result['broad']);
        if (!$worth && !array_key_exists($url, Plugin::getInstance()->storage->readData('queries'))) {
            return;
        }
        $mutex = Craft::$app->getMutex();
        // Another rebuild is saving: skip this time rather than hold up the request. It’s checked again next rebuild.
        if (!$mutex->acquire('speedy:queries', 1)) {
            return;
        }
        try {
            $storage = Plugin::getInstance()->storage;
            $pages = $storage->readData('queries');
            unset($pages[$url]);
            // Only pages with repeated queries, or queries without a section, are worth keeping.
            if ($worth) {
                $pages[$url] = ['url' => $url, 'time' => time()] + $result;
                uasort($pages, fn($a, $b) => $this->repeats($b) <=> $this->repeats($a));
                $pages = array_slice($pages, 0, self::KEEP, true);
            }
            $storage->writeData('queries', $pages);
        } finally {
            $mutex->release('speedy:queries');
        }
    }

    /**
     * @param array<string, mixed> $page
     */
    private function repeats(array $page): int
    {
        return array_sum(array_column((array)($page['repeated'] ?? []), 'count'));
    }

    /**
     * The query with its values taken out, so the same query for different elements looks the same.
     */
    public function shape(string $sql): string
    {
        $sql = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/s", '?', $sql) ?? $sql;
        $sql = preg_replace('/\b\d+(\.\d+)?\b/', '?', $sql) ?? $sql;
        $sql = preg_replace('/\(\s*\?(\s*,\s*\?)*\s*\)/', '(?)', $sql) ?? $sql;

        return trim(preg_replace('/\s+/', ' ', $sql) ?? $sql);
    }

    /**
     * What to do about a repeated query, naming what it fetches when the query shows it.
     */
    private function tip(string $sql, ?string $at): string
    {
        $types = [
            'commerce_variants' => 'Variants', 'commerce_products' => 'Products', 'categories' => 'Categories', 'assets' => 'Assets',
            'users' => 'Users', 'tags' => 'Tags', 'addresses' => 'Addresses', 'globalsets' => 'Global sets', 'entries' => 'Entries',
        ];
        $type = null;
        foreach ($types as $table => $name) {
            if (preg_match('/JOIN [`"\[]?(?:\w+_)?' . $table . '[`"\]]? /i', $sql)) {
                $type = $name;
                break;
            }
        }
        $where = $at !== null ? ' ' . Craft::t('speedy', 'at {place}', ['place' => $at]) : '';
        if ($type !== null) {
            return Craft::t('speedy', '{type} are fetched one item at a time inside a loop{where}. Eager-load them with .with() on the query that loops, or add .eagerly() where they’re used.', ['type' => Craft::t('speedy', $type), 'where' => $where]);
        }

        return Craft::t('speedy', 'The same query runs again and again{where}. Run it once before the loop, or cache the result.', ['where' => $where]);
    }

    /**
     * The Twig template and line that ran a query, such as `news/_index.twig, line 12`, from the calls that led to it.
     *
     * @param array<int, array<string, mixed>> $traces
     */
    public function templateLine(array $traces): ?string
    {
        $traces = array_values($traces);
        foreach ($traces as $i => $trace) {
            $file = (string)($trace['file'] ?? '');
            if (!str_contains($file, 'compiled_templates')) {
                continue;
            }
            // The code at this frame belongs to the next frame’s class: an {% embed %} has a class of its own in the
            // same file, so the file alone can name the wrong one.
            $next = (string)($traces[$i + 1]['class'] ?? '');
            $class = str_starts_with($next, '__TwigTemplate_') ? $next : $this->templateClass($file);
            if ($class === null || !class_exists($class, false)) {
                continue;
            }
            try {
                /** @var \Twig\Template $template */
                $template = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
                $name = $template->getTemplateName();
                $line = null;
                // Twig’s map from the compiled PHP line to the template line.
                foreach ($template->getDebugInfo() as $phpLine => $twigLine) {
                    if ($phpLine <= (int)($trace['line'] ?? 0)) {
                        $line = $twigLine;
                        break;
                    }
                }
            } catch (\Throwable) {
                continue;
            }

            return $line ? Craft::t('speedy', '{template}, line {line}', ['template' => $name, 'line' => $line]) : $name;
        }

        return null;
    }

    /** @var array<string, ?string> */
    private array $_classes = [];

    private function templateClass(string $file): ?string
    {
        if (!array_key_exists($file, $this->_classes)) {
            $head = (string)@file_get_contents($file, false, null, 0, 4096);
            $this->_classes[$file] = preg_match('/class (__TwigTemplate_\w+)/', $head, $m) ? $m[1] : null;
        }

        return $this->_classes[$file];
    }
}
