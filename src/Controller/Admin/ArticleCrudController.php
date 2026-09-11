<?php

namespace App\Controller\Admin;

use App\Entity\Article;
use App\Entity\Media;
use App\Service\MediaUploadHandler;
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
  ) {}

  public const ARTICLE_BASE_PATH = '/uploads/images/article/';
  public const ARTICLE_UPLOAD_DIR = 'public/uploads/images/article';

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
      ->setDefaultSort(['position' => 'ASC'])
      ->setPageTitle('index', '🎨 Liste des Articles')
      // Définir sur quels champs la barre de recherche globale fonctionne
      ->setSearchFields(['titre', 'description'])
      // Option sympa : afficher le nombre de résultats
      ->setPaginatorPageSize(20)
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
      IdField::new('id')->onlyOnIndex(), // Afficher l'ID seulement dans la liste

      TextField::new('titre', 'Titre de l\'article'),

      // On affiche la catégorie directement dans la liste
      AssociationField::new('categorie', 'Catégorie')
        ->autocomplete(), // Ajoute une barre de recherche dans le menu déroulant du formulaire (très utile si tu as 50 catégories)

      // Rendre le tableau plus propre : on cache la description longue sur la liste (index)
      TextEditorField::new('description')->hideOnIndex(),

      // Formatage propre des dates
      DateTimeField::new('creeLe', 'Créé le')->setFormat('dd/MM/yyyy HH:mm')->hideOnForm(),
      DateTimeField::new('ModifieLe', 'Modifié le')->setFormat('dd/MM/yyyy HH:mm')->onlyOnDetail(),

      Field::new('multipleFiles', 'Ajouter plusieurs images d\'un coup')
        ->setFormType(FileType::class)
        ->setFormTypeOptions([
          'multiple' => true, // Permet de sélectionner plusieurs fichiers
          'mapped' => false,  // Ne cherche pas à l'écrire dans la table Article
          'required' => false,
          'attr' => [
            'accept' => 'image/*', // Ouvre directement la fenêtre sur les images
          ]
        ])
        ->onlyOnForms(),

      // On ne montre plus ce numéro brut dans le formulaire : le glisser-déposer
      // (et les flèches ▲▼) de la liste s'occupent déjà de l'ordre, l'exposer ici
      // en plus n'apporterait qu'une occasion de se tromper.
      IntegerField::new('position', 'Ordre')->onlyOnIndex(),

      BooleanField::new('publie', 'Publié sur le site')
        ->renderAsSwitch(true)
        ->setHelp('Décoche pour préparer un article sans qu\'il soit visible par les visiteurs.'),
    ];
  }

  public function configureAssets(Assets $assets): Assets
  {
    return $assets
      ->addHtmlContentToHead('<script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>')
      ->addCssFile('asset/css/admin_media.css')
      ->addJsFile('asset/js/admin_upload_loader.js')
      ->addJsFile('asset/js/admin_drag_drop.js')
      ->addJsFile('asset/js/admin_media_reorder.js')
      ->addJsFile('asset/js/admin_image_resize.js')
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

      $media = new Media();
      $media->setImageName($newFilename);
      $media->setLegende($originalFilename);
      $media->setArticle($article);
      $media->setPosition($position++);
      $media->setCreeLe(new \DateTimeImmutable());

      $this->em->persist($media);
    }
  }

  public function persistEntity(EntityManagerInterface $em, $entityInstance): void
  {
    if (!$entityInstance instanceof Article) {
      throw new \Exception('Entity is not an instance of Article');
    }

    $entityInstance->setCreeLe(new \DateTimeImmutable);

    // 🆕 Définir automatiquement la position pour un nouvel article
    if ($entityInstance->getPosition() === 0) {
      $maxPosition = $em->getRepository(Article::class)
        ->createQueryBuilder('a')
        ->select('MAX(a.position)')
        ->getQuery()
        ->getSingleScalarResult();

      $entityInstance->setPosition(($maxPosition ?? -1) + 1);
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
