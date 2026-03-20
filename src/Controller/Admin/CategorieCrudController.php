<?php

namespace App\Controller\Admin;

use App\Entity\Categorie;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;

class CategorieCrudController extends AbstractCrudController
{
  public static function getEntityFqcn(): string
  {
    return Categorie::class;
  }

  public function configureCrud(Crud $crud): Crud
  {
    return $crud
      ->setPageTitle('index', '📁 Liste des Catégories')
      ->setPageTitle('new', 'Créer une nouvelle catégorie')
      ->setPageTitle('edit', 'Modifier la catégorie')
      // On permet de chercher une catégorie par son titre ou sa description
      ->setSearchFields(['titre', 'description'])
      // On trie par les plus récentes en premier
      ->setDefaultSort(['id' => 'DESC'])
      // On met les boutons d'action sur la même ligne pour que ce soit plus joli
      ->showEntityActionsInlined();
  }

  public function configureFields(string $pageName): iterable
  {
    return [
      // On cache l'ID partout, ça n'intéresse pas l'utilisateur
      IdField::new('id')->hideOnForm()->hideOnIndex(),

      TextField::new('titre', 'Nom de la catégorie'),

      // On cache la description longue sur la liste pour ne pas casser le tableau
      TextEditorField::new('description', 'Description')->hideOnIndex(),

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
      parent::persistEntity($em, $entityInstance); //appel de la méthode parent AbstractController
    } else {
      throw new \Exception('Entity is not an instance of Categorie');
    }
  }
}
