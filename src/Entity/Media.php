<?php

namespace App\Entity;

use App\Repository\MediaRepository;
use Doctrine\ORM\Mapping as ORM;
use Vich\UploaderBundle\Mapping\Attribute as Vich;
use Symfony\Component\HttpFoundation\File\File;

#[ORM\Entity(repositoryClass: MediaRepository::class)]
#[Vich\Uploadable]
class Media
{
  #[ORM\Id]
  #[ORM\GeneratedValue]
  #[ORM\Column]
  private ?int $id = null;

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

  #[ORM\Column(length: 255, nullable: true)]
  private ?string $legende = null;

  public function __construct()
  {
    $this->creeLe = new \DateTime();
  }

  public function getId(): ?int
  {
    return $this->id;
  }


  public function setImageFile(?File $imageFile = null): void
  {
    $this->imageFile = $imageFile;

    if (null !== $imageFile) {
      $this->modifieLe = new \DateTimeImmutable();
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
