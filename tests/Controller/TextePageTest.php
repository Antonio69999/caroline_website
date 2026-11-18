<?php

namespace App\Tests\Controller;

use App\Entity\Article;
use App\Entity\Categorie;
use App\Seo\SiteUrls;
use App\Twig\TexteExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La description d'un article et celle d'une catégorie sont mises en page de la même façon :
 * un texte court est centré, un texte long a ses lignes alignées à gauche (classe « --longue »),
 * et les espaces de fin de ligne laissées par l'éditeur ne décalent plus rien.
 * Le choix suit la LONGUEUR du texte, jamais le zoom : il est fait ici, côté serveur.
 *
 * Crée ses propres catégories/articles et les supprime après chaque test.
 */
class TextePageTest extends WebTestCase
{
    private KernelBrowser $client;

    /** @var list<object> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
    }

    protected function tearDown(): void
    {
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

    private function persist(object ...$entities): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ($entities as $entity) {
            $em->persist($entity);
            $this->created[] = $entity;
        }
        $em->flush();
    }

    private function longText(): string
    {
        return '<div>' . str_repeat('Une phrase assez longue pour dépasser le seuil. ', 10) . '</div>';
    }

    private function pageOfArticle(?string $description): \Symfony\Component\DomCrawler\Crawler
    {
        $categorie = (new Categorie())->setTitre('Galerie texte test')->setPosition(9101);
        $this->persist($categorie);
        $article = (new Article())->setTitre('Article texte test')->setCategorie($categorie)->setPublie(true)->setDescription($description);
        $this->persist($article);

        $crawler = $this->client->request('GET', static::getContainer()->get(SiteUrls::class)->articlePath($article));
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function pageOfCategory(?string $description): \Symfony\Component\DomCrawler\Crawler
    {
        $categorie = (new Categorie())->setTitre('Galerie texte test')->setPosition(9102)->setDescription($description);
        $this->persist($categorie);

        $crawler = $this->client->request('GET', static::getContainer()->get(SiteUrls::class)->categoryPath($categorie));
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    public function testEveryPageLoadsTheSharedTextStylesheet(): void
    {
        $crawler = $this->pageOfArticle('<div>Court</div>');

        self::assertCount(1, $crawler->filter('link[rel=stylesheet][href*="asset/css/textes.css"]'));
    }

    public function testAShortArticleTextIsCentered(): void
    {
        $crawler = $this->pageOfArticle('<div>Huile sur toile, 2023</div>');

        self::assertCount(1, $crawler->filter('.description'));
        self::assertCount(0, $crawler->filter('.description--longue'));
        self::assertStringContainsString('Huile sur toile, 2023', $crawler->filter('.description')->text());
    }

    public function testALongArticleTextIsLeftAligned(): void
    {
        $crawler = $this->pageOfArticle($this->longText());

        self::assertCount(1, $crawler->filter('.description.description--longue'));
    }

    public function testAShortCategoryTextIsCentered(): void
    {
        $crawler = $this->pageOfCategory('<div>Une série de gravures</div>');

        self::assertCount(1, $crawler->filter('.category-page__description'));
        self::assertCount(0, $crawler->filter('.category-page__description--longue'));
    }

    public function testALongCategoryTextIsLeftAligned(): void
    {
        $crawler = $this->pageOfCategory($this->longText());

        self::assertCount(1, $crawler->filter('.category-page__description.category-page__description--longue'));
    }

    public function testArticleAndCategoryDecideTheSameWayForTheSameText(): void
    {
        $limite = TexteExtension::SEUIL_TEXTE_LONG;

        foreach ([$limite => false, $limite + 1 => true] as $longueur => $attendu) {
            $texte = '<div>' . str_repeat('a', $longueur) . '</div>';

            $article = $this->pageOfArticle($texte);
            $categorie = $this->pageOfCategory($texte);

            self::assertSame($attendu, 1 === $article->filter('.description--longue')->count(), "article, $longueur caractères");
            self::assertSame($attendu, 1 === $categorie->filter('.category-page__description--longue')->count(), "catégorie, $longueur caractères");
        }
    }

    public function testTrailingNonBreakingSpacesAreNotSentToTheBrowser(): void
    {
        $crawler = $this->pageOfArticle('<div>Première ligne&nbsp;<br>Deuxième ligne&nbsp;</div>');

        $html = $crawler->filter('.description')->html();
        self::assertStringNotContainsString('&nbsp;', $html);
        self::assertStringNotContainsString("\u{00A0}", $html);
        self::assertStringContainsString('Première ligne<br', $html);
    }

    public function testTheTextIsStillPublishedAsHtml(): void
    {
        // Le texte de l'admin est du HTML de confiance : sa mise en forme (gras, lien) doit survivre
        $crawler = $this->pageOfArticle('<div>Un <strong>mot en gras</strong> et <a href="https://example.com">un lien</a></div>');

        self::assertCount(1, $crawler->filter('.description strong'));
        self::assertSame('https://example.com', $crawler->filter('.description a')->attr('href'));
    }

    public function testAnArticleWithoutTextStillRendersItsBlock(): void
    {
        $crawler = $this->pageOfArticle(null);

        self::assertCount(1, $crawler->filter('.description'));
        self::assertCount(0, $crawler->filter('.description--longue'));
    }

    public function testACategoryWithoutTextShowsNoDescription(): void
    {
        $crawler = $this->pageOfCategory(null);

        self::assertCount(0, $crawler->filter('.category-page__description'));
    }
}
