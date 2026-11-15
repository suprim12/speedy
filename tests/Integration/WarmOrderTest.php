<?php

namespace suprim\speedy\tests\Integration;

use suprim\speedy\tests\TestCase;

/**
 * After clearing everything, important pages are rebuilt first.
 */
class WarmOrderTest extends TestCase
{
    public function testHomepagesThenPriorityThenShortPaths(): void
    {
        $urls = [
            'https://example.com/news/a-story',
            'https://example.com/contact',
            'https://example.com/news',
            'https://example.com/',
            'https://example.com/shop/sale',
            'https://example.com/de/',
            'https://example.com/about',
        ];
        $order = $this->plugin()->warmer->importantFirst($urls, ['https://example.com/shop/sale' => 0.9, 'https://example.com/about' => 0.2]);

        $this->assertSame([
            'https://example.com/',
            'https://example.com/shop/sale',
            'https://example.com/contact',
            'https://example.com/news',
            'https://example.com/de/',
            'https://example.com/news/a-story',
            'https://example.com/about',
        ], $order);
    }

    public function testReadsSitemapPriorities(): void
    {
        $xml = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
              <url><loc>https://example.com/</loc><priority>1.0</priority></url>
              <url><loc>https://example.com/news</loc></url>
              <url><loc>https://example.com/news</loc><priority>0.1</priority></url>
            </urlset>
            XML;

        $this->assertSame(['https://example.com/' => 1.0, 'https://example.com/news' => null], $this->plugin()->warmer->parseSitemap($xml));
    }
}
