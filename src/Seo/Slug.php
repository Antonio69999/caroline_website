<?php

namespace App\Seo;

use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Transforme un titre en fragment d'adresse : « Gravure sur verre » → « gravure-sur-verre ».
 *
 * Le résultat ne contient que des minuscules, des chiffres et des tirets : c'est exactement
 * ce qu'accepte la route (voir HomeController), sinon une adresse générée ne serait pas
 * reconnue et la redirection vers l'adresse officielle tournerait en boucle.
 */
final class Slug
{
  // Au-delà, l'adresse devient illisible dans Google et dans un message.
  private const MAX_LENGTH = 60;

  private readonly AsciiSlugger $slugger;

  public function __construct()
  {
    $this->slugger = new AsciiSlugger('fr');
  }

  public function from(?string $text): string
  {
    $slug = $this->slugger->slug(trim((string) $text))->lower()->toString();

    if (strlen($slug) > self::MAX_LENGTH) {
      $cut = substr($slug, 0, self::MAX_LENGTH);
      $lastDash = strrpos($cut, '-');

      // On coupe entre deux mots plutôt qu'au milieu de l'un d'eux
      $slug = false !== $lastDash && $lastDash > 20 ? substr($cut, 0, $lastDash) : $cut;
    }

    return trim($slug, '-');
  }
}
