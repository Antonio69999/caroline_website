<?php

namespace App\Tests\Service\Contact;

use App\Service\Contact\TurnstileVerifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class TurnstileVerifierTest extends TestCase
{
    public function testMissingTokenFailsWithoutCallingCloudflare(): void
    {
        $httpClient = new MockHttpClient(function () {
            self::fail('Cloudflare should not be called when there is no token.');
        });

        $verifier = new TurnstileVerifier($httpClient, 'a-secret', new NullLogger());

        self::assertFalse($verifier->verify(null, '1.2.3.4'));
        self::assertFalse($verifier->verify('', '1.2.3.4'));
        self::assertFalse($verifier->verify('   ', '1.2.3.4'));
    }

    public function testEmptySecretKeyFailsClosedWithoutCallingCloudflare(): void
    {
        $httpClient = new MockHttpClient(function () {
            self::fail('Cloudflare should not be called when the secret key is not configured.');
        });

        $verifier = new TurnstileVerifier($httpClient, '', new NullLogger());

        self::assertFalse($verifier->verify('some-token', '1.2.3.4'));
    }

    public function testValidTokenIsAccepted(): void
    {
        $httpClient = new MockHttpClient(
            fn () => new MockResponse(json_encode(['success' => true]), ['http_code' => 200])
        );

        $verifier = new TurnstileVerifier($httpClient, 'a-secret', new NullLogger());

        self::assertTrue($verifier->verify('valid-token', '1.2.3.4'));
    }

    public function testCloudflareRejectionIsRefused(): void
    {
        $httpClient = new MockHttpClient(
            fn () => new MockResponse(json_encode(['success' => false, 'error-codes' => ['invalid-input-response']]), ['http_code' => 200])
        );

        $verifier = new TurnstileVerifier($httpClient, 'a-secret', new NullLogger());

        self::assertFalse($verifier->verify('bad-token', '1.2.3.4'));
    }

    public function testNetworkFailureFailsClosed(): void
    {
        $httpClient = new MockHttpClient(
            fn () => throw new TransportException('connection refused')
        );

        $verifier = new TurnstileVerifier($httpClient, 'a-secret', new NullLogger());

        self::assertFalse($verifier->verify('some-token', '1.2.3.4'));
    }
}
