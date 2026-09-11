<?php

namespace App\Entity;

use App\Repository\ArticleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\HttpFoundation\File\File;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

#[ORM\Entity(repositoryClass: ArticleRepository::class)]
#[Vich\Uploadable]
class Article
{
  #[ORM\Id]
  #[ORM\GeneratedValue]
  #[ORM\Column]
  private ?int $id = null;

  #[Assert\NotBlank(message: "N'oublie pas de donner un titre à l'article.")]
  #[ORM\Column(length: 255, nullable: true)]
  private ?string $titre = null;

  #[ORM\Column(type: Types::TEXT, nullable: true)]
  private ?string $description = null;

  #[ORM\Column(nullable: true)]
  private ?string $imageName = null;

  #[Vich\UploadableField(mapping: 'article', fileNameProperty: 'imageName')]
  private ?File $imageFile = null;

  #[ORM\Column(nullable: true)]
  private ?\DateTimeImmutable $creeLe = null;

  #[ORM\Column(nullable: true)]
  private ?\DateTimeImmutable $ModifieLe = null;

  #[ORM\OneToMany(targetEntity: Media::class, mappedBy: 'article', cascade: ['persist', 'remove'], orphanRemoval: true)]
  #[ORM\OrderBy(['position' => 'ASC'])]
  private Collection $media;

  #[Assert\NotNull(message: "Choisis une catégorie pour cet article.")]
  #[ORM\ManyToOne(inversedBy: 'article')]
  private ?Categorie $categorie = null;

  #[Gedmo\SortablePosition]
  #[ORM\Column(type: "integer")]
  private int $position = 0;

  // Permet de préparer un article (brouillon) sans qu'il soit visible sur le site.
  // Par défaut à true : un article existant ou nouvellement créé reste publié
  // tant que Caroline ne décoche pas volontairement la case.
  #[ORM\Column]
  private bool $publie = true;

  public function __construct()
  {
    $this->media = new ArrayCollection();
  }

  public function getId(): ?int
  {
    return $this->id;
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

  public function getDescription(): ?string
  {
    return $this->description;
  }

  public function setDescription(?string $description): static
  {
    $this->description = $description;

    return $this;
  }

  public function setImageFile(?File $imageFile = null): void
  {
    $this->imageFile = $imageFile;

    if (null !== $imageFile) {
      $this->ModifieLe = new \DateTimeImmutable();
    }
  }

  public function getImageFile(): ?File
  {
    return $this->imageFile;
  }

  public function setImageName(?string $imageName): void
  {
    $this->imageName = $imageName;
  }

  public function getImageName(): ?string
  {
    return $this->imageName;
  }

  public function getcreeLe(): ?\DateTimeInterface
  {
    return $this->creeLe;
  }

  public function setcreeLe(?\DateTimeInterface $creeLe): static
  {
    $this->creeLe = $creeLe;

    return $this;
  }

  public function getModifieLe(): ?\DateTimeImmutable
  {
    return $this->ModifieLe;
  }

  public function setModifieLe(?\DateTimeImmutable $ModifieLe): static
  {
    $this->ModifieLe = $ModifieLe;

    return $this;
  }

  public function setMedia(?Collection $media): self
  {
    $this->media = $media;

    return $this;
  }

  /**
   * @return Collection<int, Media>
   */
  public function getMedia(): Collection
  {
    return $this->media;
  }

  public function addMedia(Media $media): static
  {
    if (!$this->media->contains($media)) {
      $this->media->add($media);
      $media->setArticle($this);
    }

    return $this;
  }

  public function removeMedia(Media $media): static
  {
    if ($this->media->removeElement($media)) {
      $media->setArticle(null);
    }

    return $this;
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

  public function getPosition(): int
  {
    return $this->position;
  }

  public function setPosition(int $position): static
  {
    $this->position = $position;

    return $this;
  }

  public function isPublie(): bool
  {
    return $this->publie;
  }

  public function setPublie(bool $publie): static
  {
    $this->publie = $publie;

    return $this;
  }

  public function addMedium(Media $media): static
  {
    return $this->addMedia($media);
  }

  public function removeMedium(Media $media): static
  {
    return $this->removeMedia($media);
  }

  public function __toString(): string
  {
    return $this->titre ?? '';
  }
}
