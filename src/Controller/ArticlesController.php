<?php

namespace App\Controller;

use App\Repository\ArticleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ArticlesController extends AbstractController
{
    #[Route('/articles', name: 'app_articles')]
    public function index(ArticleRepository $ar): Response
    {
        $articles = $ar->findBy([], null, 3, 0);

        return $this->render('articles/index.html.twig', [
            'articles' => $articles,
        ]);
    }

    #[Route('/load-more-articles/{offset}', name: 'load_more_articles')]
    public function loadMoreArticles(int $offset, ArticleRepository $ar): Response
    {
        $articles = $ar->findBy([], null, 3, $offset);

        $serializedArticles = [];
        foreach ($articles as $article) {
            $mediaNames = [];
            foreach ($article->getMedia() as $media) {
                $mediaNames[] = $media->getImageName();
            }

            $serializedArticles[] = [
                'titre' => $article->getTitre(),
                'description' => $article->getDescription(),
                'media' => $mediaNames,
            ];
        }

        return $this->json($serializedArticles);
    }
}
