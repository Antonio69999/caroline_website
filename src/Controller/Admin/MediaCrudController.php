<?php

namespace App\Controller\Admin;

use App\Entity\Article;
use App\Entity\Media;
use App\Service\MediaUploadHandler;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MediaCrudController extends AbstractCrudController
{
  public function __construct(
    private readonly EntityManagerInterface $em,
    private readonly RequestStack $requestStack,
    private readonly MediaUploadHandler $mediaUploadHandler,
  ) {}

  public static function getEntityFqcn(): string
  {
    return Media::class;
  }

  #[Route('/admin/media/reorder', name: 'admin_media_reorder', methods: ['POST'])]
  public function reorder(Request $request): JsonResponse
  {
    $data = json_decode($request->getContent(), true);
    $orderedIds = $data['orderedIds'] ?? [];

    if (empty($orderedIds)) {
      return new JsonResponse(['status' => 'error', 'message' => 'Aucune donnée reçue'], 400);
    }

    $repository = $this->em->getRepository(Media::class);

    $medias = [];
    $currentPositions = [];

    // 1. On récupère les photos qu'on vient de bouger et on note leurs positions actuelles
    foreach ($orderedIds as $id) {
      $media = $repository->find($id);
      if ($media) {
        $medias[] = $media;
        $currentPositions[] = $media->getPosition();
      }
    }

    // 2. On trie les positions du plus petit au plus grand
    sort($currentPositions);

    // 3. On redistribue ces positions dans le nouvel ordre choisi par la souris
    //    (les IDs reçus n'appartiennent qu'à un seul article à la fois, donc ce
    //    réordonnancement reste naturellement cantonné à cet article)
    foreach ($medias as $index => $media) {
      $media->setPosition($currentPositions[$index]);
    }

    $this->em->flush();

    return new JsonResponse(['status' => 'success']);
  }

  #[Route('/admin/media/{id}/quick-update', name: 'admin_media_quick_update', methods: ['POST'])]
  public function quickUpdate(int $id, Request $request): Response
  {
    $media = $this->em->getRepository(Media::class)->find($id);
    if (!$media) {
      throw $this->createNotFoundException();
    }

    if ($request->request->has('legende')) {
      $media->setLegende($request->request->get('legende'));
    }

    $newArticleId = $request->request->get('article');
    if ($newArticleId) {
      $currentArticleId = $media->getArticle()?->getId();
      if ((int) $newArticleId !== $currentArticleId) {
        $newArticle = $this->em->getRepository(Article::class)->find($newArticleId);
        if ($newArticle) {
          $media->setArticle($newArticle);
          $media->setPosition($this->mediaUploadHandler->nextPositionForArticle($newArticle));
        }
      }
    }

    $media->setModifieLe(new \DateTimeImmutable());
    $this->em->flush();

    return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('admin'));
  }

  public function configureCrud(Crud $crud): Crud
  {
    return $crud
      ->setPageTitle('index', '🖼️ Médiathèque')
      ->setPageTitle('new', 'Ajouter des photos')
      ->setPageTitle('detail', 'Détail du média')
      ->setSearchFields(['legende', 'imageName'])
      ->setDefaultSort(['id' => 'DESC']) // Les dernières images ajoutées en haut
      ->showEntityActionsInlined()
      ->setPaginatorPageSize(30) // On affiche plus d'images par page
      ->overrideTemplate('crud/index', 'admin/media/index.html.twig');
  }

  public function configureFilters(Filters $filters): Filters
  {
    return $filters
      ->add('creeLe')
      ->add(EntityFilter::new('article'));
  }

  public function configureAssets(Assets $assets): Assets
  {
    return $assets
      ->addCssFile('asset/css/admin_media.css')
      ->addJsFile('asset/js/admin_image_resize.js')
      ->addJsFile('asset/js/admin_unsaved_warning.js');
  }

  public function configureFields(string $pageName): iterable
  {
    return [
      IdField::new('id')->hideOnIndex()->hideOnForm(),

      // L'image au centre de l'attention
      ImageField::new('imageName', 'Aperçu')
        ->setBasePath('/uploads/attachments')
        ->hideOnForm(),

      Field::new('newImages', 'Photo(s) à ajouter')
        ->setFormType(FileType::class)
        ->setFormTypeOptions([
          'multiple' => true,
          'mapped' => false,
          'required' => true,
          'attr' => ['accept' => 'image/*'],
        ])
        ->onlyWhenCreating(),

      AssociationField::new('article', 'Article')
        ->autocomplete(),

      TextField::new('article.categorie.titre', 'Catégorie')
        ->onlyOnIndex(),

      TextField::new('legende', 'Légende / Texte alternatif')
        ->setRequired(true)
        ->setHelp('Décris la photo en une phrase (ex. "Caroline la plus jolie") : c\'est ce que liront les personnes qui ne peuvent pas voir l\'image.'),

      DateTimeField::new('CreeLe', 'Ajouté le')->setFormat('dd/MM/yyyy')->hideOnForm(),
    ];
  }

  public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
  {
    if (Crud::PAGE_INDEX === $responseParameters->get('pageName')) {
      $responseParameters->set(
        'all_articles',
        $this->em->getRepository(Article::class)->findBy([], ['position' => 'ASC'])
      );
    }

    return $responseParameters;
  }

  public function updateEntity(EntityManagerInterface $em, $entityInstance): void
  {
    if (!$entityInstance instanceof Media) {
      throw new \Exception('Entity is not an instance of Media');
    }

    $entityInstance->setModifieLe(new \DateTimeImmutable);
    parent::updateEntity($em, $entityInstance);
  }

  public function persistEntity(EntityManagerInterface $em, $entityInstance): void
  {
    if (!$entityInstance instanceof Media) {
      throw new \Exception('Entity is not an instance of Media');
    }

    $entityInstance->setCreeLe(new \DateTimeImmutable);

    $request = $this->requestStack->getCurrentRequest();
    $uploadedFiles = $request->files->all()['Media']['newImages'] ?? [];
    if (!is_array($uploadedFiles)) {
      $uploadedFiles = [$uploadedFiles];
    }
    $uploadedFiles = array_values(array_filter($uploadedFiles));

    $article = $entityInstance->getArticle();
    $position = $article ? $this->mediaUploadHandler->nextPositionForArticle($article) : 0;

    if (!empty($uploadedFiles)) {
      $firstFile = array_shift($uploadedFiles);
      $entityInstance->setImageName($this->mediaUploadHandler->upload($firstFile));
      if (!$entityInstance->getLegende()) {
        $entityInstance->setLegende(pathinfo($firstFile->getClientOriginalName(), PATHINFO_FILENAME));
      }
      $entityInstance->setPosition($position++);

      // Les fichiers suivants deviennent chacun leur propre Media, à côté de celui en cours de création
      foreach ($uploadedFiles as $file) {
        $sibling = new Media();
        $sibling->setImageName($this->mediaUploadHandler->upload($file));
        $sibling->setLegende(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $sibling->setArticle($article);
        $sibling->setPosition($position++);
        $sibling->setCreeLe(new \DateTimeImmutable());
        $em->persist($sibling);
      }
    }

    parent::persistEntity($em, $entityInstance);
  }
}
