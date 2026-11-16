<?php

namespace suprim\speedy\tests\Integration;

use suprim\speedy\models\Settings;
use suprim\speedy\services\Cacher;
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

    public function testOnlyCacheMatchesPathsAndTheHomepage(): void
    {
        $settings = new Settings(['onlyCache' => "/\nnews*\n\nabout\n"]);
        $this->assertSame(['/', 'news*', 'about'], $settings->getOnlyCache());
        $this->assertSame([], (new Settings())->getOnlyCache(), 'Empty caches every page.');

        $only = $settings->getOnlyCache();
        $this->assertSame('/', Cacher::matchingPattern('', $only));
        $this->assertSame('news*', Cacher::matchingPattern('/news/a-story', $only));
        $this->assertSame('about', Cacher::matchingPattern('About/', $only));
        $this->assertNull(Cacher::matchingPattern('contact', $only));
        $this->assertNull(Cacher::matchingPattern('about/team', $only));
    }

    public function testCloudflareZonesFromAnEnvironmentVariable(): void
    {
        putenv('SPEEDY_TEST_ZONES={"example.com":"z1","example.de":"z2"}');
        try {
            $settings = new Settings(['cloudflareZones' => '$SPEEDY_TEST_ZONES', 'cloudflareZoneId' => 'z0']);
            $this->assertSame(['example.com' => 'z1', 'example.de' => 'z2', '*' => 'z0'], $settings->getCloudflareZones());
            $this->assertSame(['example.com', 'example.de'], $settings->getCloudflareZoneDomains());
        } finally {
            putenv('SPEEDY_TEST_ZONES');
        }
        $this->assertSame(['example.com' => 'z1', 'www.example.de' => 'z2'], (new Settings(['cloudflareZones' => 'example.com=z1, www.example.de=z2']))->getCloudflareZones());
        $this->assertSame([], (new Settings(['cloudflareZones' => null]))->getCloudflareZones(), 'An unset variable is no zones, not an error.');
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

    public function testRebuildAuthFromEnvironment(): void
    {
        putenv('SPEEDY_TEST_USER=staging');
        try {
            $this->assertNull((new Settings())->getWarmAuth());
            $this->assertSame(['staging', 'secret'], (new Settings(['warmUsername' => '$SPEEDY_TEST_USER', 'warmPassword' => 'secret']))->getWarmAuth());
        } finally {
            putenv('SPEEDY_TEST_USER');
        }
    }

    public function testSupportReportHidesSecrets(): void
    {
        $this->settings()->cloudflareToken = 'super-secret-token';
        $this->settings()->apiKey = 'another-secret-key-123';
        $report = $this->plugin()->insights->report();

        $this->assertStringContainsString('# Speedy Cache support report', $report);
        $this->assertStringContainsString('API key: set', $report);
        $this->assertStringNotContainsString('super-secret-token', $report);
        $this->assertStringNotContainsString('another-secret-key-123', $report);
    }
}
