<?php

namespace App\Tests\Controller;

use App\Controller\Admin\ArticleCrudController;
use App\Entity\Admin;
use App\Entity\Article;
use App\Entity\Categorie;
use App\Entity\Media;
use App\Seo\SiteUrls;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * L'année d'un article est un simple repère pour Caroline dans l'admin : elle ne change pas
 * l'ordre des articles sur le site et n'y est pas affichée. L'ordre se règle librement dans
 * une catégorie, par glisser-déposer dans la liste de l'admin, où tous les articles tiennent
 * sur une seule page.
 *
 * Crée ses propres catégories/articles/photos (aux fichiers inexistants) et les supprime après chaque test.
 */
class ArticleYearAndOrderTest extends WebTestCase
{
    /** @var list<object> */
    private array $created = [];

    protected function tearDown(): void
    {
        if ([] !== $this->created) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            $em->clear();

            // Dans l'ordre inverse : les photos, puis les articles, puis les catégories
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
    }

    private function galerie(string $titre): Categorie
    {
        $categorie = (new Categorie())->setTitre($titre)->setPosition(9700);
        $this->persist($categorie);

        return $categorie;
    }

    private function article(Categorie $categorie, string $titre, int $position, ?int $annee, bool $avecPhoto = true): Article
    {
        $article = (new Article())
            ->setTitre($titre)
            ->setCategorie($categorie)
            ->setPosition($position)
            ->setAnnee($annee);
        $this->persist($article);

        if ($avecPhoto) {
            $media = new Media();
            $media->setImageName(sprintf('annee-test-%s.jpg', uniqid()));
            $media->setLegende($titre)->setPosition(0)->setArticle($article);
            $this->persist($media);
            $article->addMedia($media);
        }

        return $article;
    }

    /**
     * @return list<string>
     */
    private function textes(Crawler $crawler, string $selecteur): array
    {
        return $crawler->filter($selecteur)->each(fn (Crawler $node) => trim($node->text()));
    }

    public function testYearNeitherReordersNorShowsOnTheSite(): void
    {
        $client = static::createClient();
        $galerie = $this->galerie('Ateliers test');

        // Années volontairement dans le désordre : seul l'ordre choisi à la main (position) compte
        $this->article($galerie, 'Atelier A', 1, 2023);
        $this->article($galerie, 'Atelier B', 2, 2025);
        $this->article($galerie, 'Atelier C', 3, null);
        $this->article($galerie, 'Atelier D', 4, 2023);

        $crawler = $client->request('GET', static::getContainer()->get(SiteUrls::class)->categoryPath($galerie));

        self::assertResponseIsSuccessful();
        self::assertSame(['Atelier A', 'Atelier B', 'Atelier C', 'Atelier D'], $this->textes($crawler, '.category-card__title'));
        self::assertCount(0, $crawler->filter('h2'), 'Pas d\'intertitre d\'année sur le site');
        self::assertCount(1, $crawler->filter('.category-grid'), 'Une seule grille, comme avant');

        $page = $crawler->filter('.page-content')->text();
        self::assertStringNotContainsString('2025', $page, 'L\'année n\'est pas affichée');
        self::assertStringNotContainsString('2023', $page, 'L\'année n\'est pas affichée');
    }

    public function testAdminListShowsEveryArticleOnASinglePageInManualOrder(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $admin = $em->getRepository(Admin::class)->findOneBy([]);
        self::assertNotNull($admin, 'Aucun compte Admin trouvé en base de test');
        $client->loginUser($admin);

        // Plus que les 20 lignes par page d'avant : toutes doivent être sur la première page,
        // dans l'ordre choisi à la main, quelle que soit leur année
        $galerie = $this->galerie('Grande catégorie test');
        $ids = [];
        for ($i = 0; $i < 25; ++$i) {
            $ids[] = $this->article($galerie, 'Article admin ' . $i, $i, 2020 + $i % 3, avecPhoto: false)->getId();
        }

        $url = static::getContainer()->get(AdminUrlGeneratorInterface::class)
            ->setController(ArticleCrudController::class)
            ->setAction(Action::INDEX)
            ->generateUrl();
        $crawler = $client->request('GET', $url);

        self::assertResponseIsSuccessful();
        $affiches = array_map('intval', $crawler->filter('table.datagrid tr[data-id]')->each(fn (Crawler $row) => $row->attr('data-id')));
        self::assertSame(
            $ids,
            array_values(array_intersect($affiches, $ids)),
            'Tous les articles de la catégorie sont sur la page, dans l\'ordre de leur position',
        );

        // L'année est une colonne de la liste, pour s'y retrouver
        self::assertGreaterThan(0, $crawler->filter('td[data-column="annee"]')->count());

        // Scripts et styles de la liste versionnés : pas d'ancienne version gardée en cache
        self::assertCount(1, $crawler->filter('script[src*="admin_drag_drop.js?v="]'));
        self::assertCount(1, $crawler->filter('link[href*="admin_media.css?v="]'));
    }

    public function testYearMustBePlausible(): void
    {
        $validator = static::getContainer()->get(ValidatorInterface::class);

        self::assertCount(0, $validator->validateProperty((new Article())->setAnnee(2024), 'annee'));
        self::assertCount(0, $validator->validateProperty((new Article())->setAnnee(null), 'annee'), 'L\'année reste facultative');
        self::assertCount(1, $validator->validateProperty((new Article())->setAnnee(24), 'annee'), '« 24 » au lieu de « 2024 »');
    }
}
