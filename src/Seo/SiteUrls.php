<?php

namespace App\Seo;

use App\Entity\Article;
use App\Entity\Categorie;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Les adresses du site, en un seul endroit : le menu, les grilles, le sitemap, la balise
 * canonical et les données structurées donnent donc toutes la même adresse pour une page.
 *
 * L'adresse d'une catégorie ou d'un article contient son titre (/article/12/la-paire-de-chaussures).
 * Le numéro reste ce qui identifie la page : si Caroline renomme un article, l'ancienne
 * adresse continue de fonctionner (elle renvoie vers la nouvelle, voir HomeController).
 */
final class SiteUrls
{
  private readonly string $siteUrl;

  public function __construct(
    private readonly UrlGeneratorInterface $router,
    private readonly Slug $slug,
    #[Autowire('%app.site_url%')]
    string $siteUrl,
  ) {
    $this->siteUrl = rtrim($siteUrl, '/');
  }

  public function slug(?string $titre): string
  {
    return $this->slug->from($titre);
  }

  /** @return array{id: int, slug?: string} Paramètres de route de l'adresse officielle. */
  public function categoryParams(Categorie $categorie): array
  {
    return $this->params((int) $categorie->getId(), $categorie->getTitre());
  }

  /** @return array{id: int, slug?: string} */
  public function articleParams(Article $article): array
  {
    return $this->params((int) $article->getId(), $article->getTitre());
  }

  public function categoryPath(Categorie $categorie): string
  {
    return $this->router->generate('app_category_show', $this->categoryParams($categorie));
  }

  public function articlePath(Article $article): string
  {
    return $this->router->generate('app_article_show', $this->articleParams($article));
  }

  public function siteUrl(): string
  {
    return $this->siteUrl;
  }

  public function homeUrl(): string
  {
    return $this->siteUrl . '/';
  }

  /** Adresse complète (avec le nom de domaine officiel) d'un chemin du site. */
  public function absolute(string $path): string
  {
    return $this->siteUrl . $path;
  }

  /** Adresse complète d'un fichier envoyé depuis l'admin (public/uploads/<dossier>/<nom>). */
  public function upload(string $dossier, string $fichier): string
  {
    return $this->siteUrl . '/uploads/' . $dossier . '/' . rawurlencode($fichier);
  }

  /** @return array{id: int, slug?: string} */
  private function params(int $id, ?string $titre): array
  {
    $slug = $this->slug->from($titre);

    // Sans titre exploitable, l'adresse reste « /category/12 »
    return '' === $slug ? ['id' => $id] : ['id' => $id, 'slug' => $slug];
  }
}
