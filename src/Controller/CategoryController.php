<?php

namespace App\Controller;

use App\Repository\ArticleRepository;
use App\Repository\CategorieRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class CategoryController extends AbstractController
{
    #[Route('/category', name: 'app_category')]
    public function index(CategorieRepository $cr): Response
    {
        $categories = $cr->findAll();

        return $this->render('category/index.html.twig', [
            'categories' => $categories,
        ]);
    }

    #[Route('/category/{id}', name: 'app_category_show')]
    public function show(int $id, CategorieRepository $cr, ArticleRepository $ar): Response
    {
        $category = $cr->findAll($id);
        $articles = $ar->findBy(['categorie' => $id]);


        return $this->render('category/show.html.twig', [
            'category' => $category,
            'articles' => $articles,
        ]);
    }
}
