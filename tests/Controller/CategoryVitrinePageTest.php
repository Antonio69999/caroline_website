<?php

namespace App\Tests\Controller;

use App\Entity\Bloc;
use App\Entity\Categorie;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Page publique d'une catégorie « vitrine » (le Préambule) : les blocs s'empilent
 * dans l'ordre choisi, chacun avec le bon élément HTML (image, lecteur audio, vidéo).
 *
 * Crée ses propres catégories et les supprime après chaque test.
 */
class CategoryVitrinePageTest extends WebTestCase
{
    /** @var list<object> */
    private array $created = [];

    protected function tearDown(): void
    {
        if ([] !== $this->created) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            $em->clear();

            foreach (array_reverse($this->created) as $entity) {
                $managed = $em->find($entity::class, $entity->getId());
                if ($managed) {
                    $em->remove($managed);
                    $em->flush();
                }
            }
        }

        parent::tearDown();
    }

    private function persist(object ...$entities): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ($entities as $entity) {
            $em->persist($entity);
            $this->created[] = $entity;
        }
        $em->flush();

        // Comme en production, la page relit les blocs depuis la base (avec leur tri par
        // position) au lieu de réutiliser la collection en mémoire dans l'ordre d'insertion.
        $em->clear();
    }

    private function bloc(string $type, int $position, ?string $titre = null, ?string $contenu = null, ?string $fichier = null, string $largeur = Bloc::LARGEUR_PLEINE): Bloc
    {
        $bloc = (new Bloc())->setType($type)->setPosition($position)->setTitre($titre)->setContenu($contenu)->setLargeur($largeur);
        $bloc->setFichierName($fichier);

        return $bloc;
    }

    public function testVitrineStacksItsBlocksInTheChosenOrder(): void
    {
        $client = static::createClient();

        $vitrine = (new Categorie())->setTitre('Préambule de test')->setType(Categorie::TYPE_VITRINE)->setPosition(9301);
        // Ajoutés dans le désordre : c'est la position qui décide, pas l'ordre d'insertion
        $vitrine->addBloc($this->bloc(Bloc::TYPE_VIDEO, 3, 'Ma vidéo', null, 'film.mp4', Bloc::LARGEUR_MOYENNE));
        $vitrine->addBloc($this->bloc(Bloc::TYPE_TEXTE, 0, null, '<h2>Bienvenue</h2><p>Dans mon <strong>jardin</strong></p>'));
        $vitrine->addBloc($this->bloc(Bloc::TYPE_SON, 2, 'Mon morceau', null, 'chanson.mp3'));
        $vitrine->addBloc($this->bloc(Bloc::TYPE_IMAGE, 1, "Un tableau d'automne", null, 'tableau.jpg', Bloc::LARGEUR_ETROITE));
        $this->persist($vitrine);

        $crawler = $client->request('GET', '/category/' . $vitrine->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.category-page__title', 'Préambule de test');
        self::assertSelectorNotExists('.category-grid');

        // Quatre blocs, dans l'ordre des positions : texte, image, son, vidéo
        $classes = $crawler->filter('.vitrine > .vitrine-bloc')->each(fn ($n) => $n->attr('class'));
        self::assertCount(4, $classes);
        self::assertStringContainsString('vitrine-bloc--texte', $classes[0]);
        self::assertStringContainsString('vitrine-bloc--image', $classes[1]);
        self::assertStringContainsString('vitrine-bloc--etroite', $classes[1]);
        self::assertStringContainsString('vitrine-bloc--son', $classes[2]);
        self::assertStringContainsString('vitrine-bloc--video', $classes[3]);
        self::assertStringContainsString('vitrine-bloc--moyenne', $classes[3]);

        // Texte : le HTML de l'éditeur est rendu tel quel
        self::assertSelectorTextContains('.vitrine-bloc--texte .category-text h2', 'Bienvenue');
        self::assertSelectorExists('.vitrine-bloc--texte .category-text strong');

        // Image : la description devient le texte alternatif
        $img = $crawler->filter('.vitrine-bloc--image img');
        self::assertSame('/uploads/vitrine/tableau.jpg', $img->attr('src'));
        self::assertSame("Un tableau d'automne", $img->attr('alt'));

        // Son : lecteur sans lecture automatique, qui ne charge rien avant le clic
        $audio = $crawler->filter('.vitrine-bloc--son audio');
        self::assertSame('/uploads/vitrine/chanson.mp3', $audio->attr('src'));
        self::assertNotNull($audio->attr('controls'));
        self::assertSame('none', $audio->attr('preload'));
        self::assertNull($audio->attr('autoplay'));
        self::assertSelectorTextContains('.vitrine-bloc--son .vitrine-bloc__titre', 'Mon morceau');

        // Vidéo : lecteur natif, sans lecture automatique
        $video = $crawler->filter('.vitrine-bloc--video video');
        self::assertSame('/uploads/vitrine/film.mp4', $video->attr('src'));
        self::assertNotNull($video->attr('controls'));
        self::assertNull($video->attr('autoplay'));
        self::assertSelectorTextContains('.vitrine-bloc--video .vitrine-bloc__titre', 'Ma vidéo');
    }

    public function testIncompleteBlocksAreSkippedInsteadOfBreakingThePage(): void
    {
        $client = static::createClient();

        $vitrine = (new Categorie())->setTitre('Vitrine incomplète')->setType(Categorie::TYPE_VITRINE)->setPosition(9302);
        $vitrine->addBloc($this->bloc(Bloc::TYPE_TEXTE, 0, null, null));              // texte vide
        $vitrine->addBloc($this->bloc(Bloc::TYPE_SON, 1, 'Sans fichier', null, null)); // fichier manquant
        $vitrine->addBloc($this->bloc(Bloc::TYPE_TEXTE, 2, null, '<p>Seul bloc complet</p>'));
        $this->persist($vitrine);

        $crawler = $client->request('GET', '/category/' . $vitrine->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.vitrine > .vitrine-bloc'));
        self::assertSelectorTextContains('.vitrine-bloc--texte', 'Seul bloc complet');
        self::assertSelectorNotExists('audio');
    }

    public function testEmptyVitrineStillRenders(): void
    {
        $client = static::createClient();

        $vitrine = (new Categorie())->setTitre('Vitrine vide')->setType(Categorie::TYPE_VITRINE)->setPosition(9303);
        $this->persist($vitrine);

        $client->request('GET', '/category/' . $vitrine->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.category-page__title', 'Vitrine vide');
        self::assertSelectorNotExists('.vitrine-bloc');
    }

    public function testBlocsOfANonVitrineCategoryAreNeverShown(): void
    {
        $client = static::createClient();

        // Une galerie qui garde des blocs d'une époque où elle était une vitrine
        $galerie = (new Categorie())->setTitre('Ancienne vitrine')->setPosition(9304);
        $galerie->addBloc($this->bloc(Bloc::TYPE_TEXTE, 0, null, '<p>Ne doit pas apparaître</p>'));
        $this->persist($galerie);

        $client->request('GET', '/category/' . $galerie->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.category-grid');
        self::assertSelectorNotExists('.vitrine');
        self::assertStringNotContainsString('Ne doit pas apparaître', $client->getResponse()->getContent());
    }

    public function testVitrineIsAnOrdinaryEntryOfTheMenuAndTheOldPreambleLinkIsGone(): void
    {
        $client = static::createClient();

        $vitrine = (new Categorie())->setTitre('Préambule menu')->setType(Categorie::TYPE_VITRINE)->setPosition(9305);
        $this->persist($vitrine);

        $crawler = $client->request('GET', '/category/' . $vitrine->getId());

        self::assertResponseIsSuccessful();

        // Elle est dans le menu comme n'importe quelle catégorie (donc déplaçable dans l'admin)
        $link = $crawler->filter('#main-menu a[href="/category/' . $vitrine->getId() . '"]');
        self::assertCount(1, $link);
        self::assertStringContainsString('Préambule menu', $link->text());

        // Plus de lien « Préambule » codé en dur vers l'ancienne page vidéo
        self::assertCount(0, $crawler->filter('#main-menu a[href="/preamble"]'));
    }

    public function testOldPreambleUrlRedirectsToTheVitrine(): void
    {
        $client = static::createClient();

        $premiere = (new Categorie())->setTitre('Vitrine A')->setType(Categorie::TYPE_VITRINE)->setPosition(9306);
        $seconde = (new Categorie())->setTitre('Vitrine B')->setType(Categorie::TYPE_VITRINE)->setPosition(9307);
        $this->persist($premiere, $seconde);

        $client->request('GET', '/preamble');

        self::assertResponseRedirects('/category/' . $premiere->getId());
    }

    public function testOldPreambleUrlFallsBackToTheHomePageWithoutAnyVitrine(): void
    {
        $client = static::createClient();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em->getRepository(Categorie::class)->findOneBy(['type' => Categorie::TYPE_VITRINE])) {
            self::markTestSkipped('La base locale contient déjà une vitrine : ce cas ne peut pas être observé ici.');
        }

        $client->request('GET', '/preamble');

        self::assertResponseRedirects('/');
    }
}
