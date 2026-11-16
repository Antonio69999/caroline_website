<?php

namespace App\Twig;

use App\Entity\Article;
use App\Entity\Categorie;
use App\Seo\SeoBuilder;
use App\Seo\SeoPage;
use App\Seo\SiteUrls;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Ce que les gabarits ont besoin de savoir du référencement :
 *  - category_path() / article_path() : l'adresse officielle d'une page (avec le titre dans l'adresse),
 *    à utiliser pour tous les liens du site ;
 *  - seo_page() : les infos d'une page fixe (contact, mentions légales) ;
 *  - seo_json_ld() : le bloc de données structurées d'une page.
 */
class SeoExtension extends AbstractExtension
{
  public function __construct(
    private readonly SiteUrls $urls,
    private readonly SeoBuilder $seo,
  ) {
  }

  public function getFunctions(): array
  {
    return [
      new TwigFunction('category_path', $this->urls->categoryPath(...)),
      new TwigFunction('article_path', $this->urls->articlePath(...)),
      new TwigFunction('seo_page', $this->page(...)),
      new TwigFunction('seo_json_ld', $this->jsonLd(...), ['is_safe' => ['html']]),
    ];
  }

  public function page(string $name): SeoPage
  {
    return match ($name) {
      'contact' => $this->seo->contact(),
      'legal' => $this->seo->legal(),
      default => throw new \InvalidArgumentException(sprintf('Page inconnue pour le référencement : "%s".', $name)),
    };
  }

  public function jsonLd(SeoPage $page): string
  {
    if ([] === $page->graph) {
      return '';
    }

    // HEX_TAG : un « </script> » écrit dans un titre ne peut pas fermer la balise avant l'heure
    $json = json_encode(
      ['@context' => 'https://schema.org', '@graph' => $page->graph],
      \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_THROW_ON_ERROR,
    );

    return '<script type="application/ld+json">' . $json . '</script>';
  }
}
