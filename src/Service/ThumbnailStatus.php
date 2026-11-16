<?php

namespace App\Service;

/**
 * Ce qui s'est passé quand on a demandé la vignette d'une photo.
 */
enum ThumbnailStatus: string
{
  /** La vignette vient d'être fabriquée. */
  case Created = 'created';

  /** Elle existait déjà. */
  case Exists = 'exists';

  /** La photo est déjà petite ou légère : la vignette n'apporterait rien. */
  case NotNeeded = 'not_needed';

  /** Format qu'on ne réduit pas (GIF animé, par exemple) : la photo d'origine est utilisée. */
  case UnsupportedFormat = 'unsupported_format';

  /** Le fichier de la photo n'est plus sur le serveur. */
  case Missing = 'missing';

  /** L'extension PHP « GD » n'est pas installée : aucune vignette ne peut être fabriquée. */
  case Unavailable = 'unavailable';

  /** Fichier illisible, ou trop grand pour la mémoire du serveur. */
  case Failed = 'failed';
}
