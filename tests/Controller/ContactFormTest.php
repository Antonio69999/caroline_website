<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Full contact form flow: visitor -> /contact -> server-side validation ->
 * Turnstile / honeypot / rate limit -> SMTP.
 *
 * Requires a reachable database (CategorieRepository::findAll() is called
 * unconditionally by the controller), like the rest of this suite.
 */
class ContactFormTest extends WebTestCase
{
    use MailerAssertionsTrait;

    /**
     * Boots the client, wires a mocked Cloudflare response (so tests never
     * hit the real network), then loads the contact page once so the
     * anti-bot "rendered at" timestamp is recorded server-side.
     *
     * Each test gets its own simulated visitor IP so the (shared, cache-backed)
     * rate limiter bucket from one test can never bleed into another.
     *
     * @return array{0: \Symfony\Bundle\FrameworkBundle\KernelBrowser, 1: \Symfony\Component\DomCrawler\Crawler, 2: array}
     */
    private function boot(bool $turnstileSuccess = true): array
    {
        $client = static::createClient();

        $mockClient = new MockHttpClient(
            fn () => new MockResponse(json_encode(['success' => $turnstileSuccess]), ['http_code' => 200])
        );
        self::getContainer()->set(HttpClientInterface::class, $mockClient);

        $server = ['REMOTE_ADDR' => '203.0.113.' . random_int(1, 254)];
        $crawler = $client->request('GET', '/contact', [], [], $server);
        self::assertResponseIsSuccessful();

        return [$client, $crawler, $server];
    }

    private function submit(array $ctx, array $fields, ?string $turnstileToken = 'test-token'): void
    {
        [$client, $crawler, $server] = $ctx;

        $form = $crawler->selectButton('Envoyer')->form();
        foreach ($fields as $name => $value) {
            $form[$name] = $value;
        }

        $payload = $form->getPhpValues();
        if ($turnstileToken !== null) {
            $payload['cf-turnstile-response'] = $turnstileToken;
        }

        $client->request($form->getMethod(), $form->getUri(), $payload, [], $server);
    }

    public function testNormalSubmissionWithValidTurnstileSendsEmail(): void
    {
        $ctx = $this->boot(turnstileSuccess: true);

        sleep(3); // clear the anti-bot minimum-time-on-form threshold

        $this->submit($ctx, [
            'contact[sujet]' => 'Jean Dupont',
            'contact[email]' => 'jean.dupont@example.com',
            'contact[message]' => 'Bonjour, je suis intéressé par une de vos œuvres, voir https://example.com/oeuvre.',
        ]);

        self::assertResponseRedirects('/contact');
        self::assertEmailCount(1);

        /** @var \Symfony\Component\Mime\Email $email */
        $email = self::getMailerMessage(0);
        self::assertSame('contact@carottecake.com', $email->getFrom()[0]->getAddress());
        self::assertSame('jean.dupont@example.com', $email->getReplyTo()[0]->getAddress());
    }

    public function testMissingTurnstileTokenBlocksEmail(): void
    {
        $ctx = $this->boot(turnstileSuccess: true);

        sleep(3);

        $this->submit($ctx, [
            'contact[sujet]' => 'Jean Dupont',
            'contact[email]' => 'jean.dupont@example.com',
            'contact[message]' => 'Bonjour, un message tout à fait normal.',
        ], turnstileToken: null);

        self::assertEmailCount(0);
    }

    public function testInvalidTurnstileTokenBlocksEmail(): void
    {
        $ctx = $this->boot(turnstileSuccess: false);

        sleep(3);

        $this->submit($ctx, [
            'contact[sujet]' => 'Jean Dupont',
            'contact[email]' => 'jean.dupont@example.com',
            'contact[message]' => 'Bonjour, un message tout à fait normal.',
        ]);

        self::assertEmailCount(0);
    }

    public function testHoneypotFilledBlocksEmailButLooksLikeSuccess(): void
    {
        $ctx = $this->boot(turnstileSuccess: true);

        sleep(3);

        $this->submit($ctx, [
            'contact[sujet]' => 'Bot Spammer',
            'contact[email]' => 'bot@example.com',
            'contact[message]' => 'Spam message.',
            'contact[website]' => 'http://spam-bot.example',
        ]);

        self::assertResponseRedirects('/contact'); // looks like a normal success
        self::assertEmailCount(0);
    }

    public function testTooManyRequestsIsRateLimited(): void
    {
        $ctx = $this->boot(turnstileSuccess: true);

        sleep(3);

        for ($i = 0; $i < 5; ++$i) {
            $this->submit($ctx, [
                'contact[sujet]' => 'Jean Dupont',
                'contact[email]' => 'jean.dupont@example.com',
                'contact[message]' => 'Message numéro ' . $i . ' tout à fait normal.',
            ]);
        }

        $this->submit($ctx, [
            'contact[sujet]' => 'Jean Dupont',
            'contact[email]' => 'jean.dupont@example.com',
            'contact[message]' => 'Encore un message normal.',
        ]);

        self::assertResponseStatusCodeSame(429);
    }

    public function testInvalidEmailIsRejected(): void
    {
        $ctx = $this->boot(turnstileSuccess: true);

        sleep(3);

        $this->submit($ctx, [
            'contact[sujet]' => 'Jean Dupont',
            'contact[email]' => 'not-an-email',
            'contact[message]' => 'Bonjour, un message tout à fait normal.',
        ]);

        self::assertResponseIsSuccessful(); // form redisplayed with errors, not a redirect
        self::assertEmailCount(0);
    }

    public function testMessageWithOneLinkIsAccepted(): void
    {
        $ctx = $this->boot(turnstileSuccess: true);

        sleep(3);

        $this->submit($ctx, [
            'contact[sujet]' => 'Jean Dupont',
            'contact[email]' => 'jean.dupont@example.com',
            'contact[message]' => 'Voici un lien vers mon travail : https://example.com/portfolio, merci !',
        ]);

        self::assertResponseRedirects('/contact');
        self::assertEmailCount(1);
    }

    public function testSpamMessageWithManyLinksIsRejected(): void
    {
        $ctx = $this->boot(turnstileSuccess: true);

        sleep(3);

        $this->submit($ctx, [
            'contact[sujet]' => 'Спам',
            'contact[email]' => 'spam@example.com',
            'contact[message]' => 'http://a.ru http://b.ru http://c.ru http://d.ru http://e.ru http://f.ru',
        ]);

        self::assertResponseIsSuccessful();
        self::assertEmailCount(0);
    }

    public function testHeaderInjectionInEmailFieldIsRejected(): void
    {
        $ctx = $this->boot(turnstileSuccess: true);

        sleep(3);

        $this->submit($ctx, [
            'contact[sujet]' => 'Jean Dupont',
            'contact[email]' => "jean@example.com\r\nBcc: victim@example.com",
            'contact[message]' => 'Bonjour, un message tout à fait normal.',
        ]);

        self::assertResponseIsSuccessful();
        self::assertEmailCount(0);
    }
}
