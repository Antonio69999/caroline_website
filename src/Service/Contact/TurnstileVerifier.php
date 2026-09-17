<?php

namespace App\Service\Contact;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Verifies a Cloudflare Turnstile token server-side against Cloudflare's
 * siteverify API. Fails closed: any missing token, misconfiguration, network
 * error, or rejection from Cloudflare is treated as "not human".
 */
final class TurnstileVerifier
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'TURNSTILE_SECRET_KEY')]
        private readonly string $secretKey,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function verify(?string $token, ?string $remoteIp): bool
    {
        if ($token === null || trim($token) === '') {
            return false;
        }

        if (trim($this->secretKey) === '') {
            $this->logger->warning('contact.turnstile.misconfigured');

            return false;
        }

        try {
            $response = $this->httpClient->request('POST', self::VERIFY_URL, [
                'body' => array_filter([
                    'secret' => $this->secretKey,
                    'response' => $token,
                    'remoteip' => $remoteIp,
                ]),
                'timeout' => 5,
            ]);

            $data = $response->toArray(false);
        } catch (HttpExceptionInterface $e) {
            $this->logger->warning('contact.turnstile.request_failed', ['reason' => $e->getMessage()]);

            return false;
        }

        if (($data['success'] ?? false) !== true) {
            $this->logger->info('contact.turnstile.rejected', ['error_codes' => $data['error-codes'] ?? []]);

            return false;
        }

        return true;
    }
}
