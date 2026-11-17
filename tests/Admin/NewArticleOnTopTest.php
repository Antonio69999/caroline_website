<?php

namespace App\Tests\Admin;

use App\Controller\Admin\ArticleCrudController;
use App\Entity\Admin;
use App\Entity\Article;
use App\Entity\Categorie;
use App\Entity\Media;
use App\Seo\SiteUrls;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Un article créé dans l'admin se place en premier dans sa catégorie (avant : tout en bas,
 * Caroline devait le remonter à la main), sans toucher à l'ordre des articles existants.
 * Les flèches / le glisser-déposer (même requête de réordonnancement) continuent de marcher
 * avec lui, et le modifier ensuite ne le déplace pas.
 *
 * Crée ses propres catégories/articles et les supprime après chaque test.
 */
class NewArticleOnTopTest extends WebTestCase
{
    /** @var list<object> */
    private array $created = [];

    protected function tearDown(): void
    {
        if ([] !== $this->created) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            $em->clear();

            // Dans l'ordre inverse : les articles, puis les catégories
            foreach (array_reverse($this->created) as $entity) {
                $managed = $em->find($entity::class, $entity->getId());
                if ($managed) {
                    $em->remove($managed);
                    $em->flush();
                }
            }
        }

        // PHPUnit garde l'objet de test jusqu'à la fin de la suite : sans ce vidage, les
        // collections Doctrine de ces entités retiendraient l'EntityManager (et tout le noyau)
        // de chaque test, et la suite complète finit par dépasser memory_limit (128M).
        $this->created = [];

        parent::tearDown();
    }

    private function loggedClient(): KernelBrowser
    {
        $client = static::createClient();
        $admin = static::getContainer()->get(EntityManagerInterface::class)->getRepository(Admin::class)->findOneBy([]);
        self::assertNotNull($admin, 'Aucun compte Admin trouvé en base de test');
        $client->loginUser($admin);

        return $client;
    }

    private function adminUrl(string $action, ?int $id = null): string
    {
        $generator = static::getContainer()->get(AdminUrlGeneratorInterface::class)
            ->setController(ArticleCrudController::class)
            ->setAction($action);
        if (null !== $id) {
            $generator->setEntityId($id);
        }

        return $generator->generateUrl();
    }

    private function persist(object $entity): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($entity);
        $em->flush();
        $this->created[] = $entity;
    }

    private function galerie(string $titre): Categorie
    {
        $categorie = (new Categorie())->setTitre($titre)->setPosition(9800);
        $this->persist($categorie);

        return $categorie;
    }

    private function article(Categorie $categorie, string $titre, int $position): Article
    {
        $article = (new Article())->setTitre($titre)->setCategorie($categorie)->setPosition($position);
        $this->persist($article);
        $this->ajouterUnePhoto($article);

        return $article;
    }

    /** Sur le site, un article sans photo n'a pas de carte (category/show.html.twig) : une photo factice suffit. */
    private function ajouterUnePhoto(Article $article): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $media = (new Media())->setLegende((string) $article->getTitre())->setPosition(0);
        $media->setImageName(sprintf('nouveau-en-tete-%s.jpg', uniqid()));
        $media->setArticle($article);
        $em->persist($media);
        $em->flush();
    }

    /** Crée un article comme Caroline : par le formulaire « Ajouter » de l'admin. */
    private function creerDepuisLAdmin(KernelBrowser $client, Categorie $categorie, string $titre): Article
    {
        $crawler = $client->request('GET', $this->adminUrl(Action::NEW));
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Créer')->form();
        $form['Article[titre]'] = $titre;
        $form['Article[categorie][autocomplete]']->disableValidation()->setValue((string) $categorie->getId());
        $client->submit($form);
        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $article = $em->getRepository(Article::class)->findOneBy(['titre' => $titre]);
        self::assertNotNull($article, 'L\'article n\'a pas été créé');
        $this->created[] = $article;
        $this->ajouterUnePhoto($article);

        return $article;
    }

    /** @return list<string> */
    private function ordreSurLeSite(KernelBrowser $client, Categorie $categorie): array
    {
        $crawler = $client->request('GET', static::getContainer()->get(SiteUrls::class)->categoryPath($categorie));
        self::assertResponseIsSuccessful();

        return $crawler->filter('.category-card__title')->each(fn (Crawler $node) => trim($node->text()));
    }

    private function position(Article $article): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->find(Article::class, $article->getId())->getPosition();
    }

    public function testANewArticleComesFirstInItsCategoryWithoutMovingTheOthers(): void
    {
        $client = $this->loggedClient();
        $galerie = $this->galerie('Nouveaux en tête test');
        $a = $this->article($galerie, 'Ancien A', 1);
        $b = $this->article($galerie, 'Ancien B', 2);
        $c = $this->article($galerie, 'Ancien C', 3);

        $nouveau = $this->creerDepuisLAdmin($client, $galerie, 'Tout nouvel article test');

        self::assertSame(['Tout nouvel article test', 'Ancien A', 'Ancien B', 'Ancien C'], $this->ordreSurLeSite($client, $galerie));

        // L'ordre déjà choisi n'est pas réécrit : les anciens articles gardent leur position
        self::assertSame([1, 2, 3], [$this->position($a), $this->position($b), $this->position($c)]);
        // Jamais 0 : pour app:init-article-positions, 0 veut dire « pas encore rangé »
        self::assertLessThan(0, $this->position($nouveau));

        // Même ordre dans la liste de l'admin, où se font les flèches et le glisser-déposer
        $crawler = $client->request('GET', $this->adminUrl(Action::INDEX));
        $ids = array_map('intval', $crawler->filter('table.datagrid tr[data-id]')->each(fn (Crawler $row) => $row->attr('data-id')));
        self::assertSame(
            [$nouveau->getId(), $a->getId(), $b->getId(), $c->getId()],
            array_values(array_intersect($ids, [$a->getId(), $b->getId(), $c->getId(), $nouveau->getId()])),
        );

        // Le suivant passe devant le précédent
        $this->creerDepuisLAdmin($client, $galerie, 'Encore plus récent test');
        self::assertSame(
            ['Encore plus récent test', 'Tout nouvel article test', 'Ancien A', 'Ancien B', 'Ancien C'],
            $this->ordreSurLeSite($client, $galerie),
        );
    }

    public function testTheNewArticleCanStillBeMovedAndEditingItKeepsItsPlace(): void
    {
        $client = $this->loggedClient();
        $galerie = $this->galerie('Déplacer un nouvel article test');
        $a = $this->article($galerie, 'Premier A', 1);
        $b = $this->article($galerie, 'Deuxième B', 2);

        $nouveau = $this->creerDepuisLAdmin($client, $galerie, 'Nouveau à déplacer test');
        self::assertSame(['Nouveau à déplacer test', 'Premier A', 'Deuxième B'], $this->ordreSurLeSite($client, $galerie));

        // ▼ ou glisser-déposer : la liste envoie le nouvel ordre de la catégorie
        $client->request('POST', '/admin/article/reorder', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'orderedIds' => [$a->getId(), $nouveau->getId(), $b->getId()],
        ]));
        self::assertResponseIsSuccessful();
        self::assertSame(['Premier A', 'Nouveau à déplacer test', 'Deuxième B'], $this->ordreSurLeSite($client, $galerie));

        // Modifier l'article ensuite (ici son titre) ne le renvoie ni en haut ni en bas
        $positionChoisie = $this->position($nouveau);
        $crawler = $client->request('GET', $this->adminUrl(Action::EDIT, $nouveau->getId()));
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form.ea-edit-form')->form();
        $form['Article[titre]'] = 'Nouveau renommé test';
        $client->submit($form);
        self::assertResponseRedirects();

        self::assertSame($positionChoisie, $this->position($nouveau));
        self::assertSame(['Premier A', 'Nouveau renommé test', 'Deuxième B'], $this->ordreSurLeSite($client, $galerie));
    }
}
