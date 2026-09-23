<?php

namespace App\Controller\Admin;

use App\Entity\Categorie;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
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
      ->addCssFile('asset/css/admin_media.css')
      ->addJsFile('asset/js/admin_categorie_drag_drop.js')
      ->addJsFile('asset/js/admin_unsaved_warning.js');
  }

  public function configureFields(string $pageName): iterable
  {
    return [
      // On cache l'ID partout, ça n'intéresse pas l'utilisateur
      IdField::new('id')->hideOnForm()->hideOnIndex(),

      TextField::new('titre', 'Nom de la catégorie'),

      // On cache la description longue sur la liste pour ne pas casser le tableau
      TextEditorField::new('description', 'Description')->hideOnIndex(),

      // Le glisser-déposer (et les flèches ▲▼) de la liste s'occupent de l'ordre
      IntegerField::new('position', 'Ordre')->onlyOnIndex(),

      // On formate les dates proprement pour que ce soit lisible !
      DateTimeField::new('CreeLe', 'Créée le')->setFormat('dd/MM/yyyy à HH:mm')->hideOnForm(),
      DateTimeField::new('ModifieeLe', 'Dernière modif.')->setFormat('dd/MM/yyyy à HH:mm')->hideOnForm()->onlyOnDetail(),
    ];
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
