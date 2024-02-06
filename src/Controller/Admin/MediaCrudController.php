<?php

namespace App\Controller\Admin;

use App\Entity\Media;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class MediaCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Media::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            DateTimeField::new('ModifieLe')->hideOnForm(),
            DateTimeField::new('CreeLe')->hideOnForm(),
        ];
    }

    public function updateEntity(EntityManagerInterface $em, $entityInstance): void
    {
        // dd($entityInstance);
        if (!$entityInstance instanceof Media) return;

        $entityInstance->setModifieLe(new \DateTimeImmutable);
        // dd($entityInstance);
        parent::updateEntity($em, $entityInstance); //appel de la méthode parent AbstractController
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
