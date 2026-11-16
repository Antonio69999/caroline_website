<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Les vignettes des photos d'œuvres : une copie réduite (800 px au plus) de chaque photo,
 * rangée dans public/uploads/attachments/_vignettes/ sous le même nom que l'originale.
 *
 * Pourquoi : la grille d'une catégorie et la galerie d'un article affichaient les photos
 * d'origine (jusqu'à 2 Mo chacune) réduites par le navigateur. Une vignette pèse dix à vingt
 * fois moins : la page s'affiche plus vite, ce que Google prend en compte (surtout sur mobile).
 * Le clic sur une photo ouvre toujours l'originale, et Google Images, le partage et le sitemap
 * pointent vers l'originale aussi.
 *
 * Tout est prévu pour ne jamais gêner le site : sans GD, ou si une photo résiste, on
 * affiche simplement la photo d'origine.
 */
class Thumbnailer
{
  /** Plus grand côté d'une vignette, en pixels : net sur une grille Retina comme sur une galerie. */
  public const MAX_SIDE = 800;

  private const JPEG_QUALITY = 82;
  private const WEBP_QUALITY = 80;
  private const PNG_COMPRESSION = 6;

  // Une vignette n'est gardée que si elle pèse au moins 10 % de moins que la photo d'origine
  private const MIN_SAVING_RATIO = 0.9;

  // Au-delà (≈ 7 000 × 8 500 px), même une réduction demanderait trop de mémoire
  private const MAX_PIXELS = 60_000_000;

  // Dossier des vignettes, dans celui des photos (que PHP a forcément le droit d'écrire)
  private const FOLDER = '_vignettes';
  private const URL_PREFIX = '/uploads/attachments';

  private readonly string $originalsDir;
  private readonly string $thumbsDir;

  public function __construct(
    #[Autowire(param: 'kernel.project_dir')]
    string $projectDir,
  ) {
    $this->originalsDir = $projectDir . '/public/uploads/attachments';
    $this->thumbsDir = $this->originalsDir . '/' . self::FOLDER;
  }

  public function isAvailable(): bool
  {
    return extension_loaded('gd');
  }

  /**
   * L'adresse à afficher pour une petite image : la vignette si elle existe, sinon la photo d'origine.
   */
  public function url(string $imageName): string
  {
    if ($this->isSafeName($imageName) && is_file($this->thumbPath($imageName))) {
      return self::URL_PREFIX . '/' . self::FOLDER . '/' . rawurlencode($imageName);
    }

    return self::URL_PREFIX . '/' . rawurlencode($imageName);
  }

  public function generate(string $imageName, bool $force = false): ThumbnailStatus
  {
    if (!$this->isSafeName($imageName)) {
      return ThumbnailStatus::Missing;
    }

    $source = $this->originalsDir . '/' . $imageName;
    $target = $this->thumbPath($imageName);

    if (!is_file($source)) {
      return ThumbnailStatus::Missing;
    }

    if (!$force && is_file($target)) {
      return ThumbnailStatus::Exists;
    }

    if (!$this->isAvailable()) {
      return ThumbnailStatus::Unavailable;
    }

    $info = @getimagesize($source);
    if (false === $info) {
      return ThumbnailStatus::Failed;
    }

    [$width, $height, $type] = $info;

    $format = match ($type) {
      \IMAGETYPE_JPEG => 'jpeg',
      \IMAGETYPE_PNG => 'png',
      \IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? 'webp' : null,
      default => null,
    };

    if (null === $format) {
      return ThumbnailStatus::UnsupportedFormat;
    }

    if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
      return ThumbnailStatus::Failed;
    }

    if (max($width, $height) <= self::MAX_SIDE) {
      // Déjà petite : on retire une éventuelle ancienne vignette devenue inutile
      if ($force && is_file($target)) {
        @unlink($target);
      }

      return ThumbnailStatus::NotNeeded;
    }

    // Une photo de 5 000 px occupe environ 100 Mo une fois ouverte : on s'assure d'avoir la place
    if (!$this->ensureMemory((int) ($width * $height * 5))) {
      return ThumbnailStatus::Failed;
    }

    $image = match ($format) {
      'jpeg' => @imagecreatefromjpeg($source),
      'png' => @imagecreatefrompng($source),
      default => @imagecreatefromwebp($source),
    };

    if (false === $image) {
      return ThumbnailStatus::Failed;
    }

    $ratio = self::MAX_SIDE / max($width, $height);
    $newWidth = max(1, (int) round($width * $ratio));
    $newHeight = max(1, (int) round($height * $ratio));

    $thumb = imagecreatetruecolor($newWidth, $newHeight);

    if ('jpeg' !== $format) {
      // Garde la transparence des PNG et WebP
      imagealphablending($thumb, false);
      imagesavealpha($thumb, true);
      imagefilledrectangle($thumb, 0, 0, $newWidth, $newHeight, (int) imagecolorallocatealpha($thumb, 0, 0, 0, 127));
    }

    imagecopyresampled($thumb, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    unset($image);

    if ('jpeg' === $format) {
      $thumb = $this->orient($thumb, ExifOrientation::ofJpegFile($source));
      imageinterlace($thumb, true);
    }

    if (!$this->ensureFolder()) {
      return ThumbnailStatus::Failed;
    }

    // On écrit d'abord à côté puis on renomme : un visiteur ne tombe jamais sur une vignette à moitié écrite
    $temporary = $target . '.' . getmypid() . '.tmp';

    $written = match ($format) {
      'jpeg' => imagejpeg($thumb, $temporary, self::JPEG_QUALITY),
      'png' => imagepng($thumb, $temporary, self::PNG_COMPRESSION),
      default => imagewebp($thumb, $temporary, self::WEBP_QUALITY),
    };

    if (!$written) {
      @unlink($temporary);

      return ThumbnailStatus::Failed;
    }

    // Une photo déjà très compressée peut peser moins que sa propre vignette : dans ce cas la vignette
    // ne ferait que ralentir la page, on garde la photo d'origine.
    if (filesize($temporary) >= filesize($source) * self::MIN_SAVING_RATIO) {
      @unlink($temporary);
      // (avec --force, une ancienne vignette plus lourde que la photo ne doit pas rester)
      @unlink($target);

      return ThumbnailStatus::NotNeeded;
    }

    if (!@rename($temporary, $target)) {
      // Windows refuse parfois de remplacer un fichier existant
      @unlink($target);

      if (!@rename($temporary, $target)) {
        @unlink($temporary);

        return ThumbnailStatus::Failed;
      }
    }

    return ThumbnailStatus::Created;
  }

  /**
   * Supprime la vignette d'une photo (appelé quand la photo est supprimée).
   */
  public function remove(string $imageName): void
  {
    if ($this->isSafeName($imageName) && is_file($this->thumbPath($imageName))) {
      @unlink($this->thumbPath($imageName));
    }
  }

  /**
   * Remet à l'endroit une vignette fabriquée à partir d'une photo « couchée » (réglage EXIF).
   *
   * @param int $orientation 1 = à l'endroit ; 3 = à l'envers ; 6 = à tourner d'un quart de tour
   *                         vers la droite (photo de téléphone en portrait) ; 8 = vers la gauche ;
   *                         2, 4, 5 et 7 = les mêmes avec un effet miroir
   */
  private function orient(\GdImage $image, int $orientation): \GdImage
  {
    $steps = match ($orientation) {
      2 => [[null, \IMG_FLIP_HORIZONTAL]],
      3 => [[180.0, null]],
      4 => [[null, \IMG_FLIP_VERTICAL]],
      5 => [[-90.0, null], [null, \IMG_FLIP_HORIZONTAL]],
      6 => [[-90.0, null]],
      7 => [[90.0, null], [null, \IMG_FLIP_HORIZONTAL]],
      8 => [[90.0, null]],
      default => [],
    };

    foreach ($steps as [$angle, $flip]) {
      if (null !== $angle) {
        $rotated = imagerotate($image, $angle, 0);
        if (false !== $rotated) {
          $image = $rotated;
        }
      }

      if (null !== $flip) {
        imageflip($image, $flip);
      }
    }

    return $image;
  }

  /**
   * Une photo doit être un simple nom de fichier : jamais un chemin (« ../ »).
   */
  private function isSafeName(string $imageName): bool
  {
    return '' !== $imageName && basename($imageName) === $imageName && !str_contains($imageName, "\0");
  }

  private function thumbPath(string $imageName): string
  {
    return $this->thumbsDir . '/' . $imageName;
  }

  private function ensureFolder(): bool
  {
    return is_dir($this->thumbsDir) || @mkdir($this->thumbsDir, 0775, true) || is_dir($this->thumbsDir);
  }

  /**
   * Fait de la place pour ouvrir une grande photo, si le serveur le permet.
   */
  private function ensureMemory(int $needed): bool
  {
    $limit = $this->memoryLimit();

    if ($limit < 0) {
      return true;
    }

    $used = memory_get_usage(true);

    if ($used + $needed <= $limit) {
      return true;
    }

    $wanted = $used + $needed + 32 * 1024 * 1024;

    // Jamais plus d'un gigaoctet, même pour une très grande photo
    if ($wanted > 1024 * 1024 * 1024) {
      return false;
    }

    return false !== @ini_set('memory_limit', (string) $wanted);
  }

  /**
   * @return int Limite de mémoire de PHP en octets, -1 si elle est illimitée
   */
  private function memoryLimit(): int
  {
    $value = trim((string) ini_get('memory_limit'));

    if ('' === $value || '-1' === $value) {
      return -1;
    }

    $number = (int) $value;

    return match (strtolower(substr($value, -1))) {
      'g' => $number * 1024 * 1024 * 1024,
      'm' => $number * 1024 * 1024,
      'k' => $number * 1024,
      default => $number,
    };
  }
}
