<?php
/**
 * Speedy Cache plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\speedy\services\cdn;

use craft\helpers\Json;
use GuzzleHttp\ClientInterface;

/**
 * Adds the Cache Rules that let Cloudflare keep a zone’s pages: one makes the sites’ pages eligible, kept as long as
 * their Cache-Control header says and not at all without one; the other leaves out logged-in visitors, carts, forms,
 * Ajax requests, the control panel and actions. Other rules in the zone are left as they are.
 */
class CloudflareRules
{
    /** The rules’ references, so they’re found and updated rather than added again. */
    public const PAGES_REF = 'speedy_cache_pages';
    public const BYPASS_REF = 'speedy_cache_bypass';

    private const PHASE = 'http_request_cache_settings';

    /** Headers Ajax and partial-page requests send, which get their own responses. */
    private const AJAX_HEADERS = ['x-requested-with', 'hx-request', 'x-up-target'];

    public function __construct(
        private ClientInterface $client,
        private string $token,
    ) {
    }

    /**
     * The rules for these hosts.
     *
     * @param string[] $hosts Such as `example.com` and `www.example.com`.
     * @param string[] $paths Paths that are never kept, such as `/admin`.
     * @param string[] $cookies Parts of cookie names that mean a visitor gets their own pages.
     * @return array<string, array<string, mixed>> By reference.
     */
    public static function rules(array $hosts, array $paths, array $cookies): array
    {
        $quote = fn(string $s) => '"' . addcslashes($s, '"\\') . '"';
        $hostMatch = '(http.host in {' . implode(' ', array_map($quote, $hosts)) . '})';
        $bypass = array_merge(
            array_map(fn($c) => 'http.cookie contains ' . $quote($c), $cookies),
            array_map(fn($p) => 'starts_with(http.request.uri.path, ' . $quote($p) . ')', $paths),
            ['any(lower(http.request.headers.names[*])[*] in {' . implode(' ', array_map($quote, self::AJAX_HEADERS)) . '})'],
        );

        return [
            self::PAGES_REF => [
                'ref' => self::PAGES_REF,
                'description' => 'Speedy Cache: keep pages for as long as their Cache-Control header says',
                'expression' => $hostMatch,
                'action' => 'set_cache_settings',
                'action_parameters' => [
                    'cache' => true,
                    // Pages without a Cache-Control header aren’t kept: Speedy sends one only with pages it can clear.
                    'edge_ttl' => ['mode' => 'bypass_by_default'],
                ],
                'enabled' => true,
            ],
            self::BYPASS_REF => [
                'ref' => self::BYPASS_REF,
                'description' => 'Speedy Cache: logged-in visitors, carts, forms, Ajax, the control panel and actions get their own pages',
                'expression' => $hostMatch . ' and (' . implode(' or ', $bypass) . ')',
                'action' => 'set_cache_settings',
                'action_parameters' => ['cache' => false],
                'enabled' => true,
            ],
        ];
    }

    /**
     * Adds the rules to a zone, or updates them when they’re there. Later cache rules win, so the bypass rule comes
     * after the pages rule.
     *
     * @param array<string, array<string, mixed>> $rules From rules().
     * @return bool Whether the rules were already there.
     */
    public function apply(string $zone, array $rules): bool
    {
        $base = 'https://api.cloudflare.com/client/v4/zones/' . rawurlencode($zone) . '/rulesets';
        $entrypoint = $this->send('GET', "$base/phases/" . self::PHASE . '/entrypoint', null, true);
        if ($entrypoint === null) {
            // The zone has no cache rules yet.
            $this->send('PUT', "$base/phases/" . self::PHASE . '/entrypoint', ['rules' => array_values($rules)]);
            return false;
        }

        $existing = [];
        foreach ($entrypoint['rules'] ?? [] as $rule) {
            if (isset($rules[$rule['ref'] ?? ''])) {
                $existing[$rule['ref']] = (string)$rule['id'];
            }
        }
        $ruleset = rawurlencode((string)$entrypoint['id']);
        foreach ($rules as $ref => $rule) {
            if (isset($existing[$ref])) {
                $this->send('PATCH', "$base/$ruleset/rules/" . rawurlencode($existing[$ref]), $rule);
                continue;
            }
            if ($ref === self::PAGES_REF && isset($existing[self::BYPASS_REF])) {
                $rule['position'] = ['before' => $existing[self::BYPASS_REF]];
            }
            $this->send('POST', "$base/$ruleset/rules", $rule);
        }

        return count($existing) === count($rules);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>|null The result, or null for a 404 when that’s allowed.
     */
    private function send(string $method, string $url, ?array $body, bool $allowMissing = false): ?array
    {
        $response = $this->client->request($method, $url, array_filter([
            'headers' => ['Authorization' => 'Bearer ' . $this->token, 'Content-Type' => 'application/json'],
            'body' => $body !== null ? Json::encode($body) : null,
            'http_errors' => false,
        ], fn($v) => $v !== null));
        $status = $response->getStatusCode();
        if ($status === 404 && $allowMissing) {
            return null;
        }
        $data = Json::decodeIfJson((string)$response->getBody());
        if ($status >= 300 || !is_array($data) || !($data['success'] ?? false)) {
            if ($status === 403 || in_array($data['errors'][0]['code'] ?? null, [10000, 9109], true)) {
                throw new \RuntimeException('The Cloudflare token can’t edit cache rules. Add the permission Zone → Cache Rules → Edit to it.');
            }
            throw new \RuntimeException((string)($data['errors'][0]['message'] ?? "Cloudflare answered with status $status."));
        }

        return is_array($data['result'] ?? null) ? $data['result'] : [];
    }
}
