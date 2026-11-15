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
