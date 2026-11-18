<?php

namespace App\Entity;

use App\Repository\CarotteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

/**
 * Une carotte cachée : une petite carotte qui danse, posée sur une page du site, et qui montre
 * une image quand on la trouve et qu'on clique dessus.
 *
 * Caroline les règle une par une dans l'admin (« Carottes cachées »). Chacune dit :
 *  - OÙ elle se cache : partout, sur l'accueil, sur une catégorie ou sur un article (cible) ;
 *  - quelle IMAGE elle révèle ;
 *  - COMMENT elle se montre : taille, transparence, place sur la page, délai d'apparition ;
 *  - COMMENT l'image s'affiche : taille (de la carte de visite au grand écran) et place à l'écran.
 *
 * Deux façons de régler (modeReglage) : « choisi » (les réglages ci-dessus) ou « hasard » (taille,
 * transparence et places tirées au sort à chaque visite : jamais deux fois pareil). Voir reglages().
 *
 * La cible est un simple texte (« partout », « accueil », « categorie:3 », « article:12 »), pas un
 * lien vers l'article ou la catégorie : si la page est supprimée un jour, la carotte ne s'affiche
 * simplement plus, sans rien casser (l'admin le signale).
 *
 * Le site public (App\Service\CarottesDeLaPage + templates/components/carottes.html.twig) ne
 * lit que ce qu'il faut pour la page affichée ; la mise en forme est dans public/asset/css/carottes.css.
 */
#[ORM\Entity(repositoryClass: CarotteRepository::class)]
#[Vich\Uploadable]
class Carotte
{
  public const CIBLE_PARTOUT = 'partout';
  public const CIBLE_ACCUEIL = 'accueil';
  public const CIBLE_CATEGORIE = 'categorie';
  public const CIBLE_ARTICLE = 'article';

  public const MODE_CHOISI = 'choisi';
  public const MODE_HASARD = 'hasard';

  public const MODES = [
    'Réglages choisis par moi (juste en dessous)' => self::MODE_CHOISI,
    'Au hasard à chaque visite (taille, transparence et place changent tout le temps)' => self::MODE_HASARD,
  ];

  public const TAILLES_CAROTTE = [
    'Minuscule' => 'minuscule',
    'Petite' => 'petite',
    'Normale' => 'normale',
    'Grande' => 'grande',
  ];

  public const TAILLES_IMAGE = [
    'Carte de visite' => 'carte',
    'Petite' => 'petite',
    'Moyenne' => 'moyenne',
    'Grande (presque tout l\'écran)' => 'grande',
  ];

  // Les neuf places d'une grille : trois lignes (haut, milieu, bas) sur trois colonnes (gauche, centre, droite)
  public const LIGNES = ['haut', 'milieu', 'bas'];
  public const COLONNES = ['gauche', 'centre', 'droite'];

  // Place de la carotte sur la PAGE (« bas » = tout en bas de la page : il faut descendre pour la trouver)
  public const PLACES_CAROTTE = [
    'En haut de la page, à gauche' => 'haut-gauche',
    'En haut de la page, au centre' => 'haut-centre',
    'En haut de la page, à droite' => 'haut-droite',
    'Au milieu de la page, à gauche' => 'milieu-gauche',
    'Au milieu de la page, au centre' => 'milieu-centre',
    'Au milieu de la page, à droite' => 'milieu-droite',
    'Tout en bas de la page, à gauche' => 'bas-gauche',
    'Tout en bas de la page, au centre' => 'bas-centre',
    'Tout en bas de la page, à droite' => 'bas-droite',
  ];

  // Place de l'image révélée sur l'ÉCRAN
  public const PLACES_IMAGE = [
    'En haut de l\'écran, à gauche' => 'haut-gauche',
    'En haut de l\'écran, au centre' => 'haut-centre',
    'En haut de l\'écran, à droite' => 'haut-droite',
    'Au milieu de l\'écran, à gauche' => 'milieu-gauche',
    'Au centre de l\'écran' => 'milieu-centre',
    'Au milieu de l\'écran, à droite' => 'milieu-droite',
    'En bas de l\'écran, à gauche' => 'bas-gauche',
    'En bas de l\'écran, au centre' => 'bas-centre',
    'En bas de l\'écran, à droite' => 'bas-droite',
  ];

  public const OPACITE_MIN = 5;
  public const OPACITE_MAX = 100;
  // Au hasard, la carotte n'est jamais plus transparente que ça : sinon on ne la trouverait presque jamais
  public const OPACITE_MIN_HASARD = 40;

  public const DELAI_MAX = 600;

  // Pas de SVG (il peut contenir du script) : les mêmes fichiers que pour la photo de l'accueil.
  public const FICHIERS_ACCEPTES = [
    'extensions' => ['jpg', 'jpeg', 'png', 'webp'],
    'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
    'label' => 'une image JPG, PNG ou WebP',
    'tailleMax' => 10 * 1024 * 1024,
  ];

  #[ORM\Id]
  #[ORM\GeneratedValue]
  #[ORM\Column]
  private ?int $id = null;

  // Le nom que Caroline voit dans la liste (« Pas de… », « Sauce karote »). Jamais montré aux visiteurs.
  #[ORM\Column(length: 120)]
  #[Assert\NotBlank(message: 'Donne un nom à cette carotte (il n\'est visible que dans l\'admin).')]
  #[Assert\Length(max: 120)]
  private ?string $nom = null;

  // Décochée : la carotte est rangée de côté, sans être supprimée.
  #[ORM\Column(options: ['default' => true])]
  private bool $actif = true;

  #[ORM\Column(length: 40, options: ['default' => self::CIBLE_PARTOUT])]
  private string $cible = self::CIBLE_PARTOUT;

  #[ORM\Column(length: 255, nullable: true)]
  private ?string $imageName = null;

  #[Vich\UploadableField(mapping: 'carotte', fileNameProperty: 'imageName')]
  private ?File $imageFile = null;

  // Ce que lisent les lecteurs d'écran à la place de l'image
  #[ORM\Column(length: 255, nullable: true)]
  #[Assert\Length(max: 255)]
  private ?string $description = null;

  #[ORM\Column(length: 10, options: ['default' => self::MODE_CHOISI])]
  #[Assert\Choice(choices: [self::MODE_CHOISI, self::MODE_HASARD])]
  private string $modeReglage = self::MODE_CHOISI;

  #[ORM\Column(length: 12, options: ['default' => 'normale'])]
  #[Assert\Choice(choices: self::TAILLES_CAROTTE)]
  private string $carotteTaille = 'normale';

  #[ORM\Column(type: Types::SMALLINT, options: ['default' => 100])]
  #[Assert\Range(min: self::OPACITE_MIN, max: self::OPACITE_MAX, notInRangeMessage: 'La transparence va de {{ min }} (presque invisible) à {{ max }} (bien visible).')]
  private int $carotteOpacite = 100;

  #[ORM\Column(length: 20, options: ['default' => 'bas-droite'])]
  #[Assert\Choice(choices: self::PLACES_CAROTTE)]
  private string $carottePlace = 'bas-droite';

  // Secondes passées sur la page avant que la carotte apparaisse (0 = tout de suite)
  #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
  #[Assert\Range(min: 0, max: self::DELAI_MAX, notInRangeMessage: 'Le délai va de {{ min }} à {{ max }} secondes.')]
  private int $delai = 0;

  #[ORM\Column(length: 12, options: ['default' => 'moyenne'])]
  #[Assert\Choice(choices: self::TAILLES_IMAGE)]
  private string $imageTaille = 'moyenne';

  #[ORM\Column(length: 20, options: ['default' => 'milieu-centre'])]
  #[Assert\Choice(choices: self::PLACES_IMAGE)]
  private string $imagePlace = 'milieu-centre';

  #[ORM\Column(nullable: true)]
  private ?\DateTimeImmutable $creeLe = null;

  // Vich ne réagit à un nouveau fichier que si une colonne change : on touche celle-ci.
  #[ORM\Column(nullable: true)]
  private ?\DateTimeImmutable $modifieLe = null;

  public function getId(): ?int
  {
    return $this->id;
  }

  public function getNom(): ?string
  {
    return $this->nom;
  }

  public function setNom(?string $nom): static
  {
    $this->nom = null === $nom ? null : trim($nom);

    return $this;
  }

  public function isActif(): bool
  {
    return $this->actif;
  }

  public function setActif(?bool $actif): static
  {
    $this->actif = (bool) $actif;

    return $this;
  }

  public function getCible(): string
  {
    return $this->cible;
  }

  public function setCible(?string $cible): static
  {
    $this->cible = $cible ?: self::CIBLE_PARTOUT;

    return $this;
  }

  /** « categorie:3 » pour la catégorie n° 3, « article:12 » pour l'article n° 12. */
  public static function cibleDe(string $type, int $id): string
  {
    return $type . ':' . $id;
  }

  /**
   * Le genre de page visé (partout, accueil, categorie, article), ou null si le texte enregistré
   * ne veut rien dire (la carotte ne s'affichera alors nulle part).
   */
  public function cibleType(): ?string
  {
    if (in_array($this->cible, [self::CIBLE_PARTOUT, self::CIBLE_ACCUEIL], true)) {
      return $this->cible;
    }

    if (preg_match('/^(categorie|article):[1-9]\d*$/', $this->cible, $m)) {
      return $m[1];
    }

    return null;
  }

  /** Le numéro de la catégorie ou de l'article visé (null pour « partout » et « accueil »). */
  public function cibleId(): ?int
  {
    return null !== $this->cibleType() && str_contains($this->cible, ':')
      ? (int) substr($this->cible, strpos($this->cible, ':') + 1)
      : null;
  }

  public function hasImage(): bool
  {
    return null !== $this->imageName && '' !== $this->imageName;
  }

  public function getImageName(): ?string
  {
    return $this->imageName;
  }

  public function setImageName(?string $imageName): void
  {
    $this->imageName = $imageName;
  }

  public function getImageFile(): ?File
  {
    return $this->imageFile;
  }

  public function setImageFile(?File $imageFile = null): void
  {
    $this->imageFile = $imageFile;

    if (null !== $imageFile) {
      $this->modifieLe = new \DateTimeImmutable();
    }
  }

  public function getDescription(): ?string
  {
    return $this->description;
  }

  public function setDescription(?string $description): static
  {
    $description = null === $description ? null : trim($description);
    $this->description = '' === $description ? null : $description;

    return $this;
  }

  public function getModeReglage(): string
  {
    return $this->modeReglage;
  }

  public function setModeReglage(?string $mode): static
  {
    $this->modeReglage = $mode ?: self::MODE_CHOISI;

    return $this;
  }

  public function getCarotteTaille(): string
  {
    return $this->carotteTaille;
  }

  public function setCarotteTaille(?string $taille): static
  {
    $this->carotteTaille = $taille ?: 'normale';

    return $this;
  }

  public function getCarotteOpacite(): int
  {
    return $this->carotteOpacite;
  }

  public function setCarotteOpacite(?int $opacite): static
  {
    $this->carotteOpacite = $opacite ?? 100;

    return $this;
  }

  public function getCarottePlace(): string
  {
    return $this->carottePlace;
  }

  public function setCarottePlace(?string $place): static
  {
    $this->carottePlace = $place ?: 'bas-droite';

    return $this;
  }

  public function getDelai(): int
  {
    return $this->delai;
  }

  public function setDelai(?int $delai): static
  {
    $this->delai = $delai ?? 0;

    return $this;
  }

  public function getImageTaille(): string
  {
    return $this->imageTaille;
  }

  public function setImageTaille(?string $taille): static
  {
    $this->imageTaille = $taille ?: 'moyenne';

    return $this;
  }

  public function getImagePlace(): string
  {
    return $this->imagePlace;
  }

  public function setImagePlace(?string $place): static
  {
    $this->imagePlace = $place ?: 'milieu-centre';

    return $this;
  }

  public function getCreeLe(): ?\DateTimeImmutable
  {
    return $this->creeLe;
  }

  public function setCreeLe(?\DateTimeImmutable $creeLe): static
  {
    $this->creeLe = $creeLe;

    return $this;
  }

  public function getModifieLe(): ?\DateTimeImmutable
  {
    return $this->modifieLe;
  }

  public function setModifieLe(?\DateTimeImmutable $modifieLe): static
  {
    $this->modifieLe = $modifieLe;

    return $this;
  }

  /**
   * Les réglages à appliquer pour CETTE visite. En mode « choisi », ce que Caroline a réglé (une
   * valeur sortie des listes en base est ramenée à une valeur permise : la page reste affichable).
   * En mode « hasard », tout est tiré au sort, donc différent à chaque affichage.
   *
   * @return array{taille: string, opacite: int, ligne: string, colonne: string, imageTaille: string, imageLigne: string, imageColonne: string}
   */
  public function reglages(): array
  {
    if (self::MODE_HASARD === $this->modeReglage) {
      $taille = self::tirer(array_values(self::TAILLES_CAROTTE));
      // Par pas de 10 % (40, 50 … 100)
      $opacite = random_int(intdiv(self::OPACITE_MIN_HASARD, 10), intdiv(self::OPACITE_MAX, 10)) * 10;
      $place = self::tirer(array_values(self::PLACES_CAROTTE));
      $imageTaille = self::tirer(array_values(self::TAILLES_IMAGE));
      $imagePlace = self::tirer(array_values(self::PLACES_IMAGE));
    } else {
      $taille = in_array($this->carotteTaille, self::TAILLES_CAROTTE, true) ? $this->carotteTaille : 'normale';
      $opacite = max(self::OPACITE_MIN, min(self::OPACITE_MAX, $this->carotteOpacite));
      $place = in_array($this->carottePlace, self::PLACES_CAROTTE, true) ? $this->carottePlace : 'bas-droite';
      $imageTaille = in_array($this->imageTaille, self::TAILLES_IMAGE, true) ? $this->imageTaille : 'moyenne';
      $imagePlace = in_array($this->imagePlace, self::PLACES_IMAGE, true) ? $this->imagePlace : 'milieu-centre';
    }

    [$ligne, $colonne] = explode('-', $place);
    [$imageLigne, $imageColonne] = explode('-', $imagePlace);

    return [
      'taille' => $taille,
      'opacite' => $opacite,
      'ligne' => $ligne,
      'colonne' => $colonne,
      'imageTaille' => $imageTaille,
      'imageLigne' => $imageLigne,
      'imageColonne' => $imageColonne,
    ];
  }

  /** @param list<string> $valeurs */
  private static function tirer(array $valeurs): string
  {
    return $valeurs[random_int(0, count($valeurs) - 1)];
  }

  #[Assert\Callback]
  public function validate(ExecutionContextInterface $context): void
  {
    if (null === $this->cibleType()) {
      $context->buildViolation('Choisis la page où cacher cette carotte dans la liste.')
        ->atPath('cible')
        ->addViolation();
    }

    if (!$this->hasImage() && null === $this->imageFile) {
      $context->buildViolation('Choisis l\'image que cette carotte révèle quand on la trouve.')
        ->atPath('imageFile')
        ->addViolation();
    }

    $this->controlerImage($this->imageFile, $context);
  }

  // Seul un fichier qui vient d'être envoyé est contrôlé : celui déjà enregistré l'a été à son envoi.
  private function controlerImage(?File $fichier, ExecutionContextInterface $context): void
  {
    if (!$fichier instanceof UploadedFile) {
      return;
    }

    $regles = self::FICHIERS_ACCEPTES;
    $extension = strtolower($fichier->getClientOriginalExtension());

    if (!in_array($extension, $regles['extensions'], true) || !in_array($fichier->getMimeType(), $regles['mimes'], true)) {
      $context->buildViolation(sprintf('Ce fichier ne convient pas : il faut %s.', $regles['label']))
        ->atPath('imageFile')
        ->addViolation();
    } elseif ($fichier->getSize() > $regles['tailleMax']) {
      $context->buildViolation(sprintf('Cette image est trop lourde (%d Mo maximum).', $regles['tailleMax'] / 1024 / 1024))
        ->atPath('imageFile')
        ->addViolation();
    }
  }
}
