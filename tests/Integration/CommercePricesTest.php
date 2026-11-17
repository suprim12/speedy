<?php

namespace suprim\speedy\tests\Integration;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use suprim\speedy\tests\TestCase;

/**
 * Commerce prices change without any product being saved: when its catalog prices are worked out again, such as
 * after a pricing rule is saved or deleted, and when a price with dates starts or ends.
 */
class CommercePricesTest extends TestCase
{
    private const PRICES = '{{%commerce_catalogpricing}}';

    private int $variantId = 0;
    private int $productId = 0;

    /** @var int[] Rows this test added. */
    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!Craft::$app->getPlugins()->isPluginEnabled('commerce') || !Craft::$app->getDb()->tableExists(self::PRICES)) {
            $this->markTestSkipped('Needs Commerce.');
        }
        $variant = (new Query())->select(['v.id', 'v.primaryOwnerId'])->from(['v' => '{{%commerce_variants}}'])
            ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[v.id]]')
            ->where(['e.dateDeleted' => null])->andWhere(['not', ['v.primaryOwnerId' => null]])->one();
        if (!$variant) {
            $this->markTestSkipped('Needs a product.');
        }
        $this->variantId = (int)$variant['id'];
        $this->productId = (int)$variant['primaryOwnerId'];
        $this->reset();
    }

    protected function tearDown(): void
    {
        if ($this->rows) {
            Db::delete(self::PRICES, ['id' => $this->rows]);
        }
        $this->reset();
        parent::tearDown();
    }

    /**
     * Forgets what this test asked to clear, so nothing real is cleared after it.
     */
    private function reset(): void
    {
        $clearer = $this->plugin()->clearer;
        foreach (['_pending' => [], '_reasons' => [], '_changed' => [], '_clearAll' => false] as $name => $value) {
            (new \ReflectionProperty($clearer, $name))->setValue($clearer, $value);
        }
    }

    /**
     * @return string[]
     */
    private function pending(): array
    {
        return array_keys((new \ReflectionProperty($this->plugin()->clearer, '_pending'))->getValue($this->plugin()->clearer));
    }

    private function price(?string $from, ?string $to, ?int $ruleId = null): void
    {
        Craft::$app->getDb()->createCommand()->insert(self::PRICES, [
            'price' => 1.23,
            'purchasableId' => $this->variantId,
            'storeId' => (new Query())->from('{{%commerce_stores}}')->min('id'),
            'catalogPricingRuleId' => $ruleId,
            'isPromotionalPrice' => true,
            'dateFrom' => $from,
            'dateTo' => $to,
        ])->execute();
        $this->rows[] = (int)Craft::$app->getDb()->getLastInsertID();
    }

    public function testRecalculatedPricesClearTheirProducts(): void
    {
        $this->plugin()->clearer->pricesRecalculated([$this->variantId]);
        $this->assertEqualsCanonicalizing(["element::$this->variantId", "element::$this->productId"], $this->pending(), 'The variant, and its product’s page.');
        $this->assertContains('Commerce prices changed', (new \ReflectionProperty($this->plugin()->clearer, '_reasons'))->getValue($this->plugin()->clearer));
    }

    public function testAllPricesClearEveryProductPage(): void
    {
        $this->plugin()->clearer->pricesRecalculated(null);
        $this->assertContains('element::craft\commerce\elements\Variant', $this->pending());
        $this->assertContains('element::craft\commerce\elements\Product', $this->pending());
    }

    public function testThePriceJobClearsWhenItFinishes(): void
    {
        // Through the queue, whatever state this site's queue is in, so nothing real is cleared.
        $this->settings()->clearInRequest = false;
        $job = new \craft\commerce\queue\jobs\CatalogPricing(['purchasableIds' => [$this->variantId]]);
        // Flushing would clear real pages: keep what it would clear instead.
        $pushed = [];
        $listener = function(\yii\queue\PushEvent $e) use (&$pushed) {
            $pushed[] = $e->job;
            $e->handled = true;
        };
        Craft::$app->getQueue()->on(\yii\queue\Queue::EVENT_BEFORE_PUSH, $listener);
        try {
            Craft::$app->getQueue()->trigger(\yii\queue\Queue::EVENT_AFTER_EXEC, new \yii\queue\ExecEvent(['job' => $job, 'id' => '0', 'ttr' => 300, 'attempt' => 1]));
        } finally {
            Craft::$app->getQueue()->off(\yii\queue\Queue::EVENT_BEFORE_PUSH, $listener);
        }
        $tags = [];
        foreach ($pushed as $clear) {
            if ($clear instanceof \suprim\speedy\jobs\ClearJob) {
                $tags = array_merge($tags, $clear->tags);
            }
        }
        $this->assertContains("element::$this->variantId", $tags, 'After Commerce worked out the prices, not before.');
    }

    /**
     * Commerce 5.7 and later push price jobs without IDs: each takes a row of IDs from its own table.
     */
    public function testAJobClearsOnlyWhatItPriced(): void
    {
        if (!Craft::$app->getDb()->tableExists('{{%commerce_catalogpricing_queue}}')) {
            $this->markTestSkipped('Needs Commerce 5.7 or later.');
        }
        $queue = '{{%commerce_catalogpricing_queue}}';
        $row = function(?array $ids) use ($queue): int {
            Craft::$app->getDb()->createCommand()->insert($queue, ['storeId' => null, 'type' => 'purchasable', 'ids' => $ids === null ? null : \craft\helpers\Json::encode($ids), 'reserved' => false])->execute();
            return (int)Craft::$app->getDb()->getLastInsertID();
        };
        $clearer = $this->plugin()->clearer;
        $mine = $row([$this->variantId]);
        $waiting = $row([987654321]);
        try {
            $clearer->beforePriceJob();
            // The job takes its row and deletes it.
            Db::delete($queue, ['id' => $mine]);
            $clearer->afterPriceJob(null, null);
            $this->assertEqualsCanonicalizing(["element::$this->variantId", "element::$this->productId"], $this->pending(), 'Only what it priced: saving one product doesn’t clear the whole shop.');

            $this->reset();
            $clearer->beforePriceJob();
            $clearer->afterPriceJob(null, null);
            $this->assertSame([], $this->pending(), 'A job that found nothing to do clears nothing.');

            // IDs merged into the row after it was noted: the prices the job wrote say.
            $this->reset();
            $clearer->beforePriceJob();
            $this->price(null, null);
            $clearer->afterPriceJob(null, null);
            $this->assertContains("element::$this->variantId", $this->pending(), 'A price written during the job.');

            $this->reset();
            $all = $row(null);
            $clearer->beforePriceJob();
            Db::delete($queue, ['id' => $all]);
            $clearer->afterPriceJob(null, null);
            $this->assertContains('element::craft\commerce\elements\Product', $this->pending(), 'Every price worked out again.');
        } finally {
            Db::delete($queue, ['id' => [$mine, $waiting, $all ?? 0]]);
        }
    }

    public function testPricesThatStartOrEndClearTheirPages(): void
    {
        $storage = $this->plugin()->storage;
        $key = 'example.com/speedy-test-product';
        $storage->write($key, '<html>Old price</html>', 'https://example.com/speedy-test-product', ["element::$this->productId"], null);
        try {
            $now = time();
            $this->price(Db::prepareDateForDb(new \DateTime('@' . ($now - 30))), null);
            $method = new \ReflectionMethod($this->plugin()->clearer, 'clearPricesChanged');
            $this->assertSame(0, $method->invoke($this->plugin()->clearer, $now - 3600, $now - 60), 'Not yet started in that time.');
            $this->assertTrue($storage->exists($key));
            $this->assertSame(1, $method->invoke($this->plugin()->clearer, $now - 60, $now), 'Started since the last check.');
            $this->assertFalse($storage->exists($key));
        } finally {
            $storage->delete($key);
        }
    }

    public function testTheNextPriceChangeIsScheduled(): void
    {
        $next = new \ReflectionMethod($this->plugin()->clearer, 'nextPriceChange');
        $at = time() + 7 * 86400;
        $this->price(null, Db::prepareDateForDb(new \DateTime('@' . $at)));
        $found = $next->invoke($this->plugin()->clearer);
        $this->assertNotNull($found);
        $this->assertLessThanOrEqual($at, $found, 'A price ending counts too.');
        $this->assertGreaterThan(time(), $found);
    }

    public function testDeletingARuleClearsItsProducts(): void
    {
        $rules = \craft\commerce\Plugin::getInstance()->getCatalogPricingRules();
        $rule = new \craft\commerce\models\CatalogPricingRule([
            'name' => 'Speedy test rule',
            'storeId' => (int)(new Query())->from('{{%commerce_stores}}')->min('id'),
            'apply' => \craft\commerce\records\CatalogPricingRule::APPLY_TO_FLAT,
            'applyAmount' => 1,
        ]);
        $listener = fn(\yii\queue\PushEvent $e) => $e->handled = true;
        Craft::$app->getQueue()->on(\yii\queue\Queue::EVENT_BEFORE_PUSH, $listener);
        try {
            $this->assertTrue($rules->saveCatalogPricingRule($rule));
            $this->price(null, null, $rule->id);
            $this->reset();
            $rules->deleteCatalogPricingRuleById($rule->id);
            $this->rows = [];
        } finally {
            Craft::$app->getQueue()->off(\yii\queue\Queue::EVENT_BEFORE_PUSH, $listener);
            if ($rule->id && (new Query())->from('{{%commerce_catalogpricingrules}}')->where(['id' => $rule->id])->exists()) {
                $rules->deleteCatalogPricingRuleById($rule->id);
            }
        }
        $this->assertContains("element::$this->variantId", $this->pending(), 'Its prices go with it, without the price job.');
    }
}
