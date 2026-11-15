<?php

namespace suprim\speedy\tests\Integration;

use Craft;
use craft\elements\Asset;
use craft\events\RegisterComponentTypesEvent;
use craft\fs\Local;
use craft\helpers\FileHelper;
use craft\models\Volume;
use suprim\speedy\purgers\BasePurger;
use suprim\speedy\services\Cdn;
use suprim\speedy\tests\TestCase;
use yii\base\Event;

/**
 * A replaced image keeps its address, so its old copy and its transforms are cleared from the CDN.
 */
class AssetReplaceTest extends TestCase
{
    private ?Local $fs = null;
    private ?Volume $volume = null;
    private ?Asset $asset = null;
    private string $dir = '';
    private bool $writeYaml = true;

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is needed to make a test image.');
        }
        // Keep the temporary volume out of the project config files.
        $projectConfig = Craft::$app->getProjectConfig();
        $this->writeYaml = $projectConfig->writeYamlAutomatically;
        $projectConfig->writeYamlAutomatically = false;

        $handle = 'speedyTest' . bin2hex(random_bytes(3));
        $this->dir = Craft::getAlias('@webroot') . "/$handle";
        $baseUrl = rtrim((string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), '/');
        $this->fs = new Local(['name' => $handle, 'handle' => $handle, 'hasUrls' => true, 'url' => "$baseUrl/$handle", 'path' => $this->dir]);
        $this->assertTrue(Craft::$app->getFs()->saveFilesystem($this->fs));
        $this->volume = new Volume(['name' => $handle, 'handle' => $handle]);
        $this->volume->setFsHandle($handle);
        $this->assertTrue(Craft::$app->getVolumes()->saveVolume($this->volume), json_encode($this->volume->getErrors()));

        Event::on(Cdn::class, Cdn::EVENT_REGISTER_PURGERS, [$this, 'registerPurger']);
        (fn() => $this->_purgers = null)->call($this->plugin()->cdn);
        RecordingPurger::$purged = [];
    }

    public function registerPurger(RegisterComponentTypesEvent $event): void
    {
        $event->types[] = RecordingPurger::class;
    }

    protected function tearDown(): void
    {
        Event::off(Cdn::class, Cdn::EVENT_REGISTER_PURGERS, [$this, 'registerPurger']);
        (fn() => $this->_purgers = null)->call($this->plugin()->cdn);
        if ($this->asset) {
            Craft::$app->getElements()->deleteElement($this->asset, true);
        }
        if ($this->volume?->id) {
            Craft::$app->getVolumes()->deleteVolume($this->volume);
        }
        if ($this->fs) {
            Craft::$app->getFs()->removeFilesystem($this->fs);
        }
        if ($this->dir !== '' && is_dir($this->dir)) {
            FileHelper::removeDirectory($this->dir);
        }
        Craft::$app->getProjectConfig()->writeYamlAutomatically = $this->writeYaml;
        parent::tearDown();
    }

    private function image(int $width): string
    {
        $file = $this->tmp . "/image-$width.png";
        FileHelper::createDirectory($this->tmp);
        $image = imagecreatetruecolor($width, 20);
        imagepng($image, $file);

        return $file;
    }

    public function testClearsTheOldFileAndItsTransforms(): void
    {
        $asset = new Asset();
        $asset->tempFilePath = $this->image(40);
        $asset->filename = 'photo.png';
        $asset->newFolderId = Craft::$app->getAssets()->getRootFolderByVolumeId($this->volume->id)->id;
        $asset->volumeId = $this->volume->id;
        $asset->avoidFilenameConflicts = true;
        $asset->setScenario(Asset::SCENARIO_CREATE);
        $this->assertTrue(Craft::$app->getElements()->saveElement($asset), json_encode($asset->getErrors()));
        $this->asset = $asset;
        $transformUrl = $asset->getUrl(['width' => 10, 'height' => 10], true);

        Craft::$app->getAssets()->replaceAssetFile($asset, $this->image(60), 'photo.png');

        $this->assertContains($asset->getUrl(), RecordingPurger::$purged);
        $this->assertContains($transformUrl, RecordingPurger::$purged, 'The transform keeps its address too.');
        $this->assertStringContainsString('Replaced file', $this->plugin()->log->recent(1)[0]['reason']);
    }
}

class RecordingPurger extends BasePurger
{
    /** @var string[] */
    public static array $purged = [];

    public static function handle(): string
    {
        return 'recording';
    }

    public static function displayName(): string
    {
        return 'Recording';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function purgeUrls(array $urls): string
    {
        self::$purged = array_merge(self::$purged, $urls);

        return 'ok';
    }

    public function purgeAll(): string
    {
        return 'ok';
    }

    public function test(): string
    {
        return 'ok';
    }
}
