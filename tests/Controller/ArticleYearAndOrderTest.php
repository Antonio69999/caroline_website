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
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Le rangement par année des articles d'une catégorie : sur le site (un intertitre par
 * année, la plus récente d'abord) et dans l'admin (tous les articles sur une seule page,
 * pour que le glisser-déposer marche dans toute une catégorie).
 *
 * Crée ses propres catégories/articles/photos (aux fichiers inexistants) et les supprime après chaque test.
 */
class CategoryYearGroupsTest extends WebTestCase
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
    private function textes(\Symfony\Component\DomCrawler\Crawler $crawler, string $selecteur): array
    {
        return $crawler->filter($selecteur)->each(fn ($node) => trim($node->text()));
    }

    public function testArticlesAreGroupedByYearMostRecentFirst(): void
    {
        $client = static::createClient();
        $galerie = $this->galerie('Ateliers test');

        // Positions volontairement dans le désordre des années : c'est l'année qui range
        $this->article($galerie, 'Atelier 2023', 1, 2023);
        $this->article($galerie, 'Atelier 2025 B', 3, 2025);
        $this->article($galerie, 'Atelier sans année', 0, null);
        $this->article($galerie, 'Atelier 2025 A', 2, 2025);
        // Sans photo : pas de vignette, et pas d'intertitre « 2019 » vide
        $this->article($galerie, 'Atelier 2019 sans photo', 4, 2019, avecPhoto: false);

        $crawler = $client->request('GET', static::getContainer()->get(SiteUrls::class)->categoryPath($galerie));

        self::assertResponseIsSuccessful();
        self::assertSame(['2025', '2023', 'Autres'], $this->textes($crawler, 'h2.category-year__title'));
        self::assertCount(1, $crawler->filter('h1'), 'Les intertitres sont des h2 : le titre de la page reste le seul h1');

        // Dans une même année, l'ordre choisi à la main (position) départage
        self::assertSame(
            ['Atelier 2025 A', 'Atelier 2025 B', 'Atelier 2023', 'Atelier sans année'],
            $this->textes($crawler, '.category-card__title'),
        );
        self::assertSame(
            [['Atelier 2025 A', 'Atelier 2025 B'], ['Atelier 2023'], ['Atelier sans année']],
            $crawler->filter('.category-year')->each(
                fn ($section) => $section->filter('.category-card__title')->each(fn ($n) => trim($n->text())),
            ),
        );
    }

    public function testCategoryWithoutAnyYearLooksAsBefore(): void
    {
        $client = static::createClient();
        $galerie = $this->galerie('Galerie sans années');
        $this->article($galerie, 'Deuxième', 2, null);
        $this->article($galerie, 'Premier', 1, null);

        $crawler = $client->request('GET', static::getContainer()->get(SiteUrls::class)->categoryPath($galerie));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('h2'), 'Aucune année renseignée : pas d\'intertitre');
        self::assertCount(1, $crawler->filter('.category-grid'));
        self::assertSame(['Premier', 'Deuxième'], $this->textes($crawler, '.category-card__title'));
    }

    public function testAdminListShowsEveryArticleOnASinglePage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $admin = $em->getRepository(Admin::class)->findOneBy([]);
        self::assertNotNull($admin, 'Aucun compte Admin trouvé en base de test');
        $client->loginUser($admin);

        // Plus que les 20 lignes par page d'avant : toutes doivent être sur la première page
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
        $affiches = array_map('intval', $crawler->filter('table.datagrid tr[data-id]')->each(fn ($row) => $row->attr('data-id')));
        self::assertSame([], array_values(array_diff($ids, $affiches)), 'Des articles de la catégorie sont passés sur une autre page');

        // L'année est une colonne de la liste : le glisser-déposer s'en sert pour regrouper
        self::assertGreaterThan(0, $crawler->filter('td[data-column="annee"]')->count());

        // Le script du glisser-déposer est versionné : pas d'ancienne version gardée en cache
        self::assertCount(1, $crawler->filter('script[src*="admin_drag_drop.js?v="]'));
    }

    public function testYearMustBePlausible(): void
    {
        $validator = static::getContainer()->get(ValidatorInterface::class);

        self::assertCount(0, $validator->validateProperty((new Article())->setAnnee(2024), 'annee'));
        self::assertCount(0, $validator->validateProperty((new Article())->setAnnee(null), 'annee'), 'L\'année reste facultative');
        self::assertCount(1, $validator->validateProperty((new Article())->setAnnee(24), 'annee'), '« 24 » au lieu de « 2024 »');
    }
}
