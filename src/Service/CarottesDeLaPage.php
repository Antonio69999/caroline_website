<?php

namespace App\Service;

use App\Entity\Carotte;
use App\Repository\CarotteRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Vich\UploaderBundle\Templating\Helper\UploaderHelper;

/**
 * Les carottes cachées à montrer sur la page qui s'affiche (voir App\Entity\Carotte).
 *
 * Une carotte est montrée si elle est active, a une image, et vise cette page :
 *  - « partout » : n'importe quelle page du site (sauf celles qui désactivent le bloc, comme la connexion) ;
 *  - « accueil » : la page d'accueil ;
 *  - « categorie:N » : la page de la catégorie n° N ;
 *  - « article:N » : la page de l'article n° N.
 *
 * Aperçu depuis l'admin : une personne connectée en administrateur peut ajouter ?carotte=N à
 * l'adresse d'une page pour voir la carotte n° N tout de suite, même rangée de côté.
 *
 * Appelé par la fonction Twig carottes_de_la_page(), depuis templates/components/carottes.html.twig.
 */
class CarottesDeLaPage
{
  public function __construct(
    private readonly RequestStack $requests,
    private readonly CarotteRepository $carottes,
    private readonly UploaderHelper $uploader,
    private readonly Security $security,
    private readonly LoggerInterface $logger,
  ) {
  }

  /**
   * Chaque carotte sous la forme dont a besoin le gabarit. Les réglages sont tirés au sort ici pour les
   * carottes « au hasard » : chaque affichage de la page en donne de nouveaux.
   *
   * Une carotte n'est qu'un clin d'œil posé sur la page : si quelque chose cloche avec elles (une ligne
   * de la base, un réglage inattendu), la page s'affiche quand même, simplement sans carotte, et le
   * problème est écrit dans le journal des erreurs. Sans ça, une carotte mal réglée suffirait à
   * rendre une page entière introuvable (erreur 500) pour tous les visiteurs.
   *
   * @return list<array{id: int, src: string, description: string, forcee: bool, delai: int, taille: string, opacite: string, ligne: string, colonne: string, imageTaille: string, imageLigne: string, imageColonne: string}>
   */
  public function lister(): array
  {
    try {
      return $this->construire();
    } catch (\Throwable $erreur) {
      $this->logger->error('Les carottes cachées n\'ont pas pu être préparées : la page s\'affiche sans elles.', ['exception' => $erreur]);

      return [];
    }
  }

  /**
   * @return list<array{id: int, src: string, description: string, forcee: bool, delai: int, taille: string, opacite: string, ligne: string, colonne: string, imageTaille: string, imageLigne: string, imageColonne: string}>
   */
  private function construire(): array
  {
    $request = $this->requests->getMainRequest();
    if (null === $request) {
      return [];
    }

    $forceeId = $this->security->isGranted('ROLE_ADMIN') ? $this->numeroDApercu($request->query->all()['carotte'] ?? null) : 0;
    $liste = [];

    foreach ($this->carottes->findPourPage($this->cibles($request->attributes->get('_route'), (array) $request->attributes->get('_route_params', [])), $forceeId ?: null) as $carotte) {
      $src = $carotte->hasImage() ? $this->uploader->asset($carotte, 'imageFile') : null;
      if (null === $src) {
        continue;
      }

      $reglages = $carotte->reglages();
      $liste[] = [
        'id' => (int) $carotte->getId(),
        'src' => $src,
        'description' => (string) $carotte->getDescription(),
        'forcee' => $forceeId > 0 && $forceeId === $carotte->getId(),
        'delai' => $carotte->getDelai(),
        'taille' => $reglages['taille'],
        // En fraction pour le CSS (35 % -> 0.35)
        'opacite' => rtrim(rtrim(number_format($reglages['opacite'] / 100, 2, '.', ''), '0'), '.'),
        'ligne' => $reglages['ligne'],
        'colonne' => $reglages['colonne'],
        'imageTaille' => $reglages['imageTaille'],
        'imageLigne' => $reglages['imageLigne'],
        'imageColonne' => $reglages['imageColonne'],
      ];
    }

    return $liste;
  }

  // Le numéro écrit dans ?carotte=N : des chiffres seulement (tout le reste est ignoré, sans erreur)
  private function numeroDApercu(mixed $valeur): int
  {
    return is_string($valeur) && preg_match('/^[1-9]\d{0,8}$/', $valeur) ? (int) $valeur : 0;
  }

  /**
   * Les cibles qui concernent la page : « partout » toujours, plus celle de la page elle-même.
   *
   * @param array<string, mixed> $params
   *
   * @return list<string>
   */
  private function cibles(mixed $route, array $params): array
  {
    $cibles = [Carotte::CIBLE_PARTOUT];

    $id = isset($params['id']) ? (int) $params['id'] : 0;

    if ('app_home' === $route) {
      $cibles[] = Carotte::CIBLE_ACCUEIL;
    } elseif ('app_category_show' === $route && $id > 0) {
      $cibles[] = Carotte::cibleDe(Carotte::CIBLE_CATEGORIE, $id);
    } elseif ('app_article_show' === $route && $id > 0) {
      $cibles[] = Carotte::cibleDe(Carotte::CIBLE_ARTICLE, $id);
    }

    return $cibles;
  }
}
