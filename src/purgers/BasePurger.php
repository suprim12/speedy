<?php
/**
 * Speedy Cache plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\speedy\purgers;

use Craft;
use craft\web\Request;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use suprim\speedy\models\Settings;
use suprim\speedy\Plugin;

/**
 * Shared helpers for purgers.
 */
abstract class BasePurger implements PurgerInterface
{
    /** The HTTP client, replaceable in tests. */
    public ?Client $client = null;

    public function detect(Request $request): bool
    {
        return false;
    }

    protected function settings(): Settings
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        return $settings;
    }

    protected function client(): Client
    {
        return $this->client ??= Craft::createGuzzleClient(['timeout' => 20, 'http_errors' => false]);
    }

    /**
     * Throws with the service’s own message when a request failed.
     */
    protected function check(ResponseInterface $response): ResponseInterface
    {
        if ($response->getStatusCode() >= 300) {
            $body = (string)$response->getBody();
            $data = json_decode($body, true);
            $message = is_array($data) ? ($data['msg'] ?? $data['Message'] ?? $data['message'] ?? $data['detail'] ?? null) : null;
            throw new RuntimeException(is_string($message) && $message !== '' ? $message : 'HTTP ' . $response->getStatusCode());
        }

        return $response;
    }

    protected function cleared(int $count): string
    {
        return Craft::t('speedy', 'Cleared {count, plural, =1{# URL} other{# URLs}}', ['count' => $count]);
    }
}
