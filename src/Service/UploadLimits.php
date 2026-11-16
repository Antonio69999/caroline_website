<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Ce que le serveur PHP accepte à l'envoi d'un formulaire. Au-delà, PHP ne se
 * contente pas de refuser le fichier : il jette tout le formulaire (ou, pour le
 * nombre de fichiers, ignore les derniers sans rien dire). L'admin lit ces
 * valeurs pour prévenir avant l'envoi plutôt qu'après.
 */
final class UploadLimits
{
  private const DEFAULT_MAX_FILES = 20;

  /** Taille maximale d'un seul fichier, en octets. */
  public static function perFile(): int
  {
    return (int) UploadedFile::getMaxFilesize();
  }

  /** Taille maximale de tout le formulaire (fichiers compris), en octets ; 0 = pas de limite. */
  public static function total(): int
  {
    return self::parseBytes((string) ini_get('post_max_size'));
  }

  /** Nombre maximal de fichiers dans un même envoi. */
  public static function maxFiles(): int
  {
    $max = (int) ini_get('max_file_uploads');

    return $max > 0 ? $max : self::DEFAULT_MAX_FILES;
  }

  /** « 8M », « 512K », « 1G » ou un nombre d'octets, comme dans php.ini. */
  public static function parseBytes(string $value): int
  {
    $value = trim($value);
    if ('' === $value) {
      return 0;
    }

    $number = (int) $value;
    $unit = strtolower(substr($value, -1));

    return match ($unit) {
      'g' => $number * 1024 * 1024 * 1024,
      'm' => $number * 1024 * 1024,
      'k' => $number * 1024,
      default => $number,
    };
  }

  /** « 2 Mo », « 1,5 Mo »… pour les messages affichés à l'utilisateur. */
  public static function format(int $bytes): string
  {
    $mo = rtrim(rtrim(number_format($bytes / 1024 / 1024, 1, ',', ''), '0'), ',');

    return sprintf('%s Mo', $mo);
  }
}
