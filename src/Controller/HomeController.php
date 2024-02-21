<?php

namespace App\Controller;

use App\Repository\ArticleRepository;
use App\Repository\CategorieRepository;
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

    #[Route('/category', name: 'app_category')]
    public function category(CategorieRepository $cr): Response
    {
        $categories = $cr->findAll();

        return $this->render('category/index.html.twig', [
            'categories' => $categories,
        ]);
    }

    #[Route('/category/{id}', name: 'app_category_show')]
    public function show(int $id, CategorieRepository $cr, ArticleRepository $ar): Response
    {
        $category = $cr->find($id);

        if (!$category) {
            throw $this->createNotFoundException('The category does not exist');
        }

        $articles = $ar->findBy(['categorie' => $id], ['position' => 'ASC']);
        $categories = $cr->findAll();

        return $this->render('category/show.html.twig', [
            'category' => $category,
            'articles' => $articles,
            'categories' => $categories,
        ]);
    }

    #[Route('/article/{id}', name: 'app_article_show')]
    public function showArticle(int $id, ArticleRepository $ar, CategorieRepository $cr): Response
    {
        $articles = $ar->findBy(['id' => $id]);
        $categories = $cr->findAll();

        return $this->render('articles/index.html.twig', [
            'articles' => $articles,
            'categories' => $categories,

        ]);
    }
}
