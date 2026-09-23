<?php

namespace App\Controller;

use App\Repository\ArticleRepository;
use App\Repository\CategorieRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
  #[Route('/', name: 'app_home')]
  public function index(CategorieRepository $categorieRepository, ArticleRepository $articleRepository): Response
  {
    // Récupérer la première catégorie (dans l'ordre choisi dans l'admin)
    $categorie = $categorieRepository->findOneBy([], ['position' => 'ASC']);
    $categories = $categorieRepository->findAllOrdered();

    // Récupérer les articles liés à la catégorie (publiés uniquement)
    $articles = $articleRepository->findBy(['categorie' => $categorie, 'publie' => true]);


    return $this->render('home/index.html.twig', [
      'categorie' => $categorie,
      'articles' => $articles,
      'categories' => $categories,
    ]);
  }

  #[Route('/preamble', name: 'preambule')]
  public function preambule(CategorieRepository $cr): Response
  {
    return $this->render('components/video.html.twig', [
      'categories' => $cr->findAllOrdered(),
    ]);
  }

  #[Route('/legal', name: 'legal')]
  public function legal(CategorieRepository $cr): Response
  {
    return $this->render('components/legal.html.twig', [
      'categories' => $cr->findAllOrdered(),
    ]);
  }

  #[Route('/category', name: 'app_category')]
  public function category(CategorieRepository $cr): Response
  {
    $categories = $cr->findAllOrdered();

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

    $articles = $ar->findWithPosition($id);
    $categories = $cr->findAllOrdered();

    return $this->render('category/show.html.twig', [
      'category' => $category,
      'articles' => $articles,
      'categories' => $categories,
    ]);
  }

  #[Route('/article/{id}', name: 'app_article_show')]
  public function showArticle(int $id, ArticleRepository $ar, CategorieRepository $cr): Response
  {
    $articles = $ar->findBy(['id' => $id, 'publie' => true]);
    $categories = $cr->findAllOrdered();

    return $this->render('articles/index.html.twig', [
      'articles' => $articles,
      'categories' => $categories,

    ]);
  }
}
