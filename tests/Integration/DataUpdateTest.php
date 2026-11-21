<?php

namespace suprim\speedy\tests\Integration;

use Craft;
use suprim\speedy\tests\TestCase;

/**
 * Speedy’s small data files, such as its state and CDN details, are changed one request at a time, so changes made
 * at the same moment don’t undo each other.
 */
class DataUpdateTest extends TestCase
{
    public function testChangesAreMadeInsideTheLock(): void
    {
        $storage = $this->plugin()->storage;
        $storage->writeData('state', ['a' => 1]);

        $held = null;
        $after = $storage->updateData('state', function(array $state) use (&$held) {
            // Another request can’t take it meanwhile.
            $held = !Craft::$app->getMutex()->acquire('speedy:data:state');
            $state['b'] = 2;
            return $state;
        });
        $this->assertTrue($held, 'The lock is held while the change is made.');
        $this->assertSame(['a' => 1, 'b' => 2], $after);
        $this->assertSame(['a' => 1, 'b' => 2], $storage->readData('state'));
        $this->assertTrue(Craft::$app->getMutex()->acquire('speedy:data:state'), 'And let go afterwards.');
        Craft::$app->getMutex()->release('speedy:data:state');
    }

    public function testNullLeavesItAsItIs(): void
    {
        $storage = $this->plugin()->storage;
        $storage->writeData('cdn', ['detected' => 'cloudflare']);
        $this->assertSame(['detected' => 'cloudflare'], $storage->updateData('cdn', fn() => null));
        $this->assertSame(['detected' => 'cloudflare'], $storage->readData('cdn'));
    }

    public function testCloudFrontPathsAddUp(): void
    {
        $cdn = $this->plugin()->cdn;
        $count = new \ReflectionMethod($cdn, 'countPaths');
        $count->invoke($cdn, 3);
        $count->invoke($cdn, 4);
        $this->assertSame(7, $cdn->cloudfrontUsage()['paths']);
    }
}
