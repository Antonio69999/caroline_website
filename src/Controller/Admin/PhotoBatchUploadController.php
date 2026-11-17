<?php

namespace App\Controller\Admin;

use App\Service\MediaUploadHandler;
use App\Service\PhotoBatchUpload;
use App\Service\UploadLimits;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Reçoit UN lot de photos (au plus ce que le serveur accepte en un envoi) et les dépose dans
 * le dossier des photos, sans encore les rattacher à un article : c'est l'enregistrement du
 * formulaire qui le fera, avec le nom renvoyé ici. Voir PhotoBatchUpload.
 *
 * Sous /mon_petit_site : protégé comme le reste de l'admin (access_control), en plus d'IsGranted.
 */
#[IsGranted('ROLE_ADMIN')]
final class PhotoBatchUploadController extends AbstractController
{
  #[Route('/mon_petit_site/photos/envoi', name: 'admin_photos_envoi', methods: ['POST'])]
  public function __invoke(Request $request, MediaUploadHandler $mediaUploadHandler): JsonResponse
  {
    // Lot plus lourd que post_max_size : PHP a tout jeté, jeton compris
    if ([] === $request->request->all() && [] === $request->files->all() && (int) $request->server->get('CONTENT_LENGTH') > 0) {
      return $this->json([
        'message' => sprintf('Ces photos dépassent ce que le serveur accepte en un envoi (%s).', UploadLimits::format(UploadLimits::total())),
      ], 413);
    }

    if (!$this->isCsrfTokenValid(PhotoBatchUpload::TOKEN_ID, (string) $request->request->get('_token'))) {
      return $this->json(['message' => 'La page a expiré : recharge-la, puis choisis à nouveau tes photos.'], 400);
    }

    $files = $request->files->all()['photos'] ?? [];
    $files = array_filter(is_array($files) ? $files : [$files], fn ($file) => $file instanceof UploadedFile);

    $photos = [];
    $erreurs = [];
    foreach ($files as $file) {
      $nom = $file->getClientOriginalName();

      if (!$file->isValid()) {
        $erreurs[] = in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)
          ? sprintf('« %s » : trop lourde (%s au maximum par photo).', $nom, UploadLimits::format(UploadLimits::perFile()))
          : sprintf('« %s » : l\'envoi a été interrompu, réessaie.', $nom);
        continue;
      }

      if (!$mediaUploadHandler->isAcceptedImage($file)) {
        $erreurs[] = sprintf('« %s » : ce n\'est pas une photo (JPEG, PNG, WebP…).', $nom);
        continue;
      }

      $photos[] = [
        'fichier' => $mediaUploadHandler->upload($file),
        'legende' => pathinfo($nom, PATHINFO_FILENAME),
      ];
    }

    if ([] === $photos && [] === $erreurs) {
      return $this->json(['message' => 'Aucune photo reçue.'], 400);
    }

    return $this->json(['photos' => $photos, 'erreurs' => $erreurs]);
  }
}
