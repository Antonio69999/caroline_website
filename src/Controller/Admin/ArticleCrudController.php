<?php

namespace App\Controller\Admin;

use App\Entity\Article;
use App\Form\MediaType;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
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
    private readonly RequestStack $requestStack
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
      ->showEntityActionsInlined();
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

      CollectionField::new('media', 'Images déjà associées')->onlyOnForms() // On le garde que dans le formulaire pour pas surcharger la liste
        ->setFormTypeOptions([
          'entry_type' => MediaType::class,
          'allow_add' => true,
          'allow_delete' => true,
          'by_reference' => false,
        ]),

      IntegerField::new('position', 'Ordre')
    ];
  }

  public function configureAssets(Assets $assets): Assets
  {
    return $assets
      ->addHtmlContentToHead('<script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>')
      ->addJsFile('asset/js/admin_upload_loader.js')
      ->addJsFile('asset/js/admin_drag_drop.js');
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

    foreach ($uploadedFiles as $file) {
      if ($file) {
        $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/attachments';

        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = strtolower(trim(preg_replace('/[^A-Za-z0-9-_]+/', '-', $originalFilename), '-'));
        $safeFilename = $safeFilename !== '' ? $safeFilename : 'image';

        $extension = $file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin';
        $newFilename = $safeFilename . '.' . $extension;

        $counter = 1;
        while (file_exists($uploadDir . '/' . $newFilename)) {
          $newFilename = $safeFilename . '-' . $counter . '.' . $extension;
          $counter++;
        }

        $file->move($uploadDir, $newFilename);

        $media = new \App\Entity\Media();
        $media->setImageName($newFilename);
        $media->setLegende($originalFilename);
        $media->setArticle($article);
        $media->setCreeLe(new \DateTimeImmutable());

        $this->em->persist($media);
      }
    }
  }

  public function persistEntity(EntityManagerInterface $em, $entityInstance): void
  {
    if (!$entityInstance instanceof Article) {
      throw new \Exception('Entity is not an instance of Article');
    }

    $entityInstance->setCreeLe(new \DateTimeImmutable);

    // 🆕 Définir automatiquement la position pour un nouvel article
    if ($entityInstance->getPosition() === null || $entityInstance->getPosition() === 0) {
      $maxPosition = $em->getRepository(Article::class)
        ->createQueryBuilder('a')
        ->select('MAX(a.position)')
        ->getQuery()
        ->getSingleScalarResult();

      $entityInstance->setPosition(($maxPosition ?? -1) + 1);
    }

    // Persister les médias associés
    foreach ($entityInstance->getMedia() as $media) {
      $media->setArticle($entityInstance);
      $media->setCreeLe(new \DateTimeImmutable);
      $em->persist($media);
    }

    $this->handleImageUploads($entityInstance);
    parent::persistEntity($em, $entityInstance);
  }

  public function updateEntity(EntityManagerInterface $em, $entityInstance): void
  {
    if (!$entityInstance instanceof Article) return;

    $entityInstance->setModifieLe(new \DateTimeImmutable);

    // Persister les nouveaux médias ajoutés
    foreach ($entityInstance->getMedia() as $media) {
      if (!$media->getId()) { // Nouveau média
        $media->setArticle($entityInstance);
        $media->setCreeLe(new \DateTimeImmutable);
        $em->persist($media);
      } else { // Média existant
        $media->setModifieLe(new \DateTimeImmutable);
      }
    }

    $this->handleImageUploads($entityInstance);
    parent::updateEntity($em, $entityInstance);
  }
}
