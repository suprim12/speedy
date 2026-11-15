<?php

namespace suprim\speedy\tests;

use craft\helpers\FileHelper;
use PHPUnit\Framework\TestCase as BaseTestCase;
use suprim\speedy\models\Settings;
use suprim\speedy\Plugin;

/**
 * Points Speedy at a temporary folder for each test, so tests never touch the site’s real cache, and puts the
 * settings back afterwards.
 */
abstract class TestCase extends BaseTestCase
{
    protected string $tmp;
    private Settings $original;

    protected function setUp(): void
    {
        parent::setUp();
        $settings = $this->settings();
        $this->original = clone $settings;
        $this->tmp = sys_get_temp_dir() . '/speedy-test-' . bin2hex(random_bytes(4));
        $settings->cachePath = "$this->tmp/web";
        $settings->dataPath = "$this->tmp/data";
        $settings->storage = Settings::STORAGE_FILES;
        $settings->rebuildCleared = false;
        $settings->enabled = true;
        $settings->cloudflareToken = $settings->cloudflareZoneId = $settings->cloudfrontDistributionId = '';
    }

    protected function tearDown(): void
    {
        $settings = $this->settings();
        foreach (get_object_vars($this->original) as $name => $value) {
            $settings->$name = $value;
        }
        if (is_dir($this->tmp)) {
            FileHelper::removeDirectory($this->tmp);
        }
        parent::tearDown();
    }

    protected function plugin(): Plugin
    {
        return Plugin::getInstance();
    }

    protected function settings(): Settings
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        return $settings;
    }
}
