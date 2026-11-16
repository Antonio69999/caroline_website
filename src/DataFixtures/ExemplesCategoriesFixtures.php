<?php

namespace App\DataFixtures;

use App\Entity\Bloc;
use App\Entity\Categorie;
use App\Repository\CategorieRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Une catégorie « texte » et une catégorie « vitrine » d'exemple, pour voir le rendu
 * de chaque type sur le site. Elles s'AJOUTENT à la fin du menu, sans toucher au reste :
 *
 *   php bin/console doctrine:fixtures:load --group=exemples --append
 *
 * (Sans --append, Doctrine vide d'abord toute la base.) Relancer la commande ne crée
 * rien de plus : une catégorie dont le titre existe déjà est laissée telle quelle.
 *
 * Les fichiers de la vitrine viennent de src/DataFixtures/files/ et sont copiés dans
 * public/uploads/vitrine/, comme le ferait l'admin.
 */
class ExemplesCategoriesFixtures extends Fixture implements FixtureGroupInterface
{
  public const TITRE_TEXTE = 'Exemple – Page de texte';
  public const TITRE_VITRINE = 'Exemple – Vitrine';

  public function __construct(
    #[Autowire('%kernel.project_dir%')]
    private readonly string $projectDir,
    private readonly CategorieRepository $categories,
  ) {
  }

  public static function getGroups(): array
  {
    return ['exemples'];
  }

  public function load(ObjectManager $manager): void
  {
    $position = $this->prochainePosition();

    if (!$this->existe(self::TITRE_TEXTE)) {
      $manager->persist($this->categorieTexte($position++));
    }

    if (!$this->existe(self::TITRE_VITRINE)) {
      $manager->persist($this->categorieVitrine($position++));
    }

    $manager->flush();
  }

  private function categorieTexte(int $position): Categorie
  {
    // Du HTML tel que le produit l'éditeur de l'admin (Quill) : titres, mise en forme,
    // listes, citation, lien, alignement et retrait.
    $contenu = <<<'HTML'
<h1>Parcours</h1>
<p>Ceci est un <strong>texte d'exemple</strong> pour juger de la mise en page d'une catégorie de type « texte ». On y trouve du <em>texte en italique</em>, du <u>texte souligné</u> et <a href="https://example.com" target="_blank" rel="noopener noreferrer">un lien</a>.</p>
<h2>Formation</h2>
<ol>
  <li>2010 – 2013 : diplôme d'arts plastiques (exemple)</li>
  <li>2013 – 2015 : master en création contemporaine (exemple)</li>
  <li>2016 : résidence d'artiste à l'étranger (exemple)</li>
</ol>
<h2>Expositions</h2>
<ul>
  <li>2018 – <em>Titre de l'exposition</em>, galerie d'exemple, Paris</li>
  <li>2020 – <em>Autre exposition</em>, centre d'art d'exemple, Lyon</li>
  <li class="ql-indent-1">Commissariat : prénom nom (élément de liste en retrait)</li>
  <li>2023 – <em>Dernière exposition</em>, musée d'exemple, Nantes</li>
</ul>
<blockquote>« Une citation mise en valeur, pour voir comment elle s'affiche dans la page. »</blockquote>
<p class="ql-align-center">Un paragraphe centré.</p>
<p class="ql-align-right">Un paragraphe aligné à droite.</p>
<p class="ql-align-justify">Un paragraphe justifié : lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.</p>
<p class="ql-indent-2">Un paragraphe en retrait.</p>
HTML;

    return (new Categorie())
      ->setTitre(self::TITRE_TEXTE)
      ->setDescription('Catégorie d\'exemple de type « texte ».')
      ->setType(Categorie::TYPE_TEXTE)
      ->setContenu($contenu)
      ->setPosition($position);
  }

  private function categorieVitrine(int $position): Categorie
  {
    $categorie = (new Categorie())
      ->setTitre(self::TITRE_VITRINE)
      ->setDescription('Catégorie d\'exemple de type « vitrine ».')
      ->setType(Categorie::TYPE_VITRINE)
      ->setPosition($position);

    $blocs = [
      $this->texte('<p>Une <strong>vitrine</strong> empile des blocs : du texte, des images, des sons et des vidéos. Voici un exemple de chaque, avec les trois largeurs possibles pour les images et les vidéos.</p>'),
      $this->fichier(Bloc::TYPE_IMAGE, 'exemple-image.jpg', 'Image pleine largeur (dégradé de couleurs)', Bloc::LARGEUR_PLEINE),
      $this->texte('<h2>Largeur moyenne</h2><p>Le bloc suivant est une image en largeur moyenne.</p>'),
      $this->fichier(Bloc::TYPE_IMAGE, 'exemple-image.jpg', 'Image de largeur moyenne', Bloc::LARGEUR_MOYENNE),
      $this->texte('<h2>Largeur étroite</h2><p>Et celui-ci, une image étroite.</p>'),
      $this->fichier(Bloc::TYPE_IMAGE, 'exemple-image.jpg', 'Image étroite', Bloc::LARGEUR_ETROITE),
      $this->texte('<h2>Son</h2><p>Un lecteur audio, jamais lancé automatiquement :</p>'),
      $this->fichier(Bloc::TYPE_SON, 'exemple-son.mp3', 'Un son d\'exemple (6 secondes)'),
      $this->texte('<h2>Vidéo</h2><p>Une vidéo en pleine largeur, puis la même en largeur étroite.</p>'),
      $this->fichier(Bloc::TYPE_VIDEO, 'exemple-video.mp4', 'Une vidéo d\'exemple', Bloc::LARGEUR_PLEINE),
      $this->fichier(Bloc::TYPE_VIDEO, 'exemple-video.mp4', 'La même vidéo, en largeur étroite', Bloc::LARGEUR_ETROITE),
      $this->texte('<p>Fin de la vitrine d\'exemple.</p>'),
    ];

    foreach ($blocs as $ordre => $bloc) {
      $categorie->addBloc($bloc->setPosition($ordre));
    }

    return $categorie;
  }

  private function texte(string $html): Bloc
  {
    return (new Bloc())->setType(Bloc::TYPE_TEXTE)->setContenu($html);
  }

  /**
   * Copie le fichier d'exemple dans le dossier d'envoi des blocs, sous un nom unique
   * (un bloc = un fichier : supprimer un bloc dans l'admin supprime son fichier).
   */
  private function fichier(string $type, string $exemple, string $titre, string $largeur = Bloc::LARGEUR_PLEINE): Bloc
  {
    $extension = pathinfo($exemple, PATHINFO_EXTENSION);
    $nom = sprintf('exemple-%s.%s', bin2hex(random_bytes(6)), $extension);

    (new Filesystem())->copy(
      $this->projectDir . '/src/DataFixtures/files/' . $exemple,
      $this->projectDir . '/public/uploads/vitrine/' . $nom,
    );

    $bloc = (new Bloc())->setType($type)->setTitre($titre)->setLargeur($largeur);
    $bloc->setFichierName($nom);

    return $bloc;
  }

  // Même règle que l'admin : une nouvelle catégorie se place après les autres.
  private function prochainePosition(): int
  {
    $max = $this->categories->createQueryBuilder('c')
      ->select('MAX(c.position)')
      ->getQuery()
      ->getSingleScalarResult();

    return ((int) ($max ?? -1)) + 1;
  }

  private function existe(string $titre): bool
  {
    return null !== $this->categories->findOneBy(['titre' => $titre]);
  }
}
