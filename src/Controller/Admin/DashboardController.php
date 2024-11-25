<?php

namespace App\Controller\Admin;

use App\Entity\Article;
use App\Entity\Categorie;
use App\Entity\Media;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractDashboardController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/mon_petit_site', name: 'admin')]
    public function index(): Response
    {
        $articleRepository = $this->entityManager->getRepository(Article::class);

        // Get the last three uploaded images
        $lastThreeArticles = $articleRepository->findBy([], ['id' => 'DESC'], 3);

        // Generate a random welcome message
        $welcomeMessages = ["Coucou Caro!", "YOOOOOOO!", "Salutation!", "Bienvenue sur ton potit site à toi", "LOVE❤️", "Pleins de bisous", "Plein de love❤️"];
        $randomWelcomeMessage = $welcomeMessages[array_rand($welcomeMessages)];

        return $this->render('admin/admin.html.twig', [
            'lastThreeArticles' => $lastThreeArticles,
            'welcomeMessage' => $randomWelcomeMessage,
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Mon Petit Site');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');
        yield MenuItem::linkToCrud('Article', 'fa-regular fa-newspaper', Article::class);
        yield MenuItem::linkToCrud('Categorie', 'fa-solid fa-list', Categorie::class);
        yield MenuItem::linkToCrud('Media', 'fa-solid fa-photo-film', Media::class);
        yield MenuItem::linkToRoute('Retourner sur le site', 'fa fa-arrow-left', 'app_home');
    }
}
