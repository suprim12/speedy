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
use suprim\speedy\Plugin;
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

    /** How many calls to note with each query, to find the template that ran it. */
    private const TRACE = 60;

    /** @var array<int, array{sql: string, at: ?string}>|null The queries run so far, while counting. */
    private ?array $_sql = null;

    private int $_traceLevel = 0;

    private bool $_profiling = false;

    /**
     * Starts counting this request’s queries.
     */
    public function start(): void
    {
        if ($this->_sql !== null || !Plugin::getInstance()->getSettings()->queryReport) {
            return;
        }
        $this->_sql = [];
        $this->_profiling = Craft::$app->getDb()->enableProfiling;
        Craft::$app->getDb()->enableProfiling = true;
        // With the calls that led to each query, the template and line that ran it can be named.
        $logger = Craft::getLogger();
        // Messages logged before now aren’t this page’s.
        $logger->flush();
        $this->_traceLevel = $logger->traceLevel;
        $logger->traceLevel = max($logger->traceLevel, self::TRACE);
        $note = function(string $sql, array $traces) {
            $this->_sql[] = ['sql' => $sql, 'at' => $this->templateLine($traces)];
        };
        // A log target that only keeps the SQL of each query, as Yii logs it when profiling is on.
        $logger->dispatcher->targets['speedy-queries'] = new class($note) extends Target {
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
                        ($this->note)((string)$message[0], (array)($message[4] ?? []));
                    }
                }
            }

            public function export(): void
            {
            }
        };
    }

    /**
     * Stops counting and notes the page in the report.
     */
    public function finish(string $url): void
    {
        if ($this->_sql === null) {
            return;
        }
        $logger = Craft::getLogger();
        $logger->flush();
        unset($logger->dispatcher->targets['speedy-queries']);
        $logger->traceLevel = $this->_traceLevel;
        Craft::$app->getDb()->enableProfiling = $this->_profiling;
        $sql = $this->_sql;
        $this->_sql = null;
        $start = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
        $this->save($url, $this->analyse($sql) + ['ms' => $start ? (int)round((microtime(true) - (float)$start) * 1000) : null]);
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
            $shape = $this->shape($query['sql']);
            $groups[$shape] ??= ['count' => 0, 'example' => $query['sql'], 'at' => []];
            $groups[$shape]['count']++;
            if ($query['at'] !== null) {
                $groups[$shape]['at'][$query['at']] = ($groups[$shape]['at'][$query['at']] ?? 0) + 1;
            }
        }
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

        return ['queries' => count($queries), 'repeated' => array_slice($repeated, 0, 5)];
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
    }

    /**
     * @param array{queries: int, repeated: array<int, array{count: int, sql: string, at: ?string, tip: string}>, ms: ?int} $result
     */
    private function save(string $url, array $result): void
    {
        $mutex = Craft::$app->getMutex();
        if (!$mutex->acquire('speedy:queries', 5)) {
            return;
        }
        try {
            $storage = Plugin::getInstance()->storage;
            $pages = $storage->readData('queries');
            unset($pages[$url]);
            // Only pages with repeated queries are worth keeping.
            if ($result['repeated']) {
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
        foreach ($traces as $trace) {
            $file = (string)($trace['file'] ?? '');
            if (!str_contains($file, 'compiled_templates')) {
                continue;
            }
            $class = $this->templateClass($file);
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
