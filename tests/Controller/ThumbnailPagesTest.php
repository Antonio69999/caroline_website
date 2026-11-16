<?php

namespace App\Tests\Controller;

use App\Entity\Article;
use App\Entity\Categorie;
use App\Entity\Media;
use App\Seo\SiteUrls;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Les grilles et galeries affichent la vignette d'une photo quand elle existe (sinon la photo d'origine),
 * alors que le clic « en grand », Google Images, le partage et le sitemap gardent la photo d'origine.
 *
 * Les photos sont fictives (le fichier d'origine n'existe pas) ; seul un fichier de vignette factice est
 * posé dans public/uploads/attachments/_vignettes/, puis retiré après chaque test.
 */
class ThumbnailPagesTest extends WebTestCase
{
    private const SITE = 'https://carottecake.com';

    /** @var list<object> */
    private array $created = [];

    /** @var list<string> */
    private array $thumbFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->thumbFiles as $file) {
            @unlink($file);
        }

        if ([] !== $this->created) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            $em->clear();

            foreach (array_reverse($this->created) as $entity) {
                // Une entité déjà supprimée par le test n'a plus d'identifiant
                if (null === $entity->getId()) {
                    continue;
                }

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

    private function thumbPath(string $imageName): string
    {
        return dirname(__DIR__, 2) . '/public/uploads/attachments/_vignettes/' . $imageName;
    }

    private function putThumbnail(string $imageName): void
    {
        $path = $this->thumbPath($imageName);
        is_dir(dirname($path)) || mkdir(dirname($path), 0775, true);
        file_put_contents($path, 'vignette factice');
        $this->thumbFiles[] = $path;
    }

    /** @return array{0: Article, 1: Media, 2: string} */
    private function articleWithPhoto(int $position): array
    {
        $categorie = (new Categorie())->setTitre('Vignettes test ' . $position)->setPosition($position);
        $this->persist($categorie);

        $article = (new Article())->setTitre('Œuvre à vignette ' . $position)->setCategorie($categorie)->setPosition($position)->setPublie(true);
        $this->persist($article);

        $name = sprintf('seo-thumb-%s.jpg', uniqid());
        $media = new Media();
        $media->setImageName($name);
        $media->setLegende('Photo de test')->setPosition(0)->setArticle($article);
        $this->persist($media);
        $article->addMedia($media);

        return [$article, $media, $name];
    }

    public function testPagesFallBackToTheOriginalPhotoWhileThereIsNoThumbnail(): void
    {
        $client = static::createClient();
        [$article, , $name] = $this->articleWithPhoto(9601);
        $urls = static::getContainer()->get(SiteUrls::class);

        $crawler = $client->request('GET', $urls->categoryPath($article->getCategorie()));
        self::assertResponseIsSuccessful();
        self::assertSame('/uploads/attachments/' . $name, $crawler->filter('.category-card__image')->attr('src'));

        $crawler = $client->request('GET', $urls->articlePath($article));
        self::assertResponseIsSuccessful();
        self::assertSame('/uploads/attachments/' . $name, $crawler->filter('.gallery img')->attr('src'));
    }

    public function testGridAndGalleryShowTheThumbnailButEverythingElseKeepsTheOriginal(): void
    {
        $client = static::createClient();
        [$article, , $name] = $this->articleWithPhoto(9602);
        $this->putThumbnail($name);
        $urls = static::getContainer()->get(SiteUrls::class);

        $thumb = '/uploads/attachments/_vignettes/' . $name;
        $original = '/uploads/attachments/' . $name;

        // Grille d'une catégorie : la vignette
        $crawler = $client->request('GET', $urls->categoryPath($article->getCategorie()));
        self::assertSame($thumb, $crawler->filter('.category-card__image')->attr('src'));

        // Galerie d'un article : la vignette affichée, l'original au clic, dans le partage et pour Google
        $crawler = $client->request('GET', $urls->articlePath($article));
        self::assertSame($thumb, $crawler->filter('.gallery img')->attr('src'));
        self::assertSame($original, $crawler->filter('.gallery a')->attr('href'), 'Le clic ouvre la photo d\'origine');
        self::assertSame(self::SITE . $original, $crawler->filter('meta[property="og:image"]')->attr('content'));

        $json = json_decode($crawler->filter('script[type="application/ld+json"]')->text(null, false), true, 512, \JSON_THROW_ON_ERROR);
        $images = array_values(array_filter($json['@graph'], fn (array $n) => 'ImageGallery' === $n['@type']))[0]['image'];
        self::assertSame(self::SITE . $original, $images[0]['contentUrl']);

        // Le sitemap (Google Images) donne la photo d'origine, pas la vignette
        $client->request('GET', '/sitemap.xml');
        self::assertStringContainsString('<image:loc>' . self::SITE . $original . '</image:loc>', $client->getResponse()->getContent());
        self::assertStringNotContainsString('_vignettes', $client->getResponse()->getContent());
    }

    public function testDeletingAPhotoDeletesItsThumbnail(): void
    {
        static::createClient();
        [, $media, $name] = $this->articleWithPhoto(9603);
        $this->putThumbnail($name);
        self::assertFileExists($this->thumbPath($name));

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->remove($em->find(Media::class, $media->getId()));
        $em->flush();

        self::assertFileDoesNotExist($this->thumbPath($name));
    }
}
