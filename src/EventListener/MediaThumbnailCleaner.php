<?php

namespace App\EventListener;

use App\Entity\Media;
use App\Service\Thumbnailer;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * Quand une photo est supprimée dans l'admin (Vich efface le fichier d'origine),
 * sa vignette part avec elle : elle n'a plus de raison d'être.
 */
#[AsEntityListener(event: Events::postRemove, entity: Media::class)]
final class MediaThumbnailCleaner
{
  public function __construct(private readonly Thumbnailer $thumbnailer)
  {
  }

  public function __invoke(Media $media): void
  {
    if ($media->getImageName()) {
      $this->thumbnailer->remove($media->getImageName());
    }
  }
}
