<?php

namespace suprim\speedy\tests\Http;

use Craft;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use suprim\speedy\Plugin;

/**
 * Real requests to pages with other plugins’ output: Formie forms work from a cached page, with their files and a
 * fresh token, and SEOmatic’s tags are kept. Needs SPEEDY_TEST_URL, and each plugin to be installed, or it’s skipped.
 */
class PluginsTest extends TestCase
{
    private static string $base;
    private static string $dir;
    private static string $prefix;

    /** A three-step Formie form on the dev site, from notes/seed-formie-test.php. */
    private const FORMIE_FORM = 'speedyMultiStep';

    public static function setUpBeforeClass(): void
    {
        $base = getenv('SPEEDY_TEST_URL');
        if (!$base) {
            self::markTestSkipped('Set SPEEDY_TEST_URL to run the http tests.');
        }
        self::$base = rtrim($base, '/');
        self::$prefix = 'speedy-tests-' . bin2hex(random_bytes(3));
        self::$dir = Craft::getAlias('@templates/' . self::$prefix);
        mkdir(self::$dir, 0775, true);
        $templates = [
            'formie' => '<!doctype html><html><head><meta charset="utf-8"><title>Formie</title></head><body>{{ craft.formie.renderForm("' . self::FORMIE_FORM . '") }}</body></html>',
            'seo' => '<!doctype html><html><head><meta charset="utf-8">{{ head() }}</head><body>{{ beginBody() }}<h1>SEO</h1>{{ endBody() }}</body></html>',
        ];
        foreach ($templates as $name => $twig) {
            file_put_contents(self::$dir . "/$name.twig", $twig);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$dir) && is_dir(self::$dir)) {
            foreach (glob(self::$dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir(self::$dir);
            foreach (Plugin::getInstance()->storage->allKeys() as $key) {
                if (str_contains($key, self::$prefix)) {
                    Plugin::getInstance()->storage->delete($key);
                }
            }
        }
    }

    private function client(?CookieJar $jar = null, bool $redirects = true): Client
    {
        return new Client(['base_uri' => self::$base, 'http_errors' => false, 'verify' => false, 'cookies' => $jar ?? false, 'allow_redirects' => $redirects]);
    }

    private function get(string $page, ?CookieJar $jar = null): ResponseInterface
    {
        return $this->client($jar)->get('/' . self::$prefix . '/' . $page);
    }

    private function needsFormie(): void
    {
        $formie = Craft::$app->getPlugins()->getPlugin('formie');
        if (!$formie || !class_exists(\verbb\formie\elements\Form::class) || !\verbb\formie\elements\Form::find()->handle(self::FORMIE_FORM)->exists()) {
            $this->markTestSkipped('Formie, with the form ' . self::FORMIE_FORM . ' (notes/seed-formie-test.php), isn’t on this site.');
        }
    }

    public function testAFormiePageIsCachedWithItsFilesAndAFreshToken(): void
    {
        $this->needsFormie();
        $this->get('formie');
        $cached = $this->get('formie');
        $html = (string)$cached->getBody();
        $this->assertContains($cached->getHeaderLine('X-Speedy'), ['HIT', 'STATIC'], 'A page with a form is cached.');
        $this->assertStringContainsString('__SPEEDY_CSRF__', $html, 'Its CSRF token is a placeholder, filled in for each visitor.');

        // Every CSS and JavaScript file the cached page links to loads, such as Formie’s in cpresources.
        preg_match_all('#<(?:script\b[^>]*\ssrc|link\b[^>]*\shref)="([^"]+\.(?:css|js)(?:\?[^"]*)?)"#i', $html, $m);
        $host = (string)parse_url(self::$base, PHP_URL_HOST);
        $paths = array_unique(array_filter(array_map(fn($url) => parse_url(html_entity_decode($url), PHP_URL_HOST) === $host || !str_starts_with($url, 'http') ? (string)parse_url(html_entity_decode($url), PHP_URL_PATH) : '', $m[1])));
        $this->assertNotEmpty(array_filter($paths, fn($p) => str_contains($p, 'formie')), 'Formie’s files are linked.');
        foreach ($paths as $path) {
            $this->assertSame(200, $this->client()->get($path)->getStatusCode(), "$path loads.");
        }

        $token = json_decode((string)$this->client()->get('/actions/speedy/csrf', ['headers' => ['Accept' => 'application/json']])->getBody(), true);
        $this->assertNotEmpty($token['token'] ?? null, 'A fresh token is given for the placeholder.');
    }

    public function testAFormieFormCanBeSentFromACachedPage(): void
    {
        $this->needsFormie();
        $start = new \DateTime();
        $this->get('formie');
        $jar = new CookieJar();
        $cached = $this->get('formie', $jar);
        $this->assertContains($cached->getHeaderLine('X-Speedy'), ['HIT', 'STATIC']);
        $html = (string)$cached->getBody();
        $this->assertSame(1, preg_match('#<form\b.*?</form>#s', $html, $form), 'The cached page has the form.');

        // What the browser sends: the form’s hidden fields, with the fresh token in place of the placeholder.
        $token = json_decode((string)$this->client($jar)->get('/actions/speedy/csrf', ['headers' => ['Accept' => 'application/json']])->getBody(), true)['token'];
        $fields = [];
        preg_match_all('#<input type="hidden" name="([^"]+)" value="([^"]*)"#', $form[0], $hidden, PREG_SET_ORDER);
        foreach ($hidden as [, $name, $value]) {
            $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
            $fields[$name] = $value === '__SPEEDY_CSRF__' ? $token : $value;
        }
        preg_match('#name="(fields\[[^\]]+\])"#', $form[0], $first);
        $fields[$first[1]] = 'Speedy test';

        try {
            $sent = $this->client($jar, false)->post('/', ['form_params' => $fields]);
            $this->assertContains($sent->getStatusCode(), [302, 303], 'Formie takes it, rather than refusing the token.');

            $mine = $this->get('formie', $jar);
            $this->assertSame('SKIP session', $mine->getHeaderLine('X-Speedy'), 'The visitor who sent it gets a page built for them.');
            $this->assertNotSame((string)$mine->getBody(), $html, 'Showing where they are.');

            $theirs = $this->get('formie');
            $this->assertContains($theirs->getHeaderLine('X-Speedy'), ['HIT', 'STATIC'], 'Everyone else still gets the cached page.');
            $this->assertSame($html, (string)$theirs->getBody());
        } finally {
            // Only the submissions this test made.
            foreach (\verbb\formie\elements\Submission::find()->form(self::FORMIE_FORM)->dateCreated('>= ' . $start->format(DATE_ATOM))->status(null)->isIncomplete(null)->all() as $submission) {
                Craft::$app->getElements()->deleteElement($submission, true);
            }
        }
    }

    public function testSeomaticTagsAreKeptInTheCachedPage(): void
    {
        if (!Craft::$app->getPlugins()->getPlugin('seomatic')) {
            $this->markTestSkipped('SEOmatic isn’t on this site.');
        }
        $first = (string)$this->get('seo')->getBody();
        if (!str_contains($first, 'application/ld+json')) {
            $this->markTestSkipped('SEOmatic doesn’t add its tags to pages on this site.');
        }
        $cached = $this->get('seo');
        $this->assertContains($cached->getHeaderLine('X-Speedy'), ['HIT', 'STATIC']);
        $body = (string)$cached->getBody();
        $this->assertStringContainsString('application/ld+json', $body, 'Its JSON-LD is in the cached page.');
        $this->assertSame(preg_match('#<title>.*?</title>#s', $first, $a) ? $a[0] : null, preg_match('#<title>.*?</title>#s', $body, $b) ? $b[0] : null, 'And the same title.');
    }
}
