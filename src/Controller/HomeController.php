<?php

namespace App\Controller;

use App\Entity\Categorie;
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

  // Ancienne page du Préambule (une vidéo). Le Préambule est maintenant une catégorie
  // « vitrine » comme les autres : on garde cette adresse pour les liens déjà partagés.
  #[Route('/preamble', name: 'preambule')]
  public function preambule(CategorieRepository $cr): Response
  {
    $vitrine = $cr->findOneBy(['type' => Categorie::TYPE_VITRINE], ['position' => 'ASC']);

    return $vitrine
      ? $this->redirectToRoute('app_category_show', ['id' => $vitrine->getId()])
      : $this->redirectToRoute('app_home');
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

    $categories = $cr->findAllOrdered();

    // Page de texte (ex. le CV) : le texte prend la place de la grille d'articles
    if ($category->isTexte()) {
      return $this->render('category/text.html.twig', [
        'category' => $category,
        'categories' => $categories,
      ]);
    }

    // Vitrine (ex. le Préambule) : une page libre faite de blocs empilés
    if ($category->isVitrine()) {
      return $this->render('category/vitrine.html.twig', [
        'category' => $category,
        'categories' => $categories,
      ]);
    }

    $articles = $ar->findWithPosition($id);

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
