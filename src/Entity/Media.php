<?php

namespace App\Entity;

use App\Repository\MediaRepository;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

#[ORM\Entity(repositoryClass: MediaRepository::class)]
#[Vich\Uploadable]
class Media
{
  #[ORM\Id]
  #[ORM\GeneratedValue]
  #[ORM\Column]
  private ?int $id = null;

  // Ce champ n'est jamais rempli par un formulaire (les photos sont uploadées via
  // MediaUploadHandler, pas via Vich) : il ne sert qu'à donner à Vich l'attribut
  // #[Vich\UploadableField] dont il a besoin pour savoir supprimer le bon fichier
  // sur le disque quand ce Media est supprimé.
  #[Vich\UploadableField(mapping: 'media', fileNameProperty: 'imageName')]
  private ?File $imageFile = null;

  #[ORM\Column(type: "string", length: 255, nullable: true)]
  private ?string $imageName = null;

  #[ORM\ManyToOne(targetEntity: Article::class, inversedBy: 'media')]
  #[ORM\JoinColumn(name: "article_id", referencedColumnName: "id", onDelete: "CASCADE")]
  private ?Article $article = null;

  #[ORM\Column(type: "datetime", nullable: true)]
  private ?\DateTimeInterface $creeLe = null;

  #[ORM\Column(nullable: true)]
  private ?\DateTimeImmutable $modifieLe = null;

  #[Assert\NotBlank(message: "Ajoute une légende : c'est ce que liront les personnes qui ne peuvent pas voir la photo.")]
  #[ORM\Column(length: 255, nullable: true)]
  private ?string $legende = null;

  #[Gedmo\SortablePosition]
  #[ORM\Column(type: "integer")]
  private int $position = 0;

  public function __construct()
  {
    $this->creeLe = new \DateTime();
  }

  public function getId(): ?int
  {
    return $this->id;
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

  public function setImageFile(?File $imageFile = null): void
  {
    $this->imageFile = $imageFile;
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

  public function getCreeLe(): ?\DateTimeInterface
  {
    return $this->creeLe;
  }

  public function setCreeLe(\DateTimeInterface $creeLe): self
  {
    $this->creeLe = $creeLe;

    return $this;
  }

  /**
   * Get the value of modifieLe
   */
  public function getModifieLe()
  {
    return $this->modifieLe;
  }

  public function setModifieLe(\DateTimeInterface $modifieLe): self
  {
    $this->modifieLe = $modifieLe;

    return $this;
  }

  public function getArticle(): ?Article
  {
    return $this->article;
  }

  public function setArticle(?Article $article): static
  {
    $this->article = $article;

    return $this;
  }

  public function __toString(): string
  {
    return (string) ($this->imageName ?? 'no name');
  }

  public function getLegende(): ?string
  {
    return $this->legende;
  }

  public function setLegende(?string $legende): static
  {
    $this->legende = $legende;

    return $this;
  }
}
