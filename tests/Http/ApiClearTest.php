<?php

namespace suprim\speedy\tests\Http;

use Craft;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use suprim\speedy\Plugin;

/**
 * Clearing pages with the API: a URL or tag sent as plain text clears that one, never everything. Needs
 * SPEEDY_TEST_URL. Sets an API key, adds two made-up pages of its own and removes them, and puts the saved settings back.
 */
class ApiClearTest extends TestCase
{
    use KeepsSettings;

    private const KEY = 'speedy-test-key-0123456789abcdef';

    private static string $base;
    private static string $host;

    /** @var string[] The pages this test added. */
    private array $made = [];

    public static function setUpBeforeClass(): void
    {
        $base = getenv('SPEEDY_TEST_URL');
        if (!$base) {
            self::markTestSkipped('Set SPEEDY_TEST_URL to run the http tests.');
        }
        self::$base = rtrim($base, '/');
        self::$host = (string)parse_url(self::$base, PHP_URL_HOST);
        self::keepSettings();
        // Removed straight away rather than rebuilt, so the test can see what was cleared.
        self::changeSettings(['apiKey' => self::KEY, 'rebuildCleared' => false]);
    }

    public static function tearDownAfterClass(): void
    {
        self::putSettingsBack();
    }

    protected function setUp(): void
    {
        $storage = Plugin::getInstance()->storage;
        foreach (['a', 'b'] as $name) {
            $key = self::$host . '/speedy-test-api-' . $name . '-' . bin2hex(random_bytes(3));
            $storage->write($key, '<html></html>', 'https://' . $key, ['speedy:speedy-test-api-' . $name], null);
            $this->made[] = $key;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->made as $key) {
            Plugin::getInstance()->storage->delete($key);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function post(array $options): ResponseInterface
    {
        return (new Client(['http_errors' => false, 'verify' => false, 'cookies' => false]))->post(self::$base . '/' . Craft::$app->getConfig()->getGeneral()->actionTrigger . '/speedy/api/clear', $options + ['headers' => ['Authorization' => 'Bearer ' . self::KEY]]);
    }

    public function testAUrlAsPlainTextClearsOnlyThatPage(): void
    {
        $storage = Plugin::getInstance()->storage;
        foreach ([['form_params' => ['urls' => 'https://' . $this->made[0]]], ['json' => ['urls' => 'https://' . $this->made[0]]]] as $options) {
            $storage->write($this->made[0], '<html></html>', 'https://' . $this->made[0], [], null);
            $response = $this->post($options);
            $this->assertSame(200, $response->getStatusCode(), (string)$response->getBody());
            $this->assertSame(1, json_decode((string)$response->getBody(), true)['cleared']);
            $this->assertFalse($storage->exists($this->made[0]));
            $this->assertTrue($storage->exists($this->made[1]), 'Not everything.');
        }
    }

    public function testATagAsPlainTextClearsOnlyItsPages(): void
    {
        $response = $this->post(['form_params' => ['tags' => 'speedy-test-api-b']]);
        $this->assertSame(1, json_decode((string)$response->getBody(), true)['cleared']);
        $this->assertTrue(Plugin::getInstance()->storage->exists($this->made[0]));
        $this->assertFalse(Plugin::getInstance()->storage->exists($this->made[1]));
    }

    public function testAnEmptyListIsRefused(): void
    {
        foreach ([['form_params' => ['urls' => '']], ['json' => ['urls' => []]], ['json' => ['tags' => [5]]]] as $options) {
            $this->assertSame(400, $this->post($options)->getStatusCode());
        }
        $this->assertTrue(Plugin::getInstance()->storage->exists($this->made[0]), 'Nothing was cleared.');
        $this->assertTrue(Plugin::getInstance()->storage->exists($this->made[1]));
    }
}
