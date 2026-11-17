<?php

namespace suprim\speedy\tests\Integration;

use Craft;
use suprim\speedy\services\Cacher;
use suprim\speedy\services\Clearer;
use suprim\speedy\tests\TestCase;

/**
 * Pages cached before their image transforms were made are rebuilt once they have been.
 */
class TransformRebuildTest extends TestCase
{
    private const URL = 'https://example.com/speedy-test-gallery';

    /** @var array<int, array{0: string, 1: int}> */
    private array $pushed = [];

    private \Closure $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forget();
        $this->listener = function(\yii\queue\PushEvent $e) {
            foreach ($e->job->urls ?? [] as $url) {
                $this->pushed[] = [$url, (int)$e->delay];
            }
            $e->handled = true;
        };
        Craft::$app->getQueue()->on(\yii\queue\Queue::EVENT_BEFORE_PUSH, $this->listener);
    }

    protected function tearDown(): void
    {
        Craft::$app->getQueue()->off(\yii\queue\Queue::EVENT_BEFORE_PUSH, $this->listener);
        $this->forget();
        parent::tearDown();
    }

    private function forget(): void
    {
        Craft::$app->getCache()->delete(Clearer::REBUILD_LATER . md5(self::URL));
        Craft::$app->getCache()->delete('speedy:transformRebuilds:' . md5(self::URL));
    }

    private function saved(string $html): void
    {
        $method = new \ReflectionMethod(Cacher::class, 'rebuildAfterTransforms');
        $method->invoke($this->plugin()->cacher, $html, self::URL, 'example.com/speedy-test-gallery');
    }

    public function testRebuiltAMinuteLaterWhileTransformsAreMade(): void
    {
        $this->saved('<html><body><img src="/actions/assets/generate-transform?transformId=12"></body></html>');
        $this->assertSame([[self::URL, 60]], $this->pushed);

        $this->saved('<html><body><img src="/actions/assets/generate-transform?transformId=12"></body></html>');
        $this->assertCount(1, $this->pushed, 'Once while a rebuild is waiting.');
    }

    public function testFinishedImagesNeedNoRebuild(): void
    {
        $this->saved('<html><body><img src="/uploads/_400x300_crop/photo.jpg"></body></html>');
        $this->assertSame([], $this->pushed);
    }

    public function testFoundInScriptsToo(): void
    {
        $this->saved('<html><body><script>var src = "\/actions\/assets\/generate-transform?transformId=3";</script></body></html>');
        $this->assertCount(1, $this->pushed, 'Written with escaped slashes, as JSON is.');
    }

    public function testGivesUpOnTransformsThatAreNeverMade(): void
    {
        for ($i = 0; $i < Cacher::TRANSFORM_REBUILDS + 2; $i++) {
            // Each rebuild starts, then finds the page still waiting for its images.
            Craft::$app->getCache()->delete(Clearer::REBUILD_LATER . md5(self::URL));
            $this->saved('<img src="/actions/assets/generate-transform?transformId=99">');
        }
        $this->assertCount(Cacher::TRANSFORM_REBUILDS, $this->pushed, 'At most this many a day, such as for a missing file.');
    }
}
