<?php

namespace App\Controller;

use App\Entity\Article;
use App\Entity\Categorie;
use App\Repository\ArticleRepository;
use App\Repository\CategorieRepository;
use App\Seo\SeoBuilder;
use App\Seo\SiteUrls;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le plan du site que lit Google : chaque page publique, avec sa date de dernière
 * modification, et chaque photo d'œuvre avec sa légende (pour apparaître dans Google Images).
 *
 * Il se met à jour tout seul à chaque publication ou modification dans l'admin.
 */
class SitemapController extends AbstractController
{
  #[Route('/sitemap.xml', name: 'sitemap', defaults: ['_format' => 'xml'], methods: ['GET'])]
  public function index(
    CategorieRepository $categories,
    ArticleRepository $articles,
    SiteUrls $urls,
    SeoBuilder $seo,
  ): Response {
    $publies = $articles->findPublishedForSitemap();

    /** @var array<int, list<Article>> $parCategorie */
    $parCategorie = [];
    foreach ($publies as $article) {
      $parCategorie[$article->getCategorie()->getId()][] = $article;
    }

    $entries = [];
    $latest = null;

    foreach ($categories->findAllOrdered() as $categorie) {
      $deSaCategorie = $parCategorie[$categorie->getId()] ?? [];

      // Une catégorie vide est « noindex » : elle n'a rien à faire dans le plan du site
      if (!$seo->categoryHasContent($categorie, count($deSaCategorie))) {
        continue;
      }

      $lastmod = $this->categoryLastModified($categorie, $deSaCategorie);
      $entries[] = ['loc' => $urls->absolute($urls->categoryPath($categorie)), 'lastmod' => $lastmod, 'images' => []];
      $latest = $this->later($latest, $lastmod);

      foreach ($deSaCategorie as $article) {
        $lastmod = $article->getModifieLe() ?? $article->getcreeLe();
        $entries[] = [
          'loc' => $urls->absolute($urls->articlePath($article)),
          'lastmod' => $lastmod,
          'images' => $this->images($article, $urls),
        ];
      }
    }

    array_unshift($entries, ['loc' => $urls->homeUrl(), 'lastmod' => $latest, 'images' => []]);
    $entries[] = ['loc' => $urls->absolute('/contact'), 'lastmod' => null, 'images' => []];

    $response = $this->render('seo/sitemap.xml.twig', ['entries' => $entries]);
    $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');
    // Une heure de cache : assez court pour qu'une nouvelle œuvre soit vite connue de Google
    $response->setPublic()->setMaxAge(3600);

    return $response;
  }

  /**
   * @param list<Article> $articles
   */
  private function categoryLastModified(Categorie $categorie, array $articles): ?\DateTimeInterface
  {
    $latest = $categorie->getModifieeLe() ?? $categorie->getCreeLe();

    foreach ($articles as $article) {
      $latest = $this->later($latest, $article->getModifieLe() ?? $article->getcreeLe());
    }

    return $latest;
  }

  private function later(?\DateTimeInterface $a, ?\DateTimeInterface $b): ?\DateTimeInterface
  {
    if (null === $a || null === $b) {
      return $a ?? $b;
    }

    return $b > $a ? $b : $a;
  }

  /**
   * @return list<array{loc: string, title: string}>
   */
  private function images(Article $article, SiteUrls $urls): array
  {
    $images = [];

    foreach ($article->getMedia() as $media) {
      if (!$media->getImageName()) {
        continue;
      }

      $images[] = [
        'loc' => $urls->upload('attachments', $media->getImageName()),
        'title' => trim((string) $media->getLegende()) ?: (string) $article->getTitre(),
      ];
    }

    // Google accepte jusqu'à 1000 images par page
    return array_slice($images, 0, 1000);
  }
}
