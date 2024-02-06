<?php

namespace App\Controller;

use App\Repository\ArticleRepository;
use App\Repository\CategorieRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(CategorieRepository $categorieRepository, ArticleRepository $articleRepository): Response
{
    // Récupérer la première catégorie (vous pouvez ajuster la logique selon vos besoins)
    $categorie = $categorieRepository->findOneBy([], ['id' => 'ASC']);
    $categories = $categorieRepository->findAll();

    // Récupérer les articles liés à la catégorie
    $articles = $articleRepository->findBy(['categorie' => $categorie]);
    

    return $this->render('home/index.html.twig', [
        'categorie' => $categorie,
        'articles' => $articles,
        'categories' => $categories,
    ]);
}
}
