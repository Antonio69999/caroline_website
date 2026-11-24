<?php

namespace App\Tests\Controller;

use Symfony\Bridge\Twig\ErrorRenderer\TwigErrorRenderer;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * La page que voient les visiteurs quand ça ne va pas : la petite carotte qui danse
 * (templates/bundles/TwigBundle/Exception/error.html.twig), et non la page grise de Symfony.
 *
 * Le noyau tourne SANS le mode debug : c'est ce que voit un visiteur en production (en debug,
 * Symfony montre à la place sa page d'erreur détaillée, pour le développeur).
 *
 * On passe par le moteur de rendu d'erreurs de Twig, celui que Symfony appelle en production,
 * et non par une requête du client de test : PHPUnit tourne en ligne de commande, où Symfony
 * remplace la page HTML par un affichage console de l'exception.
 */
class ErrorPagesTest extends WebTestCase
{
    public function testAnUnknownPageShowsTheFriendlyNotFoundPage(): void
    {
        [$code, $html] = $this->pageD('404', new NotFoundHttpException('No route found for "GET /cette-page-n-existe-pas"'));
        $page = new Crawler($html);

        self::assertSame(404, $code);
        self::assertStringContainsString('cette page n’existe pas', $page->filter('h1')->text());
        self::assertCount(1, $page->filter('img[src="/asset/krot.gif"]'));
        self::assertCount(1, $page->filter('a.oups__bouton[href="/"]'));
        // C'est bien la nôtre, pas la page anglaise de Symfony
        self::assertStringNotContainsString('An Error Occurred', $html);
        self::assertStringNotContainsString('No route found', $html);
    }

    public function testTheErrorPageStaysOutOfGoogleAndTranslation(): void
    {
        [, $html] = $this->pageD('404', new NotFoundHttpException());
        $page = new Crawler($html);

        self::assertCount(1, $page->filter('meta[name="robots"][content*="noindex"]'));
        self::assertSame('no', $page->filter('html')->attr('translate'));
    }

    /**
     * Une erreur de serveur (500) : le message doit rassurer et proposer d'écrire à Caroline,
     * sans jamais laisser filtrer le message technique de l'exception.
     */
    public function testAServerErrorSaysSomethingIsBrokenWithoutLeakingDetails(): void
    {
        [$code, $html] = $this->pageD('500', new \RuntimeException('SQLSTATE[HY000] mot-de-passe-secret'));

        self::assertSame(500, $code);
        self::assertStringContainsString('Oups, ça ne marche pas', $html);
        self::assertStringContainsString('/asset/krot.gif', $html);
        self::assertStringContainsString('href="/contact"', $html);
        self::assertStringContainsString('Retour à l’accueil', $html);
        self::assertStringNotContainsString('SQLSTATE', $html);
        self::assertStringNotContainsString('mot-de-passe-secret', $html);
    }

    public function testEachKindOfErrorHasItsOwnWords(): void
    {
        [, $introuvable] = $this->pageD('404', new NotFoundHttpException());
        [, $interdit] = $this->pageD('403', new AccessDeniedHttpException());

        self::assertStringContainsString('cette page n’existe pas', $introuvable);
        self::assertStringContainsString('cette porte est fermée', $interdit);

        // Le « écrivez-moi » n'est là que quand c'est le site qui est en panne
        self::assertStringNotContainsString('href="/contact"', $introuvable);
        self::assertStringNotContainsString('href="/contact"', $interdit);
    }

    /** Un code d'erreur sans mots prévus (ici 400) tombe sur un message général, pas sur la page de Symfony. */
    public function testAnyOtherErrorStillGetsTheFriendlyPage(): void
    {
        $twig = static::getContainer()->get('twig');
        $html = $twig->render('@Twig/Exception/error.html.twig', ['status_code' => 400, 'status_text' => 'Bad Request']);

        self::assertStringContainsString('quelque chose s’est mal passé', $html);
        self::assertStringContainsString('/asset/krot.gif', $html);
        self::assertStringNotContainsString('href="/contact"', $html);
    }

    /**
     * La page d'erreur ne doit dépendre de rien : ni menu, ni base de données. Sinon une panne
     * du site la ferait tomber avec lui et on retrouverait la page grise de Symfony.
     */
    public function testTheErrorPageIsSelfContained(): void
    {
        [, $html] = $this->pageD('500', new \RuntimeException('panne'));

        self::assertStringNotContainsString('main-menu', $html);
        self::assertStringNotContainsString('id="carottes"', $html);
        self::assertStringNotContainsString('googletagmanager', $html);
    }

    /**
     * @return array{0: int, 1: string} le code HTTP et la page, tels que Symfony les fabrique en production
     */
    private function pageD(string $attendu, \Throwable $exception): array
    {
        static::bootKernel(['debug' => false]);

        /** @var TwigErrorRenderer $rendu */
        $rendu = static::getContainer()->get('twig.error_renderer.html');
        $erreur = $rendu->render($exception);

        self::assertSame((int) $attendu, $erreur->getStatusCode());

        return [$erreur->getStatusCode(), $erreur->getAsString()];
    }
}
