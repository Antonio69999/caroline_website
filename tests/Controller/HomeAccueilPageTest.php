<?php

namespace App\Tests\Controller;

use App\Entity\Accueil;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La page d'accueil publique : tant que Caroline n'a pas choisi de photo dans l'admin, le site
 * garde l'accueil d'origine (photo de fond codée dans app.css) ; une fois la photo choisie, elle
 * s'affiche comme vraie image, avec son cadrage ordinateur / téléphone.
 *
 * L'accueil n'a qu'une ligne en base : chaque test part d'une table vide et la vide après lui.
 */
class HomeAccueilPageTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        // Le client démarre le noyau : il doit exister avant tout accès au conteneur
        $this->client = static::createClient();
        $this->viderAccueil();
    }

    protected function tearDown(): void
    {
        $this->viderAccueil();

        parent::tearDown();

        // PHPUnit garde chaque objet de test jusqu'à la fin de la suite : le client porte tout le noyau, on le lâche
        unset($this->client);
    }

    private function viderAccueil(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        foreach ($em->getRepository(Accueil::class)->findAll() as $accueil) {
            $em->remove($accueil);
        }
        $em->flush();
        $em->clear();
    }

    private function enregistrer(Accueil $accueil): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($accueil);
        $em->flush();
        $em->clear();
    }

    private function assertAccueilDOrigine($crawler): void
    {
        self::assertSelectorExists('.parent-div > .main-content', 'L\'accueil d\'origine garde son conteneur à fond d\'image');
        self::assertSelectorNotExists('.accueil-hero');
        self::assertSelectorNotExists('link[href*="accueil.css"]');
        // Les deux préchargements de la photo d'origine (ordinateur / téléphone) restent en place
        $preload = $crawler->filter('link[rel="preload"][as="image"]')->each(fn ($n) => $n->attr('href'));
        self::assertSame(['/asset/images/40-2560.jpg', '/asset/images/40-mobile.jpg'], $preload);
        self::assertCount(1, $crawler->filter('h1.visually-hidden'));
    }

    public function testWithoutAnyRowTheOriginalHomeIsUnchanged(): void
    {
        $client = $this->client;
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $this->assertAccueilDOrigine($crawler);
    }

    public function testOpeningThePageInTheAdminWithoutChoosingAPhotoChangesNothing(): void
    {
        // L'admin crée la ligne dès qu'on ouvre « Page d'accueil » : tant qu'elle n'a pas de photo, rien ne bouge
        $client = $this->client;
        $this->enregistrer((new Accueil())->setBureauMode(Accueil::MODE_PLEIN)->setBureauZoom(200)->setCouleurFond('#000000'));

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $this->assertAccueilDOrigine($crawler);
    }

    public function testTheChosenPhotoIsShownAsARealImageWithItsFraming(): void
    {
        $client = $this->client;

        $accueil = (new Accueil())
            ->setDescription('Un mur couvert de lettres peintes')
            ->setBureauMode(Accueil::MODE_PLEIN)->setBureauX(30)->setBureauY(60)->setBureauZoom(150)
            ->setMobileMode(Accueil::MODE_PLEIN)->setMobileX(100)->setMobileY(0)->setMobileZoom(200)
            ->setCouleurFond('#202020');
        $accueil->setImageName('grande-photo.jpg');
        $accueil->setImageMobileName('photo-verticale.jpg');
        $this->enregistrer($accueil);

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.main-content');
        self::assertSelectorNotExists('link[rel="preload"][as="image"]', 'L\'image est dans le HTML : plus besoin de précharger l\'ancienne');
        self::assertSelectorExists('link[rel="stylesheet"][href*="accueil.css"]');

        // Une vraie image, chargée en priorité, décrite pour les lecteurs d'écran
        $image = $crawler->filter('.parent-div > .accueil-hero picture > img.accueil-hero__image');
        self::assertCount(1, $image);
        self::assertSame('/uploads/accueil/grande-photo.jpg', $image->attr('src'));
        self::assertSame('Un mur couvert de lettres peintes', $image->attr('alt'));
        self::assertSame('high', $image->attr('fetchpriority'));

        // Le téléphone reçoit sa propre photo
        $source = $crawler->filter('.accueil-hero picture > source');
        self::assertCount(1, $source);
        self::assertSame('(max-width: 767px)', $source->attr('media'));
        self::assertSame('/uploads/accueil/photo-verticale.jpg', $source->attr('srcset'));

        // Le cadrage arrive en variables CSS
        self::assertSame(
            '--accueil-fond:#202020;--bureau-fit:cover;--bureau-pos:30% 60%;--bureau-zoom:1.5;--mobile-fit:cover;--mobile-pos:100% 0%;--mobile-zoom:2',
            $crawler->filter('.accueil-hero')->attr('style'),
        );

        // Le texte pour Google et les lecteurs d'écran est toujours là, une seule fois
        self::assertCount(1, $crawler->filter('h1.visually-hidden'));
        self::assertStringContainsString('Caroline Marcellier', $crawler->filter('h1')->text());
    }

    public function testWithoutAMobilePhoteThePhoneUsesTheMainOneAndNoSourceIsWritten(): void
    {
        $client = $this->client;

        $accueil = new Accueil();
        $accueil->setImageName('seule-photo.jpg');
        $this->enregistrer($accueil);

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.accueil-hero source'));
        self::assertSame('/uploads/accueil/seule-photo.jpg', $crawler->filter('.accueil-hero img')->attr('src'));
        // Sans description, l'image est « décorative » : le texte caché de la page dit déjà tout
        self::assertSame('', $crawler->filter('.accueil-hero img')->attr('alt'));
    }

    public function testTheMenuStillWorksAroundTheChosenPhoto(): void
    {
        $client = $this->client;

        $accueil = new Accueil();
        $accueil->setImageName('photo.jpg');
        $this->enregistrer($accueil);

        $crawler = $client->request('GET', '/');

        self::assertSelectorExists('nav#main-menu');
        self::assertGreaterThanOrEqual(1, $crawler->filter('nav#main-menu a[href="/contact"]')->count());
    }
}
