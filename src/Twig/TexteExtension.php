<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Le texte libre d'un article ou d'une catégorie (éditeur Trix de l'admin), prêt à être affiché :
 *  - texte_propre : retire les espaces que l'éditeur laisse en fin de ligne ;
 *  - texte_est_long() : dit si le texte est « long », pour que la page choisisse son alignement.
 *
 * Pourquoi l'alignement se décide ici, et pas dans le CSS : un texte court (une légende, une
 * ligne de dimensions) est plus beau centré sous les photos ; un texte long, centré, a des lignes
 * qui partent chacune d'un endroit. Le CSS ne peut juger que ce que le navigateur affiche, donc
 * une légende d'une ligne à 100 % passerait sur trois lignes à 150 % de zoom et changerait
 * d'alignement en cours de route. Le nombre de caractères, lui, ne dépend pas du zoom.
 */
class TexteExtension extends AbstractExtension
{
  /** Au-delà, un texte est « long » (environ 3 lignes de la colonne de texte) : aligné à gauche. */
  public const SEUIL_TEXTE_LONG = 250;

  public function getFilters(): array
  {
    return [
      // Même confiance que |raw : ce HTML vient de l'admin, il n'est pas ré-échappé
      new TwigFilter('texte_propre', $this->nettoyer(...), ['is_safe' => ['html']]),
    ];
  }

  public function getFunctions(): array
  {
    return [
      new TwigFunction('texte_est_long', $this->estLong(...)),
    ];
  }

  /**
   * Retire les espaces (insécables compris) juste avant un retour à la ligne ou la fin d'un bloc.
   *
   * Trix écrit « fin de ligne&nbsp;<br> » quand on tape une espace avant d'aller à la ligne. Une
   * espace insécable ne s'efface pas en fin de ligne : une ligne centrée se retrouve décalée vers
   * la gauche d'une demi-espace par espace laissée. Seules les espaces de FIN de ligne partent :
   * « le&nbsp;fusain » ou « psst&nbsp;! » (espace voulue avant un signe de ponctuation) restent.
   * Une ligne qui ne contient QUE une espace insécable reste aussi : sans elle un <div> vide
   * s'écraserait et la ligne blanche disparaîtrait.
   */
  public function nettoyer(?string $html): string
  {
    if (null === $html || '' === $html) {
      return '';
    }

    $propre = preg_replace(
      '~(?<!<div>|<p>|<li>|<blockquote>|<h[1-6]>)'                       // pas au début d'un bloc : la ligne ne doit pas devenir vide
      . '(?:&nbsp;|&#160;|&#xA0;|[\s\x{00A0}])+'                           // les espaces à retirer
      . '(?=(?:</(?:strong|em|b|i|u|a|span)>)*'                            // (en laissant passer les balises de style refermées)
      . '(?:<br\b[^>]*>|</(?:div|p|li|h[1-6]|blockquote)>|$))~iu',        // …juste avant la fin de ligne ou de bloc
      '',
      $html,
    );

    // Texte mal encodé (preg renvoie null) : on affiche tel quel plutôt que rien
    return $propre ?? $html;
  }

  /**
   * Vrai si le texte dépasse SEUIL_TEXTE_LONG caractères (hors balises, espaces comptées une fois).
   */
  public function estLong(?string $html): bool
  {
    if (null === $html || '' === trim($html)) {
      return false;
    }

    // Une espace à la place des retours à la ligne : « un<br>deux » ne doit pas compter pour « undeux »
    $html = preg_replace('~<(?:br|/p|/div|/li|/h[1-6]|/blockquote)\b[^>]*>~i', ' ', $html) ?? $html;
    $texte = html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    $texte = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $texte));

    return mb_strlen($texte) > self::SEUIL_TEXTE_LONG;
  }
}
