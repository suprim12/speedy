<?php

namespace suprim\speedy\tests\Integration;

use craft\db\Query;
use suprim\speedy\services\Queries;
use suprim\speedy\tests\TestCase;

/**
 * The report of pages that run the same query again and again (N+1 queries).
 */
class QueriesTest extends TestCase
{
    public function testTheSameQueryForDifferentItemsCountsAsOne(): void
    {
        $queries = $this->plugin()->queries;
        $this->assertSame(
            $queries->shape("SELECT * FROM `elements` WHERE `id` IN (12, 13) AND `title` = 'A'"),
            $queries->shape("SELECT *  FROM `elements` WHERE `id` IN (99) AND `title` = 'Don\\'t'"),
        );
        $this->assertNotSame($queries->shape('SELECT * FROM `elements`'), $queries->shape('SELECT * FROM `assets`'));
    }

    public function testOnlyQueriesRunManyTimesAreReported(): void
    {
        $sql = [];
        for ($i = 1; $i <= Queries::REPEATED; $i++) {
            $sql[] = ['sql' => "SELECT `elements`.`id` FROM `elements` INNER JOIN `categories` `categories` ON `categories`.`id` = `elements`.`id` WHERE `elements`.`id` = $i", 'at' => 'news/_index, line 12'];
        }
        $sql[] = 'SELECT * FROM `sites`';
        $sql[] = 'SELECT * FROM `sites`';
        $result = $this->plugin()->queries->analyse($sql);

        $this->assertSame(Queries::REPEATED + 2, $result['queries']);
        $this->assertCount(1, $result['repeated'], 'A query run twice isn’t a problem.');
        $repeat = $result['repeated'][0];
        $this->assertSame(Queries::REPEATED, $repeat['count']);
        $this->assertSame('news/_index, line 12', $repeat['at']);
        $this->assertStringStartsWith('FROM `elements`', $repeat['sql'], 'Shown from FROM on.');
        $this->assertStringContainsString('Categories are fetched one item at a time', $repeat['tip']);
        $this->assertStringContainsString('news/_index, line 12', $repeat['tip']);
    }

    public function testCountsARequestAndForgetsAPageOnceFixed(): void
    {
        $queries = $this->plugin()->queries;
        $url = 'https://example.com/news';

        $queries->start();
        for ($i = 1; $i <= Queries::REPEATED + 2; $i++) {
            (new Query())->from('{{%elements}}')->where(['id' => $i])->one();
        }
        $queries->finish($url);
        $report = $queries->report();
        $this->assertCount(1, $report);
        $this->assertSame($url, $report[0]['url']);
        $this->assertSame(Queries::REPEATED + 2, $report[0]['repeated'][0]['count']);

        // Rebuilt without the loop: off the report.
        $queries->start();
        (new Query())->from('{{%elements}}')->where(['id' => 1])->one();
        $queries->finish($url);
        $this->assertSame([], $queries->report());
    }

    public function testLeavesCraftAsItWas(): void
    {
        $db = \Craft::$app->getDb();
        $driver = $db->getDriverName();
        $command = $db->commandMap[$driver];
        $traceLevel = \Craft::getLogger()->traceLevel;
        $profiling = $db->enableProfiling;

        $queries = $this->plugin()->queries;
        $queries->start();
        $this->assertSame(\suprim\speedy\db\CountedCommand::class, $db->commandMap[$driver], 'Counted through Craft’s own command.');
        $this->assertSame($traceLevel, \Craft::getLogger()->traceLevel, 'Other log messages don’t get call traces.');
        (new Query())->from('{{%elements}}')->limit(1)->one();
        $queries->finish('https://example.com/a');

        $this->assertSame($command, $db->commandMap[$driver]);
        $this->assertSame($profiling, $db->enableProfiling);
    }

    public function testNamesTheTemplateAndLineEvenInsideAnEmbed(): void
    {
        $dir = \Craft::getAlias('@templates/speedy-tests-' . bin2hex(random_bytes(3)));
        $name = basename($dir);
        mkdir($dir, 0775, true);
        file_put_contents("$dir/_card.twig", "<div>{% block body %}{% endblock %}</div>");
        file_put_contents("$dir/page.twig", implode("\n", [
            '<h1>Page</h1>',
            '{% embed "' . $name . '/_card" %}',
            '  {% block body %}',
            '    {% for i in 1..12 %}{{ craft.users().id(i).one() ? "" }}{% endfor %}',
            '  {% endblock %}',
            '{% endembed %}',
            '{% for i in 1..12 %}{{ craft.users().id(i).cache(60).one() ? "" }}{% endfor %}',
        ]));
        $view = \Craft::$app->getView();
        $mode = $view->getTemplateMode();
        $view->setTemplateMode(\craft\web\View::TEMPLATE_MODE_SITE);
        $queries = $this->plugin()->queries;
        try {
            $queries->start();
            $view->renderTemplate("$name/page");
            $queries->finish('https://example.com/embed');
        } finally {
            $view->setTemplateMode($mode);
            foreach (glob("$dir/*") ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }

        $report = $queries->report();
        $this->assertCount(1, $report[0]['repeated'] ?? [], 'Queries from the query cache aren’t counted as repeated.');
        $this->assertSame("$name/page, line 4", $report[0]['repeated'][0]['at'], 'The line inside the embed, in the page’s own template.');
    }

    public function testOffMeansNothingIsCounted(): void
    {
        $this->settings()->queryReport = false;
        $queries = $this->plugin()->queries;
        $queries->start();
        for ($i = 1; $i <= Queries::REPEATED; $i++) {
            (new Query())->from('{{%elements}}')->where(['id' => $i])->one();
        }
        $queries->finish('https://example.com/news');
        $this->assertSame([], $queries->report());
    }
}
