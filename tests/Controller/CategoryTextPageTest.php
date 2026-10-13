<?php

namespace App\Tests\Controller;

use App\Entity\Article;
use App\Entity\Categorie;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Page publique d'une catégorie « texte » (le CV) : le texte mis en forme
 * remplace la grille d'articles, et les liens vers des articles fonctionnent.
 *
 * Crée ses propres catégories/articles et les supprime après chaque test.
 */
class CategoryTextPageTest extends WebTestCase
{
    /** @var list<object> */
    private array $created = [];

    protected function tearDown(): void
    {
        if ([] !== $this->created) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            $em->clear();

            // Les articles d'abord (ils référencent leur catégorie)
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

    public function testTextCategoryShowsFormattedContentInsteadOfTheGrid(): void
    {
        $client = static::createClient();

        $galerie = (new Categorie())->setTitre('Galerie de test')->setPosition(9001);
        $this->persist($galerie);

        $article = (new Article())->setTitre('Tableau cité dans le CV')->setCategorie($galerie)->setPublie(true);
        $this->persist($article);

        $cv = (new Categorie())
            ->setTitre('CV de test')
            ->setType(Categorie::TYPE_TEXTE)
            ->setContenu(sprintf(
                '<h1>Expositions</h1><div>2024 : <a href="/article/%d">Tableau cité dans le CV</a></div><ul><li>Prix du public</li></ul>',
                $article->getId()
            ))
            ->setPosition(9002);
        $this->persist($cv);

        $crawler = $client->request('GET', '/category/' . $cv->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.category-text');
        self::assertSelectorTextContains('.category-text h1', 'Expositions');
        self::assertSelectorTextContains('.category-text li', 'Prix du public');
        self::assertSelectorNotExists('.category-grid');

        // La référence vers l'article mène bien à sa page
        $link = $crawler->filter('.category-text a')->first();
        self::assertSame('/article/' . $article->getId(), $link->attr('href'));

        $client->click($link->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.article_subtitle', 'Tableau cité dans le CV');
    }


    public function testTextCategoryKeepsAdvancedEditorFormatting(): void
    {
        $client = static::createClient();

        $cv = (new Categorie())
            ->setTitre('CV mise en forme avancée')
            ->setType(Categorie::TYPE_TEXTE)
            ->setContenu(
                '<h2 class="ql-align-center" style="color: rgb(230, 0, 0);">Titre centré</h2>'
                . '<p><u>Texte souligné</u> et <strong>important</strong>.</p>'
                . '<blockquote>Une citation</blockquote><hr>'
            )
            ->setPosition(9005);
        $this->persist($cv);

        $client->request('GET', '/category/' . $cv->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.category-text .ql-align-center', 'Titre centré');
        self::assertSelectorTextContains('.category-text u', 'Texte souligné');
        self::assertSelectorExists('.category-text blockquote');
        self::assertSelectorExists('.category-text hr');
    }

    public function testTextCategoryWithoutContentStillRenders(): void
    {
        $client = static::createClient();

        $cv = (new Categorie())->setTitre('CV vide')->setType(Categorie::TYPE_TEXTE)->setPosition(9003);
        $this->persist($cv);

        $client->request('GET', '/category/' . $cv->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.category-page__title', 'CV vide');
        self::assertSelectorNotExists('.category-text');
    }

    public function testGalleryCategoryKeepsItsGridAndNeverShowsTextContent(): void
    {
        $client = static::createClient();

        // Une galerie qui a (par erreur ou par reste d'un ancien type) du contenu texte
        $galerie = (new Categorie())
            ->setTitre('Galerie classique')
            ->setContenu('<div>Ne doit pas apparaître</div>')
            ->setPosition(9004);
        $this->persist($galerie);

        self::assertSame(Categorie::TYPE_GALERIE, $galerie->getType(), 'Le type par défaut doit rester "galerie"');

        $client->request('GET', '/category/' . $galerie->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.category-grid');
        self::assertSelectorNotExists('.category-text');
        self::assertStringNotContainsString('Ne doit pas apparaître', $client->getResponse()->getContent());
    }
}
