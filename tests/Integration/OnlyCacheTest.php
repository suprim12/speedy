<?php

namespace suprim\speedy\tests\Integration;

use craft\elements\Entry;
use suprim\speedy\tests\TestCase;

/**
 * Only cache matches the path within each site, so it works for sites in a folder such as /de/.
 */
class OnlyCacheTest extends TestCase
{
    public function testMatchesThePathWithinASiteInAFolder(): void
    {
        // An entry at /de/pages/garden on a site whose base URL is /de/.
        $entry = new class() extends Entry {
            public function getUrl(): ?string
            {
                return 'https://example.com/de/pages/garden';
            }
        };
        $entry->id = 999999;
        $entry->uri = 'pages/garden';
        $insights = $this->plugin()->insights;

        $this->settings()->onlyCache = ['pages/*'];
        $this->assertNotSame('never', $insights->forElement($entry)['state'], 'pages/* matches /de/pages/… too.');

        $this->settings()->onlyCache = ['de/pages/*'];
        $this->assertNotSame('never', $insights->forElement($entry)['state'], 'The full path still matches.');

        $this->settings()->onlyCache = ['news*'];
        $status = $insights->forElement($entry);
        $this->assertSame('never', $status['state']);
        $this->assertSame('Not in Only cache', $status['detail']);
    }

    public function testNeverCacheMatchesThePathWithinASiteToo(): void
    {
        $entry = new class() extends Entry {
            public function getUrl(): ?string
            {
                return 'https://example.com/de/account/orders';
            }
        };
        $entry->id = 999999;
        $entry->uri = 'account/orders';

        $this->settings()->neverCache = ['account*'];
        $status = $this->plugin()->insights->forElement($entry);
        $this->assertSame('never', $status['state'], 'account* matches /de/account/… too.');
        $this->assertSame('Matches account*', $status['detail']);

        $this->settings()->neverCache = ['de/account*'];
        $this->assertSame('never', $this->plugin()->insights->forElement($entry)['state'], 'The full path still matches.');
    }
}
