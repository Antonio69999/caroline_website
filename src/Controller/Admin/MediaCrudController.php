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

  public const MEDIA_BASE_PATH = 'asset/images';
  public const MEDIA_UPLOAD_DIR = 'public/asset/images';

  public static function getEntityFqcn(): string
  {
    return Media::class;
  }

  public function configureCrud(Crud $crud): Crud
  {
    return $crud
      ->setPageTitle('index', '🖼️ Médiathèque')
      ->setPageTitle('detail', 'Détail du média')
      ->setSearchFields(['legende', 'imageName'])
      ->setDefaultSort(['id' => 'DESC']) // Les dernières images ajoutées en haut
      ->showEntityActionsInlined()
      ->setPaginatorPageSize(30); // On affiche plus d'images par page
  }

  public function configureFilters(Filters $filters): Filters
  {
    return $filters->add('creeLe');
  }

  public function configureFields(string $pageName): iterable
  {
    return [
      IdField::new('id')->hideOnIndex()->hideOnForm(),

      // L'image au centre de l'attention
      ImageField::new('imageName', 'Aperçu')
        ->setBasePath('/uploads/attachments')
        ->hideOnForm(),

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
      parent::persistEntity($em, $entityInstance);
    } else {
      throw new \Exception('Entity is not an instance of Media');
    }
  }
}
