<?php

namespace App\Twig;

use App\Service\Thumbnailer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * thumb_url(nom_du_fichier) : l'adresse de la vignette d'une photo d'œuvre (ou de la photo
 * d'origine tant que sa vignette n'existe pas). À utiliser pour les images d'une grille ou
 * d'une galerie ; les liens « voir en grand » gardent l'adresse de la photo d'origine.
 */
class ThumbnailExtension extends AbstractExtension
{
  public function __construct(private readonly Thumbnailer $thumbnailer)
  {
  }

  public function getFunctions(): array
  {
    return [
      new TwigFunction('thumb_url', $this->thumbUrl(...)),
    ];
  }

  public function thumbUrl(?string $imageName): string
  {
    return $this->thumbnailer->url((string) $imageName);
  }
}
