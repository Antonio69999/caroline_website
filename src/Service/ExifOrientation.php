<?php

namespace App\Service;

/**
 * Lit, dans une photo JPEG, le réglage « orientation » écrit par l'appareil (EXIF).
 *
 * Une photo de téléphone prise en portrait est souvent enregistrée « couchée » avec la
 * mention « à afficher tournée de 90° ». Les navigateurs la respectent, mais pas la
 * bibliothèque qui fabrique les vignettes : sans cette lecture, les vignettes de ces
 * photos s'afficheraient de travers.
 *
 * Volontairement écrit à la main (quelques lignes) pour ne pas dépendre de l'extension
 * PHP « exif », qui n'est pas installée partout.
 */
final class ExifOrientation
{
  private const TAG_ORIENTATION = 0x0112;
  private const MAX_SEGMENT_SCAN = 64;

  /**
   * @return int 1 (photo à l'endroit, ou réglage absent) à 8 : voir Thumbnailer::orient()
   */
  public static function ofJpegFile(string $path): int
  {
    $handle = @fopen($path, 'rb');

    if (false === $handle) {
      return 1;
    }

    try {
      return self::scan($handle);
    } finally {
      fclose($handle);
    }
  }

  /**
   * @param resource $handle
   */
  private static function scan($handle): int
  {
    if ("\xFF\xD8" !== fread($handle, 2)) {
      return 1;
    }

    // Un JPEG est une suite de segments ; les réglages EXIF sont dans l'un des premiers.
    for ($i = 0; $i < self::MAX_SEGMENT_SCAN; ++$i) {
      $byte = fread($handle, 1);
      if ("\xFF" !== $byte) {
        return 1;
      }

      do {
        $marker = fread($handle, 1);
      } while ("\xFF" === $marker);

      if (false === $marker || '' === $marker) {
        return 1;
      }

      $code = ord($marker);

      // Début de l'image elle-même, ou fin du fichier : plus de réglages à lire
      if (0xDA === $code || 0xD9 === $code) {
        return 1;
      }

      // Marqueurs isolés, sans contenu
      if ((0xD0 <= $code && $code <= 0xD7) || 0x01 === $code) {
        continue;
      }

      $lengthBytes = fread($handle, 2);
      if (false === $lengthBytes || 2 !== strlen($lengthBytes)) {
        return 1;
      }

      $length = unpack('n', $lengthBytes)[1] - 2;
      if ($length < 0) {
        return 1;
      }

      if (0xE1 === $code) {
        $payload = $length > 0 ? fread($handle, $length) : '';

        if (is_string($payload) && str_starts_with($payload, "Exif\0\0")) {
          return self::fromTiff(substr($payload, 6));
        }

        continue;
      }

      if ($length > 0 && 0 !== fseek($handle, $length, \SEEK_CUR)) {
        return 1;
      }
    }

    return 1;
  }

  private static function fromTiff(string $tiff): int
  {
    if (strlen($tiff) < 8) {
      return 1;
    }

    // « II » : octets dans l'ordre Intel ; « MM » : dans l'ordre Motorola
    [$short, $long] = match (substr($tiff, 0, 2)) {
      'II' => ['v', 'V'],
      'MM' => ['n', 'N'],
      default => [null, null],
    };

    if (null === $short || 42 !== unpack($short, substr($tiff, 2, 2))[1]) {
      return 1;
    }

    $offset = unpack($long, substr($tiff, 4, 4))[1];

    if (strlen($tiff) < $offset + 2) {
      return 1;
    }

    $count = unpack($short, substr($tiff, $offset, 2))[1];

    for ($i = 0; $i < $count; ++$i) {
      $entry = $offset + 2 + 12 * $i;

      if (strlen($tiff) < $entry + 12) {
        return 1;
      }

      if (self::TAG_ORIENTATION === unpack($short, substr($tiff, $entry, 2))[1]) {
        $value = unpack($short, substr($tiff, $entry + 8, 2))[1];

        return $value >= 1 && $value <= 8 ? $value : 1;
      }
    }

    return 1;
  }
}
