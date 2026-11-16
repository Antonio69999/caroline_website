<?php

namespace App\Seo;

/**
 * Tout ce que Google (et les aperçus de partage : Facebook, WhatsApp…) doit savoir d'une
 * page. Fabriqué par SeoBuilder, affiché une seule fois par templates/base.html.twig.
 */
final class SeoPage
{
  /**
   * @param string                     $title       Titre de l'onglet et du résultat Google
   * @param string                     $description Phrase affichée sous le titre dans Google
   * @param string                     $canonical   Adresse officielle complète de la page
   * @param string                     $image       Image (adresse complète) de l'aperçu de partage
   * @param string                     $imageAlt    Description de cette image
   * @param string                     $robots      Consigne aux robots (index ou noindex)
   * @param list<array<string, mixed>> $graph       Données structurées (schema.org), éléments d'un @graph
   */
  public function __construct(
    public readonly string $title,
    public readonly string $description,
    public readonly string $canonical,
    public readonly string $image,
    public readonly string $imageAlt,
    public readonly string $robots,
    public readonly array $graph = [],
  ) {
  }

  public function isIndexable(): bool
  {
    return !str_contains($this->robots, 'noindex');
  }
}
