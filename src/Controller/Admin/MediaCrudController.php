<?php

namespace App\Controller\Admin;

use App\Entity\Media;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;

class MediaCrudController extends AbstractCrudController
{

  public const MEDIA_BASE_PATH = 'uploads/attachments';
  public const MEDIA_UPLOAD_DIR = 'public/asset/images';

  public static function getEntityFqcn(): string
  {
    return Media::class;
  }

  public function configureCrud(Crud $crud): Crud
  {
    return $crud
      ->setPageTitle('index', 'Médiathèque')
      ->setSearchFields(['legende', 'imageName']); // Permet de chercher une image par sa légende
  }

  public function configureFilters(Filters $filters): Filters
  {
    return $filters->add('creeLe');
  }

  public function configureFields(string $pageName): iterable
  {
    return [
      IdField::new('id')->onlyOnIndex(),
      // L'ImageField génère automatiquement une miniature dans la liste (index)
      ImageField::new('imageName', 'Aperçu')
        ->setBasePath(self::MEDIA_BASE_PATH)
        ->setUploadDir(self::MEDIA_UPLOAD_DIR)
        ->setUploadedFileNamePattern('[randomhash].[extension]')
        ->setRequired($pageName === Crud::PAGE_NEW), // Requis à la création, mais pas à l'édition

      TextField::new('legende', 'Légende / Texte alternatif'),
      DateTimeField::new('CreeLe', 'Ajouté le')->setFormat('dd/MM/yyyy')->hideOnForm(),
    ];
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
    if ($entityInstance instanceof Media) {
      $entityInstance->setCreeLe(new \DateTimeImmutable);
      parent::persistEntity($em, $entityInstance); //appel de la méthode parent AbstractController
    } else {
      throw new \Exception('Entity is not an instance of Categorie');
    }
  }
}
