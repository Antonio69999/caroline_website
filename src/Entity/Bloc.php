<?php

namespace App\Entity;

use App\Repository\BlocRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

/**
 * Un bloc d'une catégorie « vitrine » (le Préambule) : un texte, une image,
 * un son ou une vidéo. Les blocs s'empilent dans l'ordre de leur position ;
 * chacun se met en page tout seul, ce qui garde la page lisible sur mobile.
 */
#[ORM\Entity(repositoryClass: BlocRepository::class)]
#[Vich\Uploadable]
class Bloc
{
  public const TYPE_TEXTE = 'texte';
  public const TYPE_IMAGE = 'image';
  public const TYPE_SON = 'son';
  public const TYPE_VIDEO = 'video';

  public const TYPES = [
    'Texte' => self::TYPE_TEXTE,
    'Image' => self::TYPE_IMAGE,
    'Son (mp3…)' => self::TYPE_SON,
    'Vidéo (mp4…)' => self::TYPE_VIDEO,
  ];

  // Largeur d'affichage d'une image ou d'une vidéo (sur mobile : toujours 100 %).
  public const LARGEUR_PLEINE = 'pleine';
  public const LARGEUR_MOYENNE = 'moyenne';
  public const LARGEUR_ETROITE = 'etroite';

  public const LARGEURS = [
    'Pleine largeur' => self::LARGEUR_PLEINE,
    'Moyenne' => self::LARGEUR_MOYENNE,
    'Étroite' => self::LARGEUR_ETROITE,
  ];

  // Formats acceptés par type de bloc : extensions (nom du fichier) et types MIME
  // (contenu réel du fichier, détecté côté serveur). Pas de SVG : il peut contenir du script.
  public const FICHIERS_ACCEPTES = [
    self::TYPE_IMAGE => [
      'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
      'mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
      'label' => 'une image JPG, PNG, GIF ou WebP',
      'tailleMax' => 10 * 1024 * 1024,
    ],
    self::TYPE_SON => [
      'extensions' => ['mp3', 'm4a', 'ogg', 'wav'],
      'mimes' => ['audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'application/ogg'],
      'label' => 'un son MP3, M4A, OGG ou WAV',
      'tailleMax' => 40 * 1024 * 1024,
    ],
    self::TYPE_VIDEO => [
      'extensions' => ['mp4', 'webm'],
      'mimes' => ['video/mp4', 'video/webm'],
      'label' => 'une vidéo MP4 ou WebM',
      'tailleMax' => 200 * 1024 * 1024,
    ],
  ];

  #[ORM\Id]
  #[ORM\GeneratedValue]
  #[ORM\Column]
  private ?int $id = null;

  #[ORM\ManyToOne(inversedBy: 'blocs')]
  #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
  private ?Categorie $categorie = null;

  #[ORM\Column(length: 20, options: ['default' => self::TYPE_TEXTE])]
  private string $type = self::TYPE_TEXTE;

  // Ordre d'affichage dans la page (le glisser-déposer de l'admin le renumérote).
  #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
  private int $position = 0;

  // Texte : sans usage. Image : description lue par les lecteurs d'écran (obligatoire).
  // Son / vidéo : titre affiché au-dessus du lecteur (facultatif).
  #[ORM\Column(length: 255, nullable: true)]
  private ?string $titre = null;

  // HTML produit par l'éditeur de texte de l'admin (utilisé seulement si type = texte).
  #[ORM\Column(type: Types::TEXT, nullable: true)]
  private ?string $contenu = null;

  #[ORM\Column(length: 255, nullable: true)]
  private ?string $fichierName = null;

  #[Vich\UploadableField(mapping: 'bloc', fileNameProperty: 'fichierName')]
  private ?File $fichierFile = null;

  #[ORM\Column(length: 20, options: ['default' => self::LARGEUR_PLEINE])]
  private string $largeur = self::LARGEUR_PLEINE;

  // Vich ne réagit à un nouveau fichier que si une colonne change : on touche celle-ci.
  #[ORM\Column(nullable: true)]
  private ?\DateTimeImmutable $modifieLe = null;

  public function getId(): ?int
  {
    return $this->id;
  }

  public function getCategorie(): ?Categorie
  {
    return $this->categorie;
  }

  public function setCategorie(?Categorie $categorie): static
  {
    $this->categorie = $categorie;

    return $this;
  }

  public function getType(): string
  {
    return $this->type;
  }

  public function setType(string $type): static
  {
    $this->type = $type;

    return $this;
  }

  public function getPosition(): int
  {
    return $this->position;
  }

  public function setPosition(int $position): static
  {
    $this->position = $position;

    return $this;
  }

  public function getTitre(): ?string
  {
    return $this->titre;
  }

  public function setTitre(?string $titre): static
  {
    $this->titre = $titre;

    return $this;
  }

  public function getContenu(): ?string
  {
    return $this->contenu;
  }

  public function setContenu(?string $contenu): static
  {
    $this->contenu = $contenu;

    return $this;
  }

  public function getFichierName(): ?string
  {
    return $this->fichierName;
  }

  public function setFichierName(?string $fichierName): void
  {
    $this->fichierName = $fichierName;
  }

  public function getFichierFile(): ?File
  {
    return $this->fichierFile;
  }

  public function setFichierFile(?File $fichierFile = null): void
  {
    $this->fichierFile = $fichierFile;

    if (null !== $fichierFile) {
      $this->modifieLe = new \DateTimeImmutable();
    }
  }

  public function getLargeur(): string
  {
    return $this->largeur;
  }

  public function setLargeur(string $largeur): static
  {
    $this->largeur = $largeur;

    return $this;
  }

  public function getModifieLe(): ?\DateTimeImmutable
  {
    return $this->modifieLe;
  }

  /**
   * Un bloc « fichier » (image, son, vidéo) a besoin d'un fichier ; un bloc texte non.
   */
  public function isFichier(): bool
  {
    return isset(self::FICHIERS_ACCEPTES[$this->type]);
  }

  #[Assert\Callback]
  public function validate(ExecutionContextInterface $context): void
  {
    if (self::TYPE_TEXTE === $this->type) {
      if ('' === trim(html_entity_decode(strip_tags((string) $this->contenu)))) {
        $context->buildViolation('Écris un texte dans ce bloc (ou supprime-le).')
          ->atPath('contenu')
          ->addViolation();
      }

      return;
    }

    if (!$this->isFichier()) {
      $context->buildViolation('Type de bloc inconnu.')->atPath('type')->addViolation();

      return;
    }

    $regles = self::FICHIERS_ACCEPTES[$this->type];

    if (null === $this->fichierFile && null === $this->fichierName) {
      $context->buildViolation(sprintf('Choisis un fichier : %s.', $regles['label']))
        ->atPath('fichierFile')
        ->addViolation();
    }

    if (self::TYPE_IMAGE === $this->type && '' === trim((string) $this->titre)) {
      $context->buildViolation("Décris l'image en quelques mots : c'est ce que liront les personnes qui ne peuvent pas la voir.")
        ->atPath('titre')
        ->addViolation();
    }

    // Seul un fichier qui vient d'être envoyé est contrôlé : celui déjà enregistré l'a été à son envoi.
    if ($this->fichierFile instanceof UploadedFile) {
      $extension = strtolower($this->fichierFile->getClientOriginalExtension());
      $mime = $this->fichierFile->getMimeType();

      if (!in_array($extension, $regles['extensions'], true) || !in_array($mime, $regles['mimes'], true)) {
        $context->buildViolation(sprintf('Ce fichier ne convient pas : il faut %s.', $regles['label']))
          ->atPath('fichierFile')
          ->addViolation();
      } elseif ($this->fichierFile->getSize() > $regles['tailleMax']) {
        $context->buildViolation(sprintf('Ce fichier est trop lourd (%d Mo maximum pour ce type de bloc).', $regles['tailleMax'] / 1024 / 1024))
          ->atPath('fichierFile')
          ->addViolation();
      }
    }
  }

  public function __toString(): string
  {
    $nom = array_search($this->type, self::TYPES, true);
    $nom = false === $nom ? 'Bloc' : preg_replace('/\s*\(.*\)$/', '', $nom);

    $resume = self::TYPE_TEXTE === $this->type
      ? trim(html_entity_decode(strip_tags((string) $this->contenu)))
      : trim((string) $this->titre);

    if (mb_strlen($resume) > 60) {
      $resume = mb_substr($resume, 0, 60) . '…';
    }

    return '' === $resume ? $nom : $nom . ' — ' . $resume;
  }
}
