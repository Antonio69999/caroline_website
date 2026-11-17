<?php

namespace App\Service;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Envoi des photos par lots, au-delà de ce que le serveur accepte en un seul envoi.
 *
 * PHP n'accepte qu'un nombre limité de fichiers par envoi (max_file_uploads : 20 par défaut)
 * et un poids total limité (post_max_size). Au-delà, il ne prévient pas : il ignore les photos
 * en trop ou jette tout le formulaire. D'où « je ne peux mettre que 20 photos par article ».
 *
 * Quand une sélection dépasse ces limites, admin_photo_batches.js envoie les photos par lots à
 * PhotoBatchUploadController, puis enregistre le formulaire avec seulement le nom des photos
 * déposées (champ caché FIELD) ; ArticleCrudController / MediaCrudController les rattachent
 * (MediaUploadHandler::batchUploadedPhotos). Une sélection qui tient en un envoi part comme avant.
 */
final class PhotoBatchUpload
{
  public const TOKEN_ID = 'photos_envoi';

  /** Nom des champs cachés ajoutés au formulaire : photos_envoyees[0][fichier], [legende]… */
  public const FIELD = 'photos_envoyees';

  public function __construct(
    private readonly UrlGeneratorInterface $urls,
    private readonly CsrfTokenManagerInterface $csrf,
  ) {}

  /**
   * Attributs à poser sur un champ « plusieurs photos » : admin_photo_batches.js s'en sert pour
   * savoir où envoyer, avec quel jeton, et à partir de quand découper en lots.
   *
   * @return array<string, string>
   */
  public function inputAttributes(): array
  {
    return [
      'data-photos-envoi-url' => $this->urls->generate('admin_photos_envoi'),
      'data-photos-envoi-token' => $this->csrf->getToken(self::TOKEN_ID)->getValue(),
      'data-max-files' => (string) UploadLimits::maxFiles(),
      'data-max-bytes' => (string) UploadLimits::perFile(),
      'data-max-total-bytes' => (string) UploadLimits::total(),
    ];
  }
}
