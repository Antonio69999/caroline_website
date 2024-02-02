<?php

namespace App\Controller\Admin;

use App\Entity\Article;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class ArticleCrudController extends AbstractCrudController
{

    public const ARTICLE_BASE_PATH = '/uploads/images/article/';
    public const ARTICLE_UPLOAD_DIR = 'public/uploads/images/article';

    public static function getEntityFqcn(): string
    {
        return Article::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('titre'),
            TextEditorField::new('description'),
            DateTimeField::new('ModifieeLe')->hideOnForm(),
            DateTimeField::new('CreeLe')->hideOnForm(),
            AssociationField::new('categorie'),
            AssociationField::new('media')
                ->setFormTypeOptions([
                    'by_reference' => false,
                    'multiple' => true,
                ]),
        ];
    }

    public function updateEntity(EntityManagerInterface $em, $entityInstance): void
    {
        // dd($entityInstance);
        if (!$entityInstance instanceof Article) return;

        $entityInstance->setModifieLe(new \DateTimeImmutable);
        // dd($entityInstance);
        parent::updateEntity($em, $entityInstance); //appel de la méthode parent AbstractController
    }

    public function persistEntity(EntityManagerInterface $em, $entityInstance): void
    {
        if ($entityInstance instanceof Article) {
            $entityInstance->setCreeLe(new \DateTimeImmutable);
            parent::persistEntity($em, $entityInstance); //appel de la méthode parent AbstractController
        } else {
            throw new \Exception('Entity is not an instance of Categorie');
        }
    }
}
