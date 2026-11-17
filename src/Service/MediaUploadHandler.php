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
    private readonly Thumbnailer $thumbnailer,
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

    // La vignette (petite copie pour les grilles et galeries) est un confort : si elle ne peut
    // pas être fabriquée, la photo est quand même enregistrée et le site utilise l'originale.
    try {
      $this->thumbnailer->generate($newFilename);
    } catch (\Throwable) {
    }

    return $newFilename;
  }

  /**
   * Une photo que l'envoi par lots accepte : une image, mais pas un SVG (qui peut contenir du
   * script et serait servi tel quel depuis le site).
   */
  public function isAcceptedImage(UploadedFile $file): bool
  {
    $type = (string) $file->getMimeType();

    return str_starts_with($type, 'image/') && !str_contains($type, 'svg');
  }

  /**
   * Photos déjà déposées par lots (PhotoBatchUploadController) et renvoyées par le formulaire
   * au moment d'enregistrer, sous la forme [['fichier' => …, 'legende' => …], …].
   *
   * Ces noms viennent du navigateur : on ne garde que de simples noms de fichiers (jamais un
   * chemin), présents dans le dossier des photos, et qu'aucune photo du site n'utilise déjà
   * (on ne peut pas « emprunter » la photo d'un autre article).
   *
   * @return list<array{fichier: string, legende: string}>
   */
  public function batchUploadedPhotos(mixed $raw): array
  {
    if (!is_array($raw)) {
      return [];
    }

    $photos = [];
    foreach ($raw as $item) {
      $fichier = is_array($item) && is_string($item['fichier'] ?? null) ? $item['fichier'] : '';
      if (
        1 !== preg_match('/^[a-z0-9_][a-z0-9_-]*\.[a-z0-9]{2,5}$/i', $fichier)
        || isset($photos[$fichier])
        || !is_file($this->uploadDir . '/' . $fichier)
        || $this->em->getRepository(Media::class)->count(['imageName' => $fichier]) > 0
      ) {
        continue;
      }

      $legende = is_string($item['legende'] ?? null) ? trim($item['legende']) : '';
      $photos[$fichier] = [
        'fichier' => $fichier,
        'legende' => mb_substr('' !== $legende ? $legende : pathinfo($fichier, PATHINFO_FILENAME), 0, 255),
      ];
    }

    return array_values($photos);
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
