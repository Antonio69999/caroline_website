<?php

namespace App\Tests\Controller;

use App\Entity\Admin;
use App\Entity\Article;
use App\Entity\Carotte;
use App\Entity\Categorie;
use App\Entity\Media;
use App\Seo\SiteUrls;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Les carottes cachées sur le site public : une carotte ne se montre que sur la page qu'elle vise
 * (« partout », l'accueil, une catégorie ou un article), jamais si elle est rangée de côté ou sans
 * image, jamais sur la page de connexion, et son aperçu (?carotte=N) est réservé à l'administrateur.
 *
 * La table des carottes est vidée avant et après chaque test ; les catégories et articles
 * créés par un test sont supprimés après lui.
 */
class CarottesPageTest extends WebTestCase
{
    private KernelBrowser $client;

    /** @var list<object> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Le client démarre le noyau : il doit exister avant tout accès au conteneur
        $this->client = static::createClient();
        $this->viderCarottes();
    }

    protected function tearDown(): void
    {
        $this->viderCarottes();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        foreach (array_reverse($this->created) as $entity) {
            $managed = $em->find($entity::class, $entity->getId());
            if ($managed) {
                $em->remove($managed);
                $em->flush();
            }
        }

        parent::tearDown();

        // PHPUnit garde chaque objet de test jusqu'à la fin de la suite : le client porte tout le noyau, on le lâche
        unset($this->client);
        $this->created = [];
    }

    private function viderCarottes(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        foreach ($em->getRepository(Carotte::class)->findAll() as $carotte) {
            $em->remove($carotte);
        }
        $em->flush();
        $em->clear();
    }

    private function persist(object ...$entities): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ($entities as $entity) {
            $em->persist($entity);
            if (!$entity instanceof Carotte) {
                $this->created[] = $entity;
            }
        }
        $em->flush();
        $em->clear();
    }

    private function categorie(string $titre): Categorie
    {
        $categorie = (new Categorie())->setTitre($titre)->setPosition(9800);
        $this->persist($categorie);

        return $categorie;
    }

    private function article(Categorie $categorie, string $titre, bool $publie = true): Article
    {
        // persist() vide le gestionnaire d'entités (la page relit tout depuis la base) : on rattache par référence
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $article = (new Article())->setTitre($titre)->setCategorie($em->getReference(Categorie::class, $categorie->getId()))->setPosition(0)->setPublie($publie);
        $this->persist($article);

        $media = new Media();
        $media->setImageName(sprintf('carotte-test-%s.jpg', uniqid()));
        $media->setLegende($titre)->setPosition(0)->setArticle($em->getReference(Article::class, $article->getId()));
        $this->persist($media);

        return $article;
    }

    private function carotte(string $nom, string $cible, bool $actif = true, ?string $image = 'image.jpg'): Carotte
    {
        $carotte = (new Carotte())->setNom($nom)->setCible($cible)->setActif($actif);
        $carotte->setImageName($image);
        $this->persist($carotte);

        return $carotte;
    }

    /** @return list<string> les noms (data-image) des images des carottes de la page */
    private function images(Crawler $crawler): array
    {
        return $crawler->filter('#carottes .carotte')->each(fn (Crawler $n) => $n->attr('data-image'));
    }

    private function adresseArticle(Article $article): string
    {
        return static::getContainer()->get(SiteUrls::class)->articlePath($article);
    }

    private function adresseCategorie(Categorie $categorie): string
    {
        return static::getContainer()->get(SiteUrls::class)->categoryPath($categorie);
    }

    private function connecter(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->client->loginUser($em->getRepository(Admin::class)->findOneBy([]));
    }

    public function testWithoutAnyCarrotThePagesWriteNothingAboutThem(): void
    {
        $categorie = $this->categorie('Galerie sans carotte');
        $article = $this->article($categorie, 'Article sans carotte');

        foreach (['/', $this->adresseCategorie($categorie), $this->adresseArticle($article), '/contact'] as $adresse) {
            $crawler = $this->client->request('GET', $adresse);

            self::assertResponseIsSuccessful($adresse);
            self::assertSelectorNotExists('#carottes', $adresse);
            self::assertSelectorNotExists('#carotteFenetre', $adresse);
            self::assertSelectorNotExists('link[href*="carottes.css"]', $adresse);
            self::assertSelectorNotExists('script[src*="carottes.js"]', $adresse);
            self::assertCount(0, $crawler->filter('.carotte'), $adresse);
        }
    }

    public function testACarrotForEverywhereShowsOnEveryPublicPage(): void
    {
        $categorie = $this->categorie('Galerie partout');
        $article = $this->article($categorie, 'Article partout');
        $this->carotte('Partout', Carotte::CIBLE_PARTOUT, true, 'partout.jpg');

        foreach (['/', $this->adresseCategorie($categorie), $this->adresseArticle($article), '/contact'] as $adresse) {
            $crawler = $this->client->request('GET', $adresse);

            self::assertResponseIsSuccessful($adresse);
            self::assertSame(['/uploads/carottes/partout.jpg'], $this->images($crawler), $adresse);
        }
    }

    public function testACarrotForTheHomePageShowsOnlyThere(): void
    {
        $categorie = $this->categorie('Galerie accueil');
        $article = $this->article($categorie, 'Article accueil');
        $this->carotte('Accueil', Carotte::CIBLE_ACCUEIL, true, 'accueil.jpg');

        self::assertSame(['/uploads/carottes/accueil.jpg'], $this->images($this->client->request('GET', '/')));
        self::assertSame([], $this->images($this->client->request('GET', $this->adresseCategorie($categorie))));
        self::assertSame([], $this->images($this->client->request('GET', $this->adresseArticle($article))));
        self::assertSame([], $this->images($this->client->request('GET', '/contact')));
    }

    public function testACarrotForAnArticleShowsOnThatArticleOnly(): void
    {
        $categorie = $this->categorie('Galerie articles');
        $voulu = $this->article($categorie, 'Les dames de Chavanelles');
        $autre = $this->article($categorie, 'Un autre article');
        $this->carotte('Sauce karote', Carotte::cibleDe(Carotte::CIBLE_ARTICLE, $voulu->getId()), true, 'sauce.jpg');

        self::assertSame(['/uploads/carottes/sauce.jpg'], $this->images($this->client->request('GET', $this->adresseArticle($voulu))));
        self::assertSame([], $this->images($this->client->request('GET', $this->adresseArticle($autre))));
        // Ni sur sa catégorie, ni sur l'accueil
        self::assertSame([], $this->images($this->client->request('GET', $this->adresseCategorie($categorie))));
        self::assertSame([], $this->images($this->client->request('GET', '/')));
    }

    public function testACarrotForACategoryShowsOnThatCategoryOnly(): void
    {
        $voulue = $this->categorie('Catégorie voulue');
        $autre = $this->categorie('Autre catégorie');
        $article = $this->article($voulue, 'Article de la catégorie voulue');
        $this->carotte('Catégorie', Carotte::cibleDe(Carotte::CIBLE_CATEGORIE, $voulue->getId()), true, 'cat.jpg');

        self::assertSame(['/uploads/carottes/cat.jpg'], $this->images($this->client->request('GET', $this->adresseCategorie($voulue))));
        self::assertSame([], $this->images($this->client->request('GET', $this->adresseCategorie($autre))));
        // Les articles de la catégorie n'héritent pas de sa carotte
        self::assertSame([], $this->images($this->client->request('GET', $this->adresseArticle($article))));
    }

    public function testTheNumberIsNotMixedUpBetweenACategoryAndAnArticle(): void
    {
        $categorie = $this->categorie('Galerie numéros');
        $article = $this->article($categorie, 'Article numéros');
        // Une carotte vise « categorie:N » avec le même numéro que l'article : seule la catégorie la montre
        $this->carotte('Numéro', Carotte::cibleDe(Carotte::CIBLE_CATEGORIE, $article->getId()), true, 'numero.jpg');

        self::assertSame([], $this->images($this->client->request('GET', $this->adresseArticle($article))));
    }

    public function testAnInactiveCarrotAndACarrotWithoutImageAreNeverShown(): void
    {
        $this->carotte('Rangée', Carotte::CIBLE_PARTOUT, false, 'rangee.jpg');
        $this->carotte('Sans image', Carotte::CIBLE_PARTOUT, true, null);
        $this->carotte('Visible', Carotte::CIBLE_PARTOUT, true, 'visible.jpg');

        self::assertSame(['/uploads/carottes/visible.jpg'], $this->images($this->client->request('GET', '/')));
    }

    public function testACarrotWhosePageWasDeletedShowsNowhereAndBreaksNothing(): void
    {
        $categorie = $this->categorie('Galerie supprimée');
        $article = $this->article($categorie, 'Article supprimé');
        $this->carotte('Orpheline', Carotte::cibleDe(Carotte::CIBLE_ARTICLE, $article->getId()), true, 'orpheline.jpg');
        $adresse = $this->adresseArticle($article);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $em->remove($em->find(Article::class, $article->getId()));
        $em->flush();
        $em->clear();

        $this->client->request('GET', $adresse);
        self::assertResponseStatusCodeSame(404);

        // Le reste du site n'a rien remarqué
        self::assertSame([], $this->images($this->client->request('GET', '/')));
        self::assertResponseIsSuccessful();
    }

    public function testEachCarrotCarriesItsOwnSettingsAsAttributes(): void
    {
        $carotte = $this->carotte('Réglée', Carotte::CIBLE_ACCUEIL, true, 'reglee.jpg');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $carotte = $em->find(Carotte::class, $carotte->getId());
        $carotte->setCarotteTaille('minuscule')->setCarotteOpacite(10)->setCarottePlace('milieu-gauche')->setDelai(12)
            ->setImageTaille('carte')->setImagePlace('haut-droite')->setDescription('Un mur gris « pas de… »');
        $em->flush();
        $em->clear();

        $crawler = $this->client->request('GET', '/');
        $bouton = $crawler->filter('#carottes button.carotte');

        self::assertCount(1, $bouton);
        self::assertNotNull($bouton->attr('hidden'), 'Cachée tant que le script ne la montre pas');
        self::assertSame('minuscule', $bouton->attr('data-taille'));
        self::assertSame('milieu', $bouton->attr('data-ligne'));
        self::assertSame('gauche', $bouton->attr('data-colonne'));
        self::assertSame('12', $bouton->attr('data-delai'));
        self::assertSame('0', $bouton->attr('data-forcee'));
        self::assertStringContainsString('--carotte-opacite: 0.1', $bouton->attr('style'));
        self::assertSame('carte', $bouton->attr('data-image-taille'));
        self::assertSame('haut', $bouton->attr('data-image-ligne'));
        self::assertSame('droite', $bouton->attr('data-image-colonne'));
        self::assertSame('Un mur gris « pas de… »', $bouton->attr('data-description'));
        self::assertSame((string) $carotte->getId(), $bouton->attr('data-carotte'));

        // L'image n'est pas chargée tant qu'on ne clique pas : aucune <img> ne la porte dans la page
        self::assertCount(0, $crawler->filter('img[src*="/uploads/carottes/"]'));
        self::assertSelectorExists('#carotteFenetre[hidden][role="dialog"]');
        self::assertSelectorExists('link[rel="stylesheet"][href*="carottes.css"]');
        self::assertSelectorExists('script[src*="carottes.js"][defer]');
    }

    public function testSeveralCarrotsOnTheSamePageAreAllWrittenInOrder(): void
    {
        $this->carotte('Première', Carotte::CIBLE_ACCUEIL, true, 'un.jpg');
        $this->carotte('Deuxième', Carotte::CIBLE_PARTOUT, true, 'deux.jpg');
        $this->carotte('Troisième', Carotte::CIBLE_ACCUEIL, true, 'trois.jpg');

        self::assertSame(
            ['/uploads/carottes/un.jpg', '/uploads/carottes/deux.jpg', '/uploads/carottes/trois.jpg'],
            $this->images($this->client->request('GET', '/')),
        );
    }

    public function testARandomCarrotGetsNewSettingsOnEachVisit(): void
    {
        $carotte = $this->carotte('Hasard', Carotte::CIBLE_ACCUEIL, true, 'hasard.jpg');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->find(Carotte::class, $carotte->getId())->setModeReglage(Carotte::MODE_HASARD);
        $em->flush();
        $em->clear();

        $vus = [];
        for ($i = 0; $i < 25; ++$i) {
            $bouton = $this->client->request('GET', '/')->filter('#carottes button.carotte');
            self::assertCount(1, $bouton);

            self::assertContains($bouton->attr('data-taille'), Carotte::TAILLES_CAROTTE);
            self::assertContains($bouton->attr('data-ligne') . '-' . $bouton->attr('data-colonne'), Carotte::PLACES_CAROTTE);
            self::assertContains($bouton->attr('data-image-taille'), Carotte::TAILLES_IMAGE);
            $vus[$bouton->attr('data-taille') . '|' . $bouton->attr('data-ligne') . '|' . $bouton->attr('data-colonne') . '|' . $bouton->attr('style')] = true;
        }

        self::assertGreaterThan(1, count($vus), 'Les réglages doivent changer d\'une visite à l\'autre');
    }

    public function testThereIsNoCarrotOnTheLoginPage(): void
    {
        $this->carotte('Partout', Carotte::CIBLE_PARTOUT, true, 'partout.jpg');

        // Comme une personne non connectée qui ouvre l'admin : on est renvoyé vers la vraie page de connexion
        $this->client->request('GET', '/mon_petit_site');
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();

        // On est bien sur la page de connexion (elle charge son canard) et non sur une page d'erreur
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('script[src*="duck.js"]');

        self::assertSelectorNotExists('#carottes');
        self::assertSelectorNotExists('script[src*="carottes.js"]');
        self::assertCount(0, $crawler->filter('.carotte'));
    }

    public function testThePreviewShowsAnInactiveCarrotButOnlyToAnAdministrator(): void
    {
        $rangee = $this->carotte('Rangée', Carotte::CIBLE_ACCUEIL, false, 'rangee.jpg');
        $adresse = '/?carotte=' . $rangee->getId();

        // Un visiteur ne la voit pas, même en connaissant le numéro
        self::assertSame([], $this->images($this->client->request('GET', $adresse)));

        $this->connecter();
        $crawler = $this->client->request('GET', $adresse);

        self::assertSame(['/uploads/carottes/rangee.jpg'], $this->images($crawler));
        self::assertSame('1', $crawler->filter('#carottes .carotte')->attr('data-forcee'));

        // Sans le paramètre, l'administrateur la voit comme tout le monde : pas du tout
        self::assertSame([], $this->images($this->client->request('GET', '/')));
    }

    public function testThePreviewDoesNotMakeACarrotAppearOnAnotherPage(): void
    {
        $categorie = $this->categorie('Galerie aperçu');
        $article = $this->article($categorie, 'Article aperçu');
        $carotte = $this->carotte('Aperçu', Carotte::cibleDe(Carotte::CIBLE_ARTICLE, $article->getId()), false, 'apercu.jpg');

        $this->connecter();

        // L'aperçu d'une carotte d'article sur l'accueil ne la montre pas : elle ne vise pas cette page
        self::assertSame([], $this->images($this->client->request('GET', '/?carotte=' . $carotte->getId())));
        self::assertSame(['/uploads/carottes/apercu.jpg'], $this->images($this->client->request('GET', $this->adresseArticle($article) . '?carotte=' . $carotte->getId())));
    }

    public function testAGarbageCarrotNumberInThePreviewChangesNothing(): void
    {
        $this->carotte('Visible', Carotte::CIBLE_ACCUEIL, true, 'visible.jpg');
        $this->connecter();

        foreach (['abc', '0', '-3', '99999999999', '1;DROP TABLE carotte', '[]'] as $valeur) {
            $this->client->request('GET', '/?carotte=' . rawurlencode($valeur));
            self::assertResponseIsSuccessful($valeur);
        }
    }
}
