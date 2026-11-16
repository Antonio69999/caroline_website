<?php

namespace App\Controller\Admin;

use App\Entity\Bloc;
use App\Entity\Categorie;
use App\Form\BlocType;
use App\Repository\ArticleRepository;
use App\Service\UploadLimits;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class CategorieCrudController extends AbstractCrudController
{
  public function __construct(private readonly ArticleRepository $articleRepository) {}

  public static function getEntityFqcn(): string
  {
    return Categorie::class;
  }

  #[Route('/admin/categorie/reorder', name: 'admin_categorie_reorder', methods: ['POST'])]
  public function reorder(Request $request, EntityManagerInterface $em): JsonResponse
  {
    $data = json_decode($request->getContent(), true);
    $orderedIds = $data['orderedIds'] ?? [];

    if (empty($orderedIds)) {
      return new JsonResponse(['status' => 'error', 'message' => 'Aucune donnée reçue'], 400);
    }

    $repository = $em->getRepository(Categorie::class);

    $categories = [];
    $currentPositions = [];

    // 1. On récupère les catégories qu'on vient de bouger et on note leurs positions actuelles
    foreach ($orderedIds as $id) {
      $categorie = $repository->find($id);
      if ($categorie) {
        $categories[] = $categorie;
        $currentPositions[] = $categorie->getPosition();
      }
    }

    // 2. On trie les positions du plus petit au plus grand
    sort($currentPositions);

    // 3. On redistribue ces positions dans le nouvel ordre choisi par la souris
    foreach ($categories as $index => $categorie) {
      $categorie->setPosition($currentPositions[$index]);
    }

    $em->flush();

    return new JsonResponse(['status' => 'success']);
  }

  public function configureCrud(Crud $crud): Crud
  {
    return $crud
      ->setPageTitle('index', '📁 Liste des Catégories')
      ->setPageTitle('new', 'Créer une nouvelle catégorie')
      ->setPageTitle('edit', 'Modifier la catégorie')
      // On permet de chercher une catégorie par son titre ou sa description
      ->setSearchFields(['titre', 'description'])
      // On trie selon l'ordre choisi dans l'admin (celui utilisé dans la sidebar du site)
      ->setDefaultSort(['position' => 'ASC'])
      // Peu de catégories en pratique : on garde tout sur une seule page pour que le
      // glisser-déposer et la numérotation visuelle restent simples
      ->setPaginatorPageSize(100)
      // On met les boutons d'action sur la même ligne pour que ce soit plus joli
      ->showEntityActionsInlined()
      ->overrideTemplate('crud/index', 'admin/categorie/index.html.twig');
  }

  public function configureAssets(Assets $assets): Assets
  {
    return $assets
      ->addHtmlContentToHead('<script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>')
      ->addHtmlContentToHead('<link href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" rel="stylesheet">')
      ->addHtmlContentToHead('<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>')
      ->addCssFile('asset/css/admin_media.css')
      // Titres et polices : les mêmes que sur le site (c'est ce fichier qui fait que « Titre 2 » mesure 20 px)
      ->addCssFile('asset/css/category_text.css')
      ->addCssFile('asset/css/admin_category_rich_text.css')
      ->addCssFile('asset/css/admin_vitrine_blocs.css')
      ->addCssFile('asset/css/admin_draft.css')
      ->addJsFile('asset/js/admin_categorie_drag_drop.js')
      ->addJsFile('asset/js/admin_categorie_type.js')
      ->addJsFile('asset/js/admin_category_rich_text.js')
      // Même réduction / conversion des photos que pour les articles (utilisée par la vitrine)
      ->addJsFile('asset/js/admin_image_resize.js')
      ->addJsFile('asset/js/admin_vitrine_blocs.js')
      // Brouillon automatique (gardé dans le navigateur), puis l'alerte qui s'en sert
      ->addJsFile('asset/js/admin_draft.js')
      ->addJsFile('asset/js/admin_unsaved_warning.js');
  }

  public function configureFields(string $pageName): iterable
  {
    return [
      // On cache l'ID partout, ça n'intéresse pas l'utilisateur
      IdField::new('id')->hideOnForm()->hideOnIndex(),

      TextField::new('titre', 'Nom de la catégorie')
        ->setHelp('Ce nom apparaît dans le menu, en titre de la page et dans les résultats de Google, et il entre dans l\'adresse de la page. Choisis les mots que les gens taperaient pour te trouver : « Gravure sur verre » plutôt que « Verre ».'),

      ChoiceField::new('type', 'Type de page')
        ->setChoices([
          'Galerie (articles avec images)' => Categorie::TYPE_GALERIE,
          'Page de texte (par ex. un CV)' => Categorie::TYPE_TEXTE,
          'Vitrine (page libre : textes, images, sons, vidéos)' => Categorie::TYPE_VITRINE,
        ])
        ->renderExpanded()
        ->renderAsBadges([
          Categorie::TYPE_GALERIE => 'secondary',
          Categorie::TYPE_TEXTE => 'info',
          Categorie::TYPE_VITRINE => 'success',
        ])
        ->setRequired(true)
        ->setHelp('Une galerie affiche ses articles. Une page de texte affiche à la place un texte mis en forme (par exemple ton CV). Une vitrine est une page libre faite de blocs empilés (textes, images, sons, vidéos), comme le Préambule. Pour ces deux derniers types, les articles rattachés à la catégorie ne sont pas montrés.'),

      // On cache la description longue sur la liste pour ne pas casser le tableau
      TextEditorField::new('description', 'Description')
        ->hideOnIndex()
        ->setHelp('Affichée sous les images (galeries). Le début sert aussi de résumé dans Google (environ 150 caractères) : dis en une ou deux phrases ce qu\'on trouve ici — technique, matière, lieu, ce qui rend ce travail unique. Sans texte, Google n\'a que les titres pour comprendre la page.'),

      // On garde volontairement TextEditorField : Trix reste le fallback si Quill
      // ne charge pas. Le JS remplace seulement l'interface visible du champ contenu.
      TextEditorField::new('contenu', 'Contenu de la page')
        ->onlyOnForms()
        ->setNumOfRows(20)
        ->setHelp('Éditeur avancé : polices, titres 1 à 6 (leur taille en pixels est écrite dans le menu), gras, italique, souligné, couleurs, listes, alignement, indentation, citations, séparateurs, HTML, annuler/rétablir et plein écran. Pour faire un lien : sélectionne un mot ou un groupe de mots, puis « Lier vers un site » (autre site, e-mail…) ou « Lier vers un article » (une œuvre du site).')
        ->setFormTypeOption('attr', [
          'data-rich-category-editor' => '1',
          'data-linkable-articles' => $this->linkableArticlesJson($pageName),
        ]),

      // Les blocs d'une vitrine : texte, image, son ou vidéo, empilés dans l'ordre choisi.
      // Pas de TextEditorField ici : chaque bloc texte utilise le même éditeur Quill (voir BlocType).
      // La barre « Ajouter un texte / des images… » est construite par admin_vitrine_blocs.js
      // au-dessus de la liste ; le bouton « Ajouter un nouvel élément » d'EasyAdmin, lui, est
      // masqué (le script s'en sert en coulisses pour créer chaque bloc).
      CollectionField::new('blocs', 'Contenu de la page')
        ->onlyOnForms()
        ->setColumns(12)
        ->setEntryType(BlocType::class)
        ->setEntryIsComplex()
        ->allowAdd()
        ->allowDelete()
        // Un bloc neuf démarre en « Texte » (sinon aucun type ne serait coché)
        ->setFormTypeOption('prototype_data', new Bloc())
        ->setFormTypeOption('entry_options.linkable_articles', $this->linkableArticlesJson($pageName))
        // Limites d'envoi du serveur : le script refuse un ajout qui les dépasserait,
        // au lieu de laisser PHP jeter tout le formulaire à l'enregistrement.
        ->setFormTypeOption('row_attr', [
          'data-max-bytes' => (string) UploadLimits::perFile(),
          'data-max-total-bytes' => (string) UploadLimits::total(),
          'data-max-files' => (string) UploadLimits::maxFiles(),
        ])
        ->setHelp('La page se lit de haut en bas, dans l\'ordre des blocs. Ajoute-en avec les boutons ci-dessus (ou glisse tes fichiers dans la zone), puis déplace-les avec ⠿ ou ▲▼. Chaque bloc s\'adapte tout seul aux écrans de téléphone.'),

      // Le glisser-déposer (et les flèches ▲▼) de la liste s'occupent de l'ordre
      IntegerField::new('position', 'Ordre')->onlyOnIndex(),

      // On formate les dates proprement pour que ce soit lisible !
      DateTimeField::new('CreeLe', 'Créée le')->setFormat('dd/MM/yyyy à HH:mm')->hideOnForm(),
      DateTimeField::new('ModifieeLe', 'Dernière modif.')->setFormat('dd/MM/yyyy à HH:mm')->hideOnForm()->onlyOnDetail(),
    ];
  }

  /**
   * Articles que l'éditeur avancé peut citer, au format lu par admin_category_rich_text.js.
   * Calculé seulement sur les formulaires : inutile de requêter pour la liste.
   */
  private function linkableArticlesJson(string $pageName): string
  {
    if (!in_array($pageName, [Crud::PAGE_NEW, Crud::PAGE_EDIT], true)) {
      return '[]';
    }

    $articles = array_map(fn ($article) => [
      'titre' => $article->getTitre() ?: 'Article sans titre',
      'categorie' => $article->getCategorie()?->getTitre() ?: 'Sans catégorie',
      'url' => $this->generateUrl('app_article_show', ['id' => $article->getId()]),
    ], $this->articleRepository->findPublishedForLinks());

    return json_encode($articles, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
  }

  public function updateEntity(EntityManagerInterface $em, $entityInstance): void
  {
    // dd($entityInstance);
    if (!$entityInstance instanceof Categorie) return;

    $entityInstance->setModifieeLe(new \DateTimeImmutable);
    // dd($entityInstance);
    parent::updateEntity($em, $entityInstance); //appel de la méthode parent AbstractController
  }

  public function persistEntity(EntityManagerInterface $em, $entityInstance): void
  {
    if ($entityInstance instanceof Categorie) {
      $entityInstance->setCreeLe(new \DateTimeImmutable);

      // Une nouvelle catégorie arrive en dernière position (elle apparaîtra
      // en bas de la sidebar, Caroline peut ensuite la remonter si besoin)
      $maxPosition = $em->getRepository(Categorie::class)
        ->createQueryBuilder('c')
        ->select('MAX(c.position)')
        ->getQuery()
        ->getSingleScalarResult();

      $entityInstance->setPosition(($maxPosition ?? -1) + 1);

      parent::persistEntity($em, $entityInstance); //appel de la méthode parent AbstractController
    } else {
      throw new \Exception('Entity is not an instance of Categorie');
    }
  }
}
