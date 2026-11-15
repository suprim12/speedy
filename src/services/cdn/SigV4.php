<?php
/**
 * Speedy Cache plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\speedy\services\cdn;

/**
 * Signs AWS requests with Signature Version 4, so Speedy needs no AWS SDK.
 *
 * @see https://docs.aws.amazon.com/IAM/latest/UserGuide/reference_sigv-create-signed-request.html
 */
class SigV4
{
    public function __construct(
        private string $accessKey,
        private string $secretKey,
        private string $region,
        private string $service,
        private ?string $sessionToken = null,
    ) {
    }

    /**
     * Headers to add to the request, including Authorization.
     *
     * @param array<string, string> $headers Headers the request already has (Host is added if missing).
     * @return array<string, string>
     */
    public function sign(string $method, string $url, array $headers, string $body, ?\DateTimeInterface $now = null): array
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '/';
        $query = $parts['query'] ?? '';
        $now = $now ? \DateTimeImmutable::createFromInterface($now) : new \DateTimeImmutable('now');
        $now = $now->setTimezone(new \DateTimeZone('UTC'));
        $amzDate = $now->format('Ymd\THis\Z');
        $date = $now->format('Ymd');

        $headers = array_change_key_case($headers, CASE_LOWER);
        $headers['host'] ??= $host;
        $headers['x-amz-date'] = $amzDate;
        if ($this->sessionToken) {
            $headers['x-amz-security-token'] = $this->sessionToken;
        }
        ksort($headers);

        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name . ':' . trim(preg_replace('/\s+/', ' ', $value)) . "\n";
        }
        $signedHeaders = implode(';', array_keys($headers));

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $this->canonicalPath($path),
            $this->canonicalQuery($query),
            $canonicalHeaders,
            $signedHeaders,
            hash('sha256', $body),
        ]);

        $scope = "$date/$this->region/$this->service/aws4_request";
        $stringToSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonicalRequest)]);

        $key = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $key = hash_hmac('sha256', $this->region, $key, true);
        $key = hash_hmac('sha256', $this->service, $key, true);
        $key = hash_hmac('sha256', 'aws4_request', $key, true);
        $signature = hash_hmac('sha256', $stringToSign, $key);

        $headers['authorization'] = "AWS4-HMAC-SHA256 Credential=$this->accessKey/$scope, SignedHeaders=$signedHeaders, Signature=$signature";

        return $headers;
    }

    private function canonicalPath(string $path): string
    {
        $segments = explode('/', $path);

        return implode('/', array_map(fn($s) => rawurlencode(rawurldecode($s)), $segments));
    }

    private function canonicalQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }
        $pairs = [];
        foreach (explode('&', $query) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $pairs[] = [rawurlencode(rawurldecode($k)), rawurlencode(rawurldecode($v))];
        }
        usort($pairs, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return implode('&', array_map(fn($p) => $p[0] . '=' . $p[1], $pairs));
    }
}
