<?php

namespace App\Controller\Admin;

use App\Entity\Article;
use App\Entity\Media;
use App\Service\MediaUploadHandler;
use App\Service\PhotoBatchUpload;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use \Symfony\Component\Routing\Attribute\Route;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Config\Asset;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;


class ArticleCrudController extends AbstractCrudController
{

  public function __construct(
    private readonly EntityManagerInterface $em,
    private readonly RequestStack $requestStack,
    private readonly MediaUploadHandler $mediaUploadHandler,
    private readonly PhotoBatchUpload $photoBatchUpload,
  ) {}

  public const ARTICLE_BASE_PATH = '/uploads/images/article/';
  public const ARTICLE_UPLOAD_DIR = 'public/uploads/images/article';

  // Largement au-dessus du nombre d'articles du site : en pratique, une seule page
  public const ARTICLES_PAR_PAGE = 1000;

  public static function getEntityFqcn(): string
  {
    return Article::class;
  }

  #[Route('/admin/article/reorder', name: 'admin_article_reorder', methods: ['POST'])]
  public function reorder(Request $request): JsonResponse
  {
    $data = json_decode($request->getContent(), true);
    $orderedIds = $data['orderedIds'] ?? [];

    if (empty($orderedIds)) {
      return new JsonResponse(['status' => 'error', 'message' => 'Aucune donnée reçue'], 400);
    }

    $repository = $this->em->getRepository(Article::class);

    $articles = [];
    $currentPositions = [];

    // 1. On récupère les articles qu'on vient de bouger et on note leurs positions actuelles
    foreach ($orderedIds as $id) {
      $article = $repository->find($id);
      if ($article) {
        $articles[] = $article;
        $currentPositions[] = $article->getPosition();
      }
    }

    // 2. On trie les positions du plus petit au plus grand (ex: 10, 15, 42)
    sort($currentPositions);

    // 3. On redistribue ces positions dans le nouvel ordre choisi par la souris
    foreach ($articles as $index => $article) {
      $article->setPosition($currentPositions[$index]);
    }

    $this->em->flush();

    return new JsonResponse(['status' => 'success']);
  }

  public function configureCrud(Crud $crud): Crud
  {
    return $crud
      // Regroupés par catégorie (dans l'ordre choisi pour la sidebar), puis par
      // position au sein de chaque catégorie : le glisser-déposer ne réordonne
      // jamais entre catégories, donc c'est aussi l'ordre qui doit s'afficher.
      // L'année n'intervient pas : l'ordre est libre, l'année sert à s'y retrouver.
      ->setDefaultSort(['categorie.position' => 'ASC', 'position' => 'ASC'])
      ->setPageTitle('index', '🎨 Liste des Articles')
      // Définir sur quels champs la barre de recherche globale fonctionne
      ->setSearchFields(['titre', 'description'])
      // Tous les articles sur une seule page : le glisser-déposer ne fonctionne qu'entre
      // les lignes affichées. Avec 20 par page, une catégorie à cheval sur deux pages ne
      // pouvait plus être réordonnée (impossible de remonter un article de la page 2).
      ->setPaginatorPageSize(self::ARTICLES_PAR_PAGE)
      ->showEntityActionsInlined()
      // Ajoute la galerie de photos triable sous le formulaire d'édition
      ->overrideTemplate('crud/edit', 'admin/article/edit.html.twig')
      // Ajoute un petit mode d'emploi en haut de la liste
      ->overrideTemplate('crud/index', 'admin/article/index.html.twig');
  }



  // LE GAME CHANGER : Les filtres sur le côté droit
  public function configureFilters(Filters $filters): Filters
  {
    return $filters
      ->add(EntityFilter::new('categorie')) // Filtre par catégorie (menu déroulant automatique !)
      ->add('creeLe'); // Filtre par date
  }

  public function configureFields(string $pageName): iterable
  {
    return [
      // Le tri des colonnes est désactivé sur la liste : les articles sont
      // regroupés par catégorie puis triés par position (voir configureCrud),
      // et cet ordre doit rester fixe pour que le glisser-déposer garde un sens.
      // IdField::new('id')->onlyOnIndex()->setSortable(false), // Afficher l'ID seulement dans la liste

      TextField::new('titre', 'Titre de l\'article')
        ->setSortable(false)
        ->setHelp('Ce titre est celui de la page dans Google et il entre dans son adresse. Un titre précis (« Vase gravé aux fougères ») est trouvé plus facilement que « Sans titre 12 ».'),

      // On affiche la catégorie directement dans la liste
      AssociationField::new('categorie', 'Catégorie')
        ->autocomplete() // Ajoute une barre de recherche dans le menu déroulant du formulaire (très utile si tu as 50 catégories)
        ->setSortable(false),

      IntegerField::new('annee', 'Année')
        ->setSortable(false)
        ->setFormTypeOption('attr', ['min' => 1900, 'max' => 2100, 'placeholder' => 'ex. ' . date('Y')])
        ->setHelp('L\'année de l\'œuvre ou de l\'atelier, pour t\'y retrouver dans la liste des articles. Elle n\'est pas affichée sur le site et ne change pas l\'ordre : c\'est toi qui le choisis dans la liste. Facultative.'),

      // Rendre le tableau plus propre : on cache la description longue sur la liste (index)
      TextEditorField::new('description')
        ->hideOnIndex()
        ->setHelp('Le début de ce texte sert de résumé dans Google (environ 150 caractères). Précise la technique, le support, les dimensions, l\'année, le lieu : ce sont les mots que les gens cherchent.'),

      // Formatage propre des dates
      DateTimeField::new('creeLe', 'Créé le')->setFormat('dd/MM/yyyy HH:mm')->hideOnForm()->setSortable(false),
      DateTimeField::new('ModifieLe', 'Modifié le')->setFormat('dd/MM/yyyy HH:mm')->onlyOnDetail(),

      Field::new('multipleFiles', 'Ajouter plusieurs images d\'un coup')
        ->setFormType(FileType::class)
        ->setFormTypeOptions([
          'multiple' => true, // Permet de sélectionner plusieurs fichiers
          'mapped' => false,  // Ne cherche pas à l'écrire dans la table Article
          'required' => false,
          'attr' => [
            'accept' => 'image/*', // Ouvre directement la fenêtre sur les images
          ] + $this->photoBatchUpload->inputAttributes(), // plus de photos que le serveur n'en accepte d'un coup : envoi par lots
        ])
        ->setHelp('Tu peux en choisir autant que tu veux : au-delà de ce que le serveur accepte en une fois, elles partent automatiquement en plusieurs envois.')
        ->onlyOnForms(),

      // On ne montre plus ce numéro brut dans le formulaire : le glisser-déposer
      // (et les flèches ▲▼) de la liste s'occupent déjà de l'ordre, l'exposer ici
      // en plus n'apporterait qu'une occasion de se tromper.
      IntegerField::new('position', 'Ordre')->onlyOnIndex()->setSortable(false),

      BooleanField::new('publie', 'Publié sur le site')
        ->renderAsSwitch(true)
        ->setSortable(false)
        ->setHelp('Décoche pour préparer un article sans qu\'il soit visible par les visiteurs.'),
    ];
  }

  public function configureAssets(Assets $assets): Assets
  {
    return $assets
      ->addHtmlContentToHead('<script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>')
      // Versionnés (?v=…, voir config/packages/assets.yaml) : après une mise en ligne, le
      // navigateur de Caroline recharge ces fichiers s'ils ont changé au lieu de garder
      // l'ancienne version en cache
      ->addCssFile(Asset::new('asset/css/admin_media.css')->package('static_assets'))
      ->addCssFile('asset/css/admin_draft.css')
      ->addJsFile('asset/js/admin_upload_loader.js')
      ->addJsFile(Asset::new('asset/js/admin_drag_drop.js')->package('static_assets'))
      ->addJsFile('asset/js/admin_media_reorder.js')
      ->addJsFile('asset/js/admin_image_resize.js')
      ->addJsFile(Asset::new('asset/js/admin_photo_batches.js')->package('static_assets'))
      // Brouillon automatique (gardé dans le navigateur), puis l'alerte qui s'en sert
      ->addJsFile('asset/js/admin_draft.js')
      ->addJsFile('asset/js/admin_unsaved_warning.js');
  }

  public function configureActions(Actions $actions): Actions
  {
    return $actions;
  }

  private function handleImageUploads(Article $article): void
  {
    $request = $this->requestStack->getCurrentRequest();
    $files = $request->files->all();

    $uploadedFiles = $files['Article']['multipleFiles'] ?? [];

    if (!is_array($uploadedFiles)) {
      $uploadedFiles = [$uploadedFiles];
    }

    $position = $this->mediaUploadHandler->nextPositionForArticle($article);

    foreach ($uploadedFiles as $file) {
      if (!$file) {
        continue;
      }

      $newFilename = $this->mediaUploadHandler->upload($file);
      $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

      $this->addPhoto($article, $newFilename, $originalFilename, $position++);
    }

    // Photos déjà déposées par lots (plus que le serveur n'en accepte en un envoi, voir
    // PhotoBatchUpload) : il ne reste qu'à les rattacher à l'article, à la suite, dans l'ordre.
    $batchUploaded = $this->mediaUploadHandler->batchUploadedPhotos($request->request->all()[PhotoBatchUpload::FIELD] ?? null);
    foreach ($batchUploaded as $photo) {
      $this->addPhoto($article, $photo['fichier'], $photo['legende'], $position++);
    }
  }

  private function addPhoto(Article $article, string $imageName, string $legende, int $position): void
  {
    $media = new Media();
    $media->setImageName($imageName);
    $media->setLegende($legende);
    $media->setArticle($article);
    $media->setPosition($position);
    $media->setCreeLe(new \DateTimeImmutable());

    $this->em->persist($media);
  }

  public function persistEntity(EntityManagerInterface $em, $entityInstance): void
  {
    if (!$entityInstance instanceof Article) {
      throw new \Exception('Entity is not an instance of Article');
    }

    $entityInstance->setCreeLe(new \DateTimeImmutable);

    // Un nouvel article se place EN TÊTE de sa catégorie (avant : tout en bas, et Caroline
    // devait remonter chaque nouvel article à la main). Il prend une position plus petite
    // que celle de tous les articles existants, sans qu'aucun autre article ne soit modifié :
    // l'ordre déjà choisi reste intact, et les flèches ▲▼ / le glisser-déposer (reorder()
    // ci-dessus) redistribuent ces positions comme les autres, même négatives.
    // Jamais 0 : pour app:init-article-positions, 0 veut dire « pas encore rangé ».
    if ($entityInstance->getPosition() === 0) {
      $plusPetitePosition = $em->getRepository(Article::class)
        ->createQueryBuilder('a')
        ->select('MIN(a.position)')
        ->getQuery()
        ->getSingleScalarResult();

      $entityInstance->setPosition(min((int) $plusPetitePosition - 1, -1));
    }

    $this->handleImageUploads($entityInstance);
    parent::persistEntity($em, $entityInstance);
  }

  public function updateEntity(EntityManagerInterface $em, $entityInstance): void
  {
    if (!$entityInstance instanceof Article) return;

    $entityInstance->setModifieLe(new \DateTimeImmutable);

    $this->handleImageUploads($entityInstance);
    parent::updateEntity($em, $entityInstance);
  }
}
