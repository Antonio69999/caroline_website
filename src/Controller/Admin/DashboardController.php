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
use Symfony\Component\Routing\Attribute\Route;

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
    yield MenuItem::linkToDashboard('Tableau de bord', 'fa fa-home');

    yield MenuItem::section('Gestion du Contenu');
    yield MenuItem::linkToCrud('Articles', 'fas fa-newspaper', Article::class)
      ->setBadge('Nouveau', 'success'); // Optionnel : tu peux mettre des badges dynamiques plus tard
    yield MenuItem::linkToCrud('Catégories', 'fas fa-folder', Categorie::class);

    yield MenuItem::section('Ressources');
    yield MenuItem::linkToCrud('Médiathèque', 'fas fa-images', Media::class);

    // Optionnel mais très pratique : un bouton pour retourner sur le site public
    yield MenuItem::section('Site Web');
    // yield MenuItem::linkToRoute('Retour au site', 'fas fa-external-link-alt', 'nom_de_ta_route_accueil');
  }
}
