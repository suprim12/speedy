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

    public function testASitemapOnlySendsSpeedyToItsOwnHost(): void
    {
        $xml = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            . '<url><loc>https://example.com/news</loc></url>'
            . '<url><loc>https://169.254.169.254/latest/meta-data/</loc></url>'
            . '<url><loc>http://internal.example/admin</loc></url>'
            . '<url><loc>file:///etc/passwd</loc></url>'
            . '<url><loc>https://user@example.com/x</loc></url>'
            . '</urlset>';
        $this->assertSame(['https://example.com/news' => null], $this->plugin()->warmer->parseSitemap($xml, 0, 'example.com'), 'Only its own host’s pages.');

        $may = new \ReflectionMethod($this->plugin()->warmer, 'sitemapMayList');
        $this->assertTrue($may->invoke($this->plugin()->warmer, 'https://example.com/sitemap-2.xml', 'example.com'));
        $this->assertFalse($may->invoke($this->plugin()->warmer, 'https://elsewhere.example/sitemap.xml', 'example.com'), 'A sitemap index can’t send it to another host.');
        $site = (string)parse_url((string)\Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), PHP_URL_HOST);
        $this->assertTrue($may->invoke($this->plugin()->warmer, "https://$site/", 'example.com'), 'Or to one of the sites, as a multi-site index may.');
    }
}
