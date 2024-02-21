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
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use Symfony\Component\HttpFoundation\Response;

enum Direction
{
    case Top;
    case Up;
    case Down;
    case Bottom;
}

class ArticleCrudController extends AbstractCrudController
{

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public const ARTICLE_BASE_PATH = '/uploads/images/article/';
    public const ARTICLE_UPLOAD_DIR = 'public/uploads/images/article';

    public static function getEntityFqcn(): string
    {
        return Article::class;
    }

    public function moveUp(AdminContext $context): Response
    {
        return $this->move($context, Direction::Up);
    }

    private function move(AdminContext $context, Direction $direction): Response
    {
        $repository = $this->em->getRepository(Article::class);

        $entityInstance = $context->getEntity()->getInstance();
        $currentPosition = $entityInstance->getPosition();
    
        // Get the total number of articles to prevent moving beyond the last position
        $totalArticles = $repository->createQueryBuilder('a')
            ->select('count(a.id)')
            ->getQuery()
            ->getSingleScalarResult();
    
        $newPosition = match ($direction) {
            Direction::Up => max(0, $currentPosition - 1),
            Direction::Down => min($totalArticles - 1, $currentPosition + 1),
        };
    
        // Find the entity that currently occupies the new position
        $otherEntity = $repository->findOneBy(['position' => $newPosition]);
    
        if ($otherEntity) {
            // Move the other entity to the current position
            $otherEntity->setPosition($currentPosition);
        }
    
        // Move the current entity to the new position
        $entityInstance->setPosition($newPosition);
    
        $this->em->flush();
    
        $this->addFlash('success', 'The element has been successfully moved.');
    
        return $this->redirectToRoute('admin', [
            'crudAction' => 'index',
            'crudControllerFqcn' => ArticleCrudController::class,
            'entityId' => $entityInstance->getId(),
        ]);
    }

    public function moveDown(AdminContext $context): Response
    {
        return $this->move($context, Direction::Down);
    }

   

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setDefaultSort(['position' => 'ASC'])
            ->setPageTitle('index', 'List of Articles')
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        $moveUp = Action::new('moveUp', false, 'fa fa-sort-up')
            ->setHtmlAttributes(['title' => 'Move up'])
            ->linkToCrudAction('moveUp');

        $moveDown = Action::new('moveDown', false, 'fa fa-sort-down')
            ->setHtmlAttributes(['title' => 'Move down'])
            ->linkToCrudAction('moveDown');

        return $actions
            ->add(Crud::PAGE_INDEX, $moveUp)
            ->add(Crud::PAGE_INDEX, $moveDown);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('titre'),
            TextEditorField::new('description'),
            DateTimeField::new('ModifieLe')->hideOnForm(),
            DateTimeField::new('creeLe')->hideOnForm(),
            AssociationField::new('categorie'),
            CollectionField::new('media')
                ->setFormTypeOptions([
                    'entry_type' => MediaType::class,
                    'allow_add' => true,
                    'by_reference' => false,
                ]),
            IntegerField::new('position')
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
