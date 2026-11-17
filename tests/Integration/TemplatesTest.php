<?php

namespace suprim\speedy\tests\Integration;

use Craft;
use craft\web\View;
use suprim\speedy\tests\TestCase;

/**
 * The utility page and dashboard widget render in each state: empty, with content, and with CDNs set up.
 */
class TemplatesTest extends TestCase
{
    /**
     * @param array<string, mixed> $vars
     */
    private function render(string $template, array $vars): string
    {
        // The forms on the page need a web request and response, for their CSRF tokens.
        $request = Craft::$app->getRequest();
        $response = Craft::$app->getResponse();
        Craft::$app->set('request', new class(['cookieValidationKey' => 'test']) extends \craft\web\Request {
            public function getCsrfToken($regenerate = false): string
            {
                return 'test-token';
            }
        });
        Craft::$app->set('response', new \craft\web\Response());
        $view = Craft::$app->getView();
        $mode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);
        try {
            return $view->renderTemplate($template, $vars);
        } finally {
            $view->setTemplateMode($mode);
            Craft::$app->set('request', $request);
            Craft::$app->set('response', $response);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function utilityVars(bool $full): array
    {
        $plugin = $this->plugin();
        $row = ['url' => 'https://example.com/news', 'time' => time(), 'queries' => 82, 'ms' => 316, 'repeated' => [['count' => 42, 'sql' => 'FROM `entries` WHERE `id` = ?', 'at' => 'news/_index, line 9', 'tip' => 'Entries are fetched one item at a time <b>inside</b> a loop.']]];

        return [
            'pageCheck' => $full ? ['url' => 'https://example.com/homes?view=map', 'cached' => false, 'answer' => 'Not cached: query string.', 'why' => 'The address has a query string with `view` <b>x</b>.', 'fix' => 'Add it to **Cached parameters**.', 'notes' => ['You’re logged in.'], 'visits' => ['First visit: X-Speedy: SKIP query string, status 200, 40 ms']] : null,
            'checks' => $full ? [['level' => 'error', 'message' => 'Problem **here**'], ['level' => 'hint', 'message' => 'See [Repeated queries](#queries).']] : [],
            'list' => $full ? ['total' => 120, 'pages' => [['url' => 'https://example.com/news', 'created' => time(), 'expires' => time() + 60, 'deps' => ['Entries: News']], ['url' => 'https://example.com/', 'created' => time(), 'expires' => null, 'deps' => []]]] : ['total' => 0, 'pages' => []],
            'search' => $full ? 'news' : '',
            'page' => 2,
            'perPage' => 50,
            'settings' => $plugin->getSettings(),
            'stats' => ['pages' => $full ? 120 : 0, 'bytes' => 2048000],
            'detected' => $full ? 'cloudflare' : null,
            'configured' => $full ? ['cloudflare', 'cloudfront'] : [],
            'cdnLast' => $full ? ['cloudflare' => ['time' => time(), 'result' => 'Cleared 3 URLs', 'error' => false], 'cloudfront' => ['time' => time(), 'result' => 'failed: denied', 'error' => true]] : [],
            'cdnNames' => $plugin->cdn->names(),
            'report' => 'Report <script>',
            'cloudfront' => ['month' => date('Y-m'), 'paths' => 12],
            'log' => $full ? array_fill(0, 30, ['time' => time(), 'reason' => 'Saved entry “A”', 'count' => 2, 'urls' => ['https://example.com/a'], 'cdn' => null]) : [],
            'queries' => $full ? [$row] : [],
            'slowest' => $full ? [['url' => 'https://example.com/listing', 'ms' => 12400, 'queries' => 380, 'time' => time()], ['url' => 'https://example.com/<b>about</b>', 'ms' => 640, 'queries' => 40, 'time' => time()]] : [],
            'broad' => $full ? [['url' => 'https://example.com/', 'time' => time(), 'broad' => [['type' => 'Entries', 'at' => '_partials/<b>latest</b>, line 14', 'count' => 1]]]] : [],
            'state' => $full ? ['lastExpire' => time()] : [],
            'webPath' => $full ? 'speedy' : null,
            'nginx' => 'location /speedy {}',
            'apache' => 'RewriteRule .*',
        ];
    }

    public function testUtilityPageWhenEmpty(): void
    {
        $html = $this->render('speedy/_utility', $this->utilityVars(false));
        $this->assertStringContainsString('No pages cached yet', $html);
        $this->assertStringContainsString('Nothing cleared yet', $html);
        $this->assertStringContainsString('No repeated queries found', $html);
        $this->assertStringContainsString('No page rebuilt so far took more than half a second', $html);
        $this->assertStringNotContainsString('Cleared by any change', $html);
        $this->assertStringNotContainsString('speedy-badge', $html, 'No counts on empty tabs.');
        $this->assertStringNotContainsString('Show 0 more', $html);
    }

    public function testUtilityPageWithEverything(): void
    {
        $html = $this->render('speedy/_utility', $this->utilityVars(true));
        foreach (['pages', 'clears', 'queries', 'setup'] as $tab) {
            $this->assertStringContainsString('id="speedy-panel-' . $tab . '"', $html);
            $this->assertStringContainsString('href="#' . $tab . '"', $html);
        }
        $this->assertStringContainsString('Show 20 more', $html, 'The first 10 clears, then the rest.');
        $this->assertSame(20, substr_count($html, 'class="speedy-more" hidden'));
        $this->assertStringContainsString('42×', $html);
        $this->assertStringContainsString('12.4 s', $html, 'Slow pages in seconds.');
        $this->assertStringContainsString('640 ms', $html);
        $this->assertStringContainsString('Any of: Entries, at _partials/&lt;b&gt;latest&lt;/b&gt;, line 14', $html, 'The line to narrow, escaped.');
        $this->assertStringNotContainsString('<b>about</b>', $html);
        $this->assertStringContainsString('Test Cloudflare', $html);
        $this->assertStringContainsString('failed: denied', $html);
        $this->assertStringContainsString('Page 2 of 3', $html);
        $this->assertStringContainsString('Why isn’t this page cached?', $html);
        $this->assertStringContainsString('speedy-check--warning speedy-pagecheck__result', $html);
        $this->assertStringContainsString('<strong>Cached parameters</strong>', $html, 'The answer’s Markdown.');
        $this->assertStringContainsString('value="https://example.com/homes?view=map"', $html, 'The address stays in the field.');
        $this->assertStringNotContainsString('<b>x</b>', $html, 'Escaped.');
        // Text from pages, queries and reports is escaped.
        $this->assertStringNotContainsString('<b>inside</b>', $html);
        $this->assertStringNotContainsString('Report <script>', $html);
    }

    public function testUtilityPageWithoutTheQueryReport(): void
    {
        $this->settings()->queryReport = false;
        $html = $this->render('speedy/_utility', $this->utilityVars(true));
        $this->assertStringNotContainsString('speedy-panel-queries', $html);
        $this->assertStringNotContainsString('id="speedy-tab-queries"', $html);
    }

    public function testWidget(): void
    {
        $plugin = $this->plugin();
        $vars = ['settings' => $plugin->getSettings(), 'stats' => ['pages' => 0, 'bytes' => 0], 'problems' => [], 'log' => [], 'detected' => null, 'configured' => [], 'cdnLast' => [], 'cdnNames' => $plugin->cdn->names()];
        $this->assertStringContainsString('Nothing cleared yet', $this->render('speedy/_widget', $vars));

        $html = $this->render('speedy/_widget', array_merge($vars, [
            'stats' => ['pages' => 1234, 'bytes' => 5 * 1048576],
            'problems' => [['level' => 'error', 'message' => 'Disk **nearly** full']],
            'log' => [['time' => time(), 'reason' => 'Saved entry <i>A</i>', 'count' => 3, 'urls' => [], 'cdn' => null]],
            'configured' => ['cloudflare'],
            'cdnLast' => ['cloudflare' => ['time' => time(), 'result' => 'boom', 'error' => true]],
        ]));
        $this->assertStringContainsString('1,234', $html);
        $this->assertStringContainsString('5.0 MB', $html);
        $this->assertStringContainsString('Clearing Cloudflare', $html);
        $this->assertStringContainsString('The last Cloudflare clear failed: boom', $html);
        $this->assertStringContainsString('<strong>nearly</strong>', $html, 'Problems are formatted.');
        $this->assertStringNotContainsString('<i>A</i>', $html, 'Reasons are escaped.');
    }
}
