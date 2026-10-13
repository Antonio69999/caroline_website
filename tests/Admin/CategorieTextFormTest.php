<?php

namespace App\Tests\Admin;

use App\Controller\Admin\CategorieCrudController;
use App\Entity\Admin;
use App\Entity\Article;
use App\Entity\Categorie;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Formulaire admin d'une catégorie : choix du type (galerie / page de texte / vitrine),
 * éditeur de texte du CV et liste d'articles proposés pour les liens.
 */
class CategorieTextFormTest extends WebTestCase
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

    private function newFormUrl(): string
    {
        return static::getContainer()->get(AdminUrlGeneratorInterface::class)
            ->setController(CategorieCrudController::class)
            ->setAction(Action::NEW)
            ->generateUrl();
    }

    public function testNewFormOffersAllTypesAndOnlyPublishedArticlesForLinks(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $client->loginUser($em->getRepository(Admin::class)->findOneBy([]));

        $galerie = (new Categorie())->setTitre('Galerie pour liens')->setPosition(9101);
        $em->persist($galerie);
        $this->created[] = $galerie;

        $publie = (new Article())->setTitre('Article publié pour lien')->setCategorie($galerie)->setPublie(true);
        $brouillon = (new Article())->setTitre('Brouillon secret')->setCategorie($galerie)->setPublie(false);
        $em->persist($publie);
        $em->persist($brouillon);
        $this->created[] = $publie;
        $this->created[] = $brouillon;
        $em->flush();

        $crawler = $client->request('GET', $this->newFormUrl());
        self::assertResponseIsSuccessful();

        // Trois types au choix (galerie, texte, vitrine), "galerie" pré-sélectionné pour ne rien changer par défaut
        self::assertCount(3, $crawler->filter('input[name="Categorie[type]"]'));
        self::assertSame('galerie', $crawler->filter('input[name="Categorie[type]"]:checked')->attr('value'));

        // L'éditeur du CV reçoit la liste des articles à citer
        self::assertSelectorExists('textarea[name="Categorie[contenu]"][data-linkable-articles]');
        $articles = json_decode($crawler->filter('textarea[name="Categorie[contenu]"]')->attr('data-linkable-articles'), true, 512, JSON_THROW_ON_ERROR);

        $byTitle = array_column($articles, null, 'titre');
        self::assertArrayHasKey('Article publié pour lien', $byTitle);
        self::assertSame('/article/' . $publie->getId(), $byTitle['Article publié pour lien']['url']);
        self::assertSame('Galerie pour liens', $byTitle['Article publié pour lien']['categorie']);
        self::assertArrayNotHasKey('Brouillon secret', $byTitle, 'Un brouillon ne doit pas être proposé comme cible de lien');
    }

    public function testCreatingATextCategoryPersistsItsFormattedContent(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $client->loginUser($em->getRepository(Admin::class)->findOneBy([]));

        $crawler = $client->request('GET', $this->newFormUrl());
        self::assertResponseIsSuccessful();

        $contenu = '<h1>Formation</h1><div><strong>2010</strong> : <a href="/article/1">Une référence</a></div>';

        $form = $crawler->selectButton('Créer')->form();
        $form['Categorie[titre]'] = 'CV créé par le test';
        $form['Categorie[type]'] = 'texte';
        $form['Categorie[contenu]'] = $contenu;
        $client->submit($form);

        self::assertResponseRedirects();

        $em->clear();
        $cv = $em->getRepository(Categorie::class)->findOneBy(['titre' => 'CV créé par le test']);
        self::assertNotNull($cv, 'La catégorie de type texte doit être créée');
        $this->created[] = $cv;

        self::assertTrue($cv->isTexte());
        self::assertSame($contenu, $cv->getContenu());
    }
}
