<?php

namespace App\Entity;

use App\Repository\CategorieRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CategorieRepository::class)]
class Categorie
{
  // Catégorie classique : une grille d'articles (images, légendes, description).
  public const TYPE_GALERIE = 'galerie';

  // Page de texte mis en forme (ex. le CV de l'artiste) à la place de la grille.
  public const TYPE_TEXTE = 'texte';

  // Page libre faite de blocs empilés (texte, image, son, vidéo) : le Préambule.
  public const TYPE_VITRINE = 'vitrine';

  #[ORM\Id]
  #[ORM\GeneratedValue]
  #[ORM\Column]
  private ?int $id = null;

  #[ORM\Column(length: 255, nullable: true)]
  private ?string $titre = null;

  #[ORM\Column(type: Types::TEXT, nullable: true)]
  private ?string $description = null;

  // Par défaut 'galerie' : toutes les catégories existantes gardent leur affichage actuel.
  #[ORM\Column(length: 20, options: ['default' => self::TYPE_GALERIE])]
  private string $type = self::TYPE_GALERIE;

  // HTML produit par l'éditeur de texte de l'admin (utilisé seulement si type = texte).
  #[ORM\Column(type: Types::TEXT, nullable: true)]
  private ?string $contenu = null;

  #[ORM\OneToMany(mappedBy: 'categorie', targetEntity: Article::class)]
  private Collection $article;

  // Blocs de la page (utilisés seulement si type = vitrine), dans l'ordre d'affichage.
  // Valid : sans lui, les règles de Bloc::validate() (texte vide, fichier manquant ou
  // refusé…) ne seraient jamais appliquées quand on enregistre la catégorie.
  #[ORM\OneToMany(mappedBy: 'categorie', targetEntity: Bloc::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
  #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
  #[Assert\Valid]
  private Collection $blocs;

  #[ORM\Column]
  private ?\DateTimeImmutable $creeLe = null;

  #[ORM\Column(nullable: true)]
  private ?\DateTimeImmutable $modifieeLe = null;

  #[Gedmo\SortablePosition]
  #[ORM\Column(type: 'integer')]
  private int $position = 0;

  public function __construct()
  {
    $this->article = new ArrayCollection();
    $this->blocs = new ArrayCollection();
    $this->creeLe = new \DateTimeImmutable();
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

  public function getType(): string
  {
    return $this->type;
  }

  public function setType(string $type): static
  {
    $this->type = $type;

    return $this;
  }

  public function isTexte(): bool
  {
    return self::TYPE_TEXTE === $this->type;
  }

  public function isVitrine(): bool
  {
    return self::TYPE_VITRINE === $this->type;
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

  /**
   * @return Collection<int, Bloc>
   */
  public function getBlocs(): Collection
  {
    return $this->blocs;
  }

  public function addBloc(Bloc $bloc): static
  {
    if (!$this->blocs->contains($bloc)) {
      $this->blocs->add($bloc);
      $bloc->setCategorie($this);
    }

    return $this;
  }

  public function removeBloc(Bloc $bloc): static
  {
    // orphanRemoval supprime la ligne (et Vich le fichier) ; on laisse bloc->categorie tel quel
    // car la colonne n'est pas nullable.
    $this->blocs->removeElement($bloc);

    return $this;
  }

  /**
   * @return Collection<int, Article>
   */
  public function getArticle(): Collection
  {
    return $this->article;
  }

  public function addArticle(Article $article): static
  {
    if (!$this->article->contains($article)) {
      $this->article->add($article);
      $article->setCategorie($this);
    }

    return $this;
  }

  public function removeArticle(Article $article): static
  {
    if ($this->article->removeElement($article)) {
      // set the owning side to null (unless already changed)
      if ($article->getCategorie() === $this) {
        $article->setCategorie(null);
      }
    }

    return $this;
  }

  public function getCreeLe(): ?\DateTimeImmutable
  {
    return $this->creeLe;
  }

  public function setCreeLe(\DateTimeImmutable $creeLe): static
  {
    $this->creeLe = new \DateTimeImmutable();

    return $this;
  }

  public function getModifieeLe(): ?\DateTimeImmutable
  {
    return $this->modifieeLe;
  }

  public function setModifieeLe(?\DateTimeImmutable $modifierLe): static
  {
    $this->modifieeLe = $modifierLe;

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

  public function __toString(): string
  {
    return $this->titre ?? 'Catégorie sans titre';
  }
}
