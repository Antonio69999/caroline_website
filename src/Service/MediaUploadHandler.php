<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\Media;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Seul point d'entrée pour déposer une photo dans public/uploads/attachments
 * et calculer sa position dans la galerie d'un article. Utilisé à la fois par
 * ArticleCrudController et MediaCrudController pour qu'il n'y ait plus qu'un
 * unique chemin d'upload dans toute l'application.
 */
class MediaUploadHandler
{
  private readonly string $uploadDir;

  public function __construct(
    #[Autowire(param: 'kernel.project_dir')] string $projectDir,
    private readonly EntityManagerInterface $em,
  ) {
    $this->uploadDir = $projectDir . '/public/uploads/attachments';
  }

  public function upload(UploadedFile $file): string
  {
    if (!is_dir($this->uploadDir)) {
      mkdir($this->uploadDir, 0775, true);
    }

    $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
    $safeFilename = strtolower(trim(preg_replace('/[^A-Za-z0-9-_]+/', '-', $originalFilename), '-'));
    $safeFilename = $safeFilename !== '' ? $safeFilename : 'image';

    $extension = $file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin';
    $newFilename = $safeFilename . '.' . $extension;

    $counter = 1;
    while (file_exists($this->uploadDir . '/' . $newFilename)) {
      $newFilename = $safeFilename . '-' . $counter . '.' . $extension;
      $counter++;
    }

    $file->move($this->uploadDir, $newFilename);

    return $newFilename;
  }

  /**
   * Position à donner à la prochaine photo ajoutée à cet article (= fin de la galerie).
   */
  public function nextPositionForArticle(Article $article): int
  {
    if ($article->getId() === null) {
      return $article->getMedia()->count();
    }

    $maxPosition = $this->em->createQueryBuilder()
      ->select('MAX(m.position)')
      ->from(Media::class, 'm')
      ->where('m.article = :article')
      ->setParameter('article', $article)
      ->getQuery()
      ->getSingleScalarResult();

    return $maxPosition === null ? 0 : ((int) $maxPosition + 1);
  }
}
