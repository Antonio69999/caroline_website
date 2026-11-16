<?php

namespace App\Controller;

use App\Entity\Categorie;
use App\Repository\ArticleRepository;
use App\Repository\CategorieRepository;
use App\Seo\SeoBuilder;
use App\Seo\SiteUrls;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
  public function __construct(
    private readonly SeoBuilder $seo,
    private readonly SiteUrls $urls,
  ) {
  }

  #[Route('/', name: 'app_home')]
  public function index(CategorieRepository $categorieRepository): Response
  {
    return $this->render('home/index.html.twig', [
      'categories' => $categorieRepository->findAllOrdered(),
      'seo' => $this->seo->home(),
    ]);
  }

  // Ancienne page du Préambule (une vidéo). Le Préambule est maintenant une catégorie
  // « vitrine » comme les autres : on garde cette adresse pour les liens déjà partagés.
  #[Route('/preamble', name: 'preambule')]
  public function preambule(CategorieRepository $cr): Response
  {
    $vitrine = $cr->findOneBy(['type' => Categorie::TYPE_VITRINE], ['position' => 'ASC']);

    // Définitif (301) : la page a bel et bien changé d'adresse, Google doit retenir la nouvelle
    return $vitrine
      ? $this->redirect($this->urls->categoryPath($vitrine), Response::HTTP_MOVED_PERMANENTLY)
      : $this->redirectToRoute('app_home');
  }

  #[Route('/legal', name: 'legal')]
  public function legal(CategorieRepository $cr): Response
  {
    return $this->render('components/legal.html.twig', [
      'categories' => $cr->findAllOrdered(),
    ]);
  }

  // Cette ancienne page n'affichait rien d'autre que le menu : mieux vaut renvoyer à l'accueil
  // que laisser Google indexer une page vide.
  #[Route('/category', name: 'app_category')]
  public function category(): Response
  {
    return $this->redirectToRoute('app_home', [], Response::HTTP_MOVED_PERMANENTLY);
  }

  // Le numéro désigne la page ; le titre dans l'adresse n'est là que pour Google et les humains.
  // /category/3 et /category/3/un-vieux-titre renvoient donc (301) vers /category/3/titre-actuel.
  #[Route(
    '/category/{id}/{slug}',
    name: 'app_category_show',
    requirements: ['id' => '\d+', 'slug' => '[a-z0-9-]+'],
    defaults: ['slug' => null],
  )]
  public function show(int $id, ?string $slug, Request $request, CategorieRepository $cr, ArticleRepository $ar): Response
  {
    $category = $cr->find($id);

    if (!$category) {
      throw $this->createNotFoundException('The category does not exist');
    }

    if ($redirect = $this->redirectToCanonical($request, 'app_category_show', $this->urls->categoryParams($category), $slug)) {
      return $redirect;
    }

    $categories = $cr->findAllOrdered();

    // Page de texte (ex. le CV) : le texte prend la place de la grille d'articles
    if ($category->isTexte()) {
      return $this->render('category/text.html.twig', [
        'category' => $category,
        'categories' => $categories,
        'seo' => $this->seo->category($category),
      ]);
    }

    // Vitrine (ex. le Préambule) : une page libre faite de blocs empilés
    if ($category->isVitrine()) {
      return $this->render('category/vitrine.html.twig', [
        'category' => $category,
        'categories' => $categories,
        'seo' => $this->seo->category($category),
      ]);
    }

    $articles = $ar->findWithPosition($id);

    return $this->render('category/show.html.twig', [
      'category' => $category,
      'articles' => $articles,
      'categories' => $categories,
      'seo' => $this->seo->category($category, $articles),
    ]);
  }

  #[Route(
    '/article/{id}/{slug}',
    name: 'app_article_show',
    requirements: ['id' => '\d+', 'slug' => '[a-z0-9-]+'],
    defaults: ['slug' => null],
  )]
  public function showArticle(int $id, ?string $slug, Request $request, ArticleRepository $ar, CategorieRepository $cr): Response
  {
    $article = $ar->findOneBy(['id' => $id, 'publie' => true]);

    // Un article supprimé ou repassé en brouillon doit répondre « introuvable » (404) :
    // une page vide qui répond « OK » (200) serait gardée par Google comme une vraie page.
    if (!$article) {
      throw $this->createNotFoundException('The article does not exist');
    }

    if ($redirect = $this->redirectToCanonical($request, 'app_article_show', $this->urls->articleParams($article), $slug)) {
      return $redirect;
    }

    return $this->render('articles/index.html.twig', [
      'article' => $article,
      'categories' => $cr->findAllOrdered(),
      'seo' => $this->seo->article($article),
    ]);
  }

  /**
   * Renvoie (301, définitif) vers l'adresse officielle quand le titre de l'adresse demandée
   * n'est pas le bon ; ne fait rien sinon. Les éventuels paramètres (?utm_…) sont conservés.
   *
   * @param array{id: int, slug?: string} $canonical
   */
  private function redirectToCanonical(Request $request, string $route, array $canonical, ?string $slug): ?RedirectResponse
  {
    if (($slug ?? '') === ($canonical['slug'] ?? '')) {
      return null;
    }

    return $this->redirectToRoute($route, $canonical + $request->query->all(), Response::HTTP_MOVED_PERMANENTLY);
  }
}
