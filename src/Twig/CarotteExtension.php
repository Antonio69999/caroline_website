<?php

namespace App\Twig;

use App\Service\CarottesDeLaPage;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * carottes_de_la_page() : les carottes cachées à montrer sur la page affichée
 * (voir App\Service\CarottesDeLaPage et templates/components/carottes.html.twig).
 */
class CarotteExtension extends AbstractExtension
{
  public function __construct(private readonly CarottesDeLaPage $carottes)
  {
  }

  public function getFunctions(): array
  {
    return [
      new TwigFunction('carottes_de_la_page', $this->carottes->lister(...)),
    ];
  }
}
