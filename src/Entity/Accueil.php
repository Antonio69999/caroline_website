<?php

namespace App\Entity;

use App\Repository\AccueilRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

/**
 * La page d'accueil : une grande photo, que Caroline choisit et cadre depuis l'admin.
 *
 * Il n'y a qu'une seule ligne dans cette table (créée la première fois que Caroline ouvre
 * « Page d'accueil » dans l'admin). Tant qu'aucune photo n'est choisie, le site garde
 * l'accueil d'origine (la photo des lettres peintes, codée dans app.css) : rien ne change
 * sans qu'elle l'ait voulu.
 *
 * Deux cadrages indépendants, un pour l'ordinateur et un pour le téléphone, parce qu'une photo
 * panoramique ne se montre pas comme une photo verticale. Chacun dit :
 *  - le mode : photo entière (rien n'est rogné, des marges apparaissent) ou plein écran
 *    (l'écran est rempli, les bords dépassants sont rognés) ;
 *  - le point à garder (X, Y en %) : quelle partie de la photo reste visible quand on rogne ;
 *  - le zoom (100 % = pas de zoom), utile en plein écran seulement.
 * Les réglages sont traduits en CSS par style() ; la mise en forme est dans accueil.css.
 */
#[ORM\Entity(repositoryClass: AccueilRepository::class)]
#[Vich\Uploadable]
class Accueil
{
  public const MODE_ENTIERE = 'entiere';
  public const MODE_PLEIN = 'plein';

  public const MODES = [
    'Photo entière (toute la photo est visible, avec des marges si besoin)' => self::MODE_ENTIERE,
    'Plein écran (la photo remplit l\'écran, ses bords sont rognés)' => self::MODE_PLEIN,
  ];

  public const ZOOM_MIN = 100;
  public const ZOOM_MAX = 300;

  public const COULEUR_PAR_DEFAUT = '#ffffff';

  // Pas de SVG (il peut contenir du script) ni de GIF (une animation en plein écran n'a pas sa place ici).
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

  // Photo de l'accueil sur ordinateur (et sur téléphone si aucune photo « téléphone » n'est choisie).
  #[ORM\Column(length: 255, nullable: true)]
  private ?string $imageName = null;

  #[Vich\UploadableField(mapping: 'accueil', fileNameProperty: 'imageName')]
  private ?File $imageFile = null;

  // Photo facultative pensée pour les écrans verticaux.
  #[ORM\Column(length: 255, nullable: true)]
  private ?string $imageMobileName = null;

  #[Vich\UploadableField(mapping: 'accueil', fileNameProperty: 'imageMobileName')]
  private ?File $imageMobileFile = null;

  // Ce que lisent les lecteurs d'écran à la place de la photo (et ce que Google comprend d'elle).
  #[ORM\Column(length: 255, nullable: true)]
  #[Assert\Length(max: 255)]
  private ?string $description = null;

  #[ORM\Column(length: 7, options: ['default' => self::COULEUR_PAR_DEFAUT])]
  #[Assert\Regex(pattern: '/^#[0-9a-fA-F]{6}$/', message: 'Choisis une couleur dans le sélecteur.')]
  private string $couleurFond = self::COULEUR_PAR_DEFAUT;

  #[ORM\Column(length: 20, options: ['default' => self::MODE_ENTIERE])]
  #[Assert\Choice(choices: [self::MODE_ENTIERE, self::MODE_PLEIN])]
  private string $bureauMode = self::MODE_ENTIERE;

  #[ORM\Column(type: Types::SMALLINT, options: ['default' => 50])]
  #[Assert\Range(min: 0, max: 100)]
  private int $bureauX = 50;

  #[ORM\Column(type: Types::SMALLINT, options: ['default' => 50])]
  #[Assert\Range(min: 0, max: 100)]
  private int $bureauY = 50;

  #[ORM\Column(type: Types::SMALLINT, options: ['default' => 100])]
  #[Assert\Range(min: self::ZOOM_MIN, max: self::ZOOM_MAX)]
  private int $bureauZoom = 100;

  // Sur téléphone, « plein écran » est le choix naturel : l'écran est vertical et étroit.
  #[ORM\Column(length: 20, options: ['default' => self::MODE_PLEIN])]
  #[Assert\Choice(choices: [self::MODE_ENTIERE, self::MODE_PLEIN])]
  private string $mobileMode = self::MODE_PLEIN;

  #[ORM\Column(type: Types::SMALLINT, options: ['default' => 50])]
  #[Assert\Range(min: 0, max: 100)]
  private int $mobileX = 50;

  #[ORM\Column(type: Types::SMALLINT, options: ['default' => 50])]
  #[Assert\Range(min: 0, max: 100)]
  private int $mobileY = 50;

  #[ORM\Column(type: Types::SMALLINT, options: ['default' => 100])]
  #[Assert\Range(min: self::ZOOM_MIN, max: self::ZOOM_MAX)]
  private int $mobileZoom = 100;

  // Vich ne réagit à un nouveau fichier que si une colonne change : on touche celle-ci.
  #[ORM\Column(nullable: true)]
  private ?\DateTimeImmutable $modifieLe = null;

  public function getId(): ?int
  {
    return $this->id;
  }

  /**
   * Une photo a-t-elle été choisie ? Sinon, le site affiche l'accueil d'origine.
   */
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

  public function getImageMobileName(): ?string
  {
    return $this->imageMobileName;
  }

  public function setImageMobileName(?string $imageMobileName): void
  {
    $this->imageMobileName = $imageMobileName;
  }

  public function getImageMobileFile(): ?File
  {
    return $this->imageMobileFile;
  }

  public function setImageMobileFile(?File $imageMobileFile = null): void
  {
    $this->imageMobileFile = $imageMobileFile;

    if (null !== $imageMobileFile) {
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

  public function getCouleurFond(): string
  {
    return $this->couleurFond;
  }

  public function setCouleurFond(?string $couleurFond): static
  {
    $this->couleurFond = $couleurFond ?: self::COULEUR_PAR_DEFAUT;

    return $this;
  }

  public function getBureauMode(): string
  {
    return $this->bureauMode;
  }

  public function setBureauMode(?string $mode): static
  {
    $this->bureauMode = $mode ?: self::MODE_ENTIERE;

    return $this;
  }

  public function getBureauX(): int
  {
    return $this->bureauX;
  }

  public function setBureauX(?int $x): static
  {
    $this->bureauX = $x ?? 50;

    return $this;
  }

  public function getBureauY(): int
  {
    return $this->bureauY;
  }

  public function setBureauY(?int $y): static
  {
    $this->bureauY = $y ?? 50;

    return $this;
  }

  public function getBureauZoom(): int
  {
    return $this->bureauZoom;
  }

  public function setBureauZoom(?int $zoom): static
  {
    $this->bureauZoom = $zoom ?? 100;

    return $this;
  }

  public function getMobileMode(): string
  {
    return $this->mobileMode;
  }

  public function setMobileMode(?string $mode): static
  {
    $this->mobileMode = $mode ?: self::MODE_PLEIN;

    return $this;
  }

  public function getMobileX(): int
  {
    return $this->mobileX;
  }

  public function setMobileX(?int $x): static
  {
    $this->mobileX = $x ?? 50;

    return $this;
  }

  public function getMobileY(): int
  {
    return $this->mobileY;
  }

  public function setMobileY(?int $y): static
  {
    $this->mobileY = $y ?? 50;

    return $this;
  }

  public function getMobileZoom(): int
  {
    return $this->mobileZoom;
  }

  public function setMobileZoom(?int $zoom): static
  {
    $this->mobileZoom = $zoom ?? 100;

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
   * Les réglages, sous forme de variables CSS lues par accueil.css (une valeur sortie des
   * limites enregistrées en base est ramenée dans les limites : la page reste affichable).
   */
  public function style(): string
  {
    $couleur = preg_match('/^#[0-9a-fA-F]{6}$/', $this->couleurFond) ? $this->couleurFond : self::COULEUR_PAR_DEFAUT;
    $css = ['--accueil-fond' => $couleur];

    foreach (['bureau' => [$this->bureauMode, $this->bureauX, $this->bureauY, $this->bureauZoom], 'mobile' => [$this->mobileMode, $this->mobileX, $this->mobileY, $this->mobileZoom]] as $appareil => [$mode, $x, $y, $zoom]) {
      $plein = self::MODE_PLEIN === $mode;

      $css['--' . $appareil . '-fit'] = $plein ? 'cover' : 'contain';
      // Photo entière : toujours centrée, le point à garder n'a pas de sens quand rien n'est rogné.
      $css['--' . $appareil . '-pos'] = $plein ? sprintf('%d%% %d%%', max(0, min(100, $x)), max(0, min(100, $y))) : '50% 50%';
      $css['--' . $appareil . '-zoom'] = $plein ? rtrim(rtrim(number_format(max(self::ZOOM_MIN, min(self::ZOOM_MAX, $zoom)) / 100, 2, '.', ''), '0'), '.') : '1';
    }

    return implode(';', array_map(fn (string $nom, string $valeur) => $nom . ':' . $valeur, array_keys($css), $css));
  }

  #[Assert\Callback]
  public function validate(ExecutionContextInterface $context): void
  {
    $this->controlerImage($this->imageFile, 'imageFile', $context);
    $this->controlerImage($this->imageMobileFile, 'imageMobileFile', $context);

    $principale = null !== $this->imageFile || $this->hasImage();
    $mobile = null !== $this->imageMobileFile || (null !== $this->imageMobileName && '' !== $this->imageMobileName);

    if ($mobile && !$principale) {
      $context->buildViolation('Choisis d\'abord la photo principale : la photo « téléphone » ne s\'affiche qu\'avec elle.')
        ->atPath('imageMobileFile')
        ->addViolation();
    }
  }

  // Seul un fichier qui vient d'être envoyé est contrôlé : celui déjà enregistré l'a été à son envoi.
  private function controlerImage(?File $fichier, string $chemin, ExecutionContextInterface $context): void
  {
    if (!$fichier instanceof UploadedFile) {
      return;
    }

    $regles = self::FICHIERS_ACCEPTES;
    $extension = strtolower($fichier->getClientOriginalExtension());

    if (!in_array($extension, $regles['extensions'], true) || !in_array($fichier->getMimeType(), $regles['mimes'], true)) {
      $context->buildViolation(sprintf('Ce fichier ne convient pas : il faut %s.', $regles['label']))
        ->atPath($chemin)
        ->addViolation();
    } elseif ($fichier->getSize() > $regles['tailleMax']) {
      $context->buildViolation(sprintf('Cette photo est trop lourde (%d Mo maximum).', $regles['tailleMax'] / 1024 / 1024))
        ->atPath($chemin)
        ->addViolation();
    }
  }
}
