<?php

namespace suprim\speedy\tests\Integration;

use suprim\speedy\models\Settings;
use suprim\speedy\tests\TestCase;

class SettingsTest extends TestCase
{
    public function testApiKeyNeedsSixteenCharacters(): void
    {
        $settings = new Settings(['apiKey' => 'short']);
        $this->assertFalse($settings->validate(['apiKey']));

        $settings = new Settings(['apiKey' => str_repeat('k', 16)]);
        $this->assertTrue($settings->validate(['apiKey']));

        $settings = new Settings(['apiKey' => '']);
        $this->assertTrue($settings->validate(['apiKey']), 'Empty turns the API off.');
    }

    public function testStorageMustBeKnown(): void
    {
        $this->assertFalse((new Settings(['storage' => 'disk']))->validate(['storage']));
        $this->assertTrue((new Settings(['storage' => Settings::STORAGE_CRAFT_CACHE]))->validate(['storage']));
    }

    public function testNeverCacheAcceptsLinesFromTheSettingsPage(): void
    {
        $settings = new Settings(['neverCache' => "checkout/*\n\naccount*\n"]);
        $this->assertSame(['checkout/*', 'account*'], $settings->getNeverCache());
    }

    public function testOffOnTemporaryDisksUnlessUsingCraftsCache(): void
    {
        putenv('CRAFT_EPHEMERAL=true');
        try {
            $this->assertFalse((new Settings())->isEnabled());
            $this->assertTrue((new Settings(['storage' => Settings::STORAGE_CRAFT_CACHE]))->isEnabled());
        } finally {
            putenv('CRAFT_EPHEMERAL');
        }
    }
}
