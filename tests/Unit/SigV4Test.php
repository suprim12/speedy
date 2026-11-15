<?php

namespace suprim\speedy\tests\Unit;

use PHPUnit\Framework\TestCase;
use suprim\speedy\services\cdn\SigV4;

class SigV4Test extends TestCase
{
    /**
     * AWS’s own worked example for Signature Version 4 (IAM ListUsers).
     */
    public function testMatchesAwsReferenceSignature(): void
    {
        $signer = new SigV4('AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', 'us-east-1', 'iam');
        $headers = $signer->sign(
            'GET',
            'https://iam.amazonaws.com/?Action=ListUsers&Version=2010-05-08',
            ['Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8'],
            '',
            new \DateTimeImmutable('2015-08-30T12:36:00Z'),
        );

        $this->assertSame('20150830T123600Z', $headers['x-amz-date']);
        $this->assertStringContainsString('SignedHeaders=content-type;host;x-amz-date', $headers['authorization']);
        $this->assertStringEndsWith('Signature=5d672d79c15b13162d9279b0855cfba6789a8edb4c82c400e06b5924a6f2b5d7', $headers['authorization']);
    }

    public function testAddsSessionToken(): void
    {
        $signer = new SigV4('AKID', 'secret', 'us-east-1', 'cloudfront', 'session-token');
        $headers = $signer->sign('POST', 'https://cloudfront.amazonaws.com/2020-05-31/distribution/E1/invalidation', [], '<x/>');

        $this->assertSame('session-token', $headers['x-amz-security-token']);
        $this->assertStringContainsString('x-amz-security-token', $headers['authorization']);
    }
}
