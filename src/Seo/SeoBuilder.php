<?php

namespace App\Seo;

use App\Entity\Article;
use App\Entity\Bloc;
use App\Entity\Categorie;
use App\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Fabrique, pour chaque page publique, ce qui la fait trouver et bien présenter par Google :
 * titre, description, adresse officielle (canonical), aperçu de partage et données structurées.
 *
 * Tout est déduit de ce que Caroline écrit déjà dans l'admin (titres, descriptions, légendes
 * des photos) : elle n'a rien de plus à remplir. Plus ces textes sont précis (technique,
 * matière, lieu, mots que les gens taperaient), mieux les pages sont comprises.
 *
 * Les phrases de repli ci-dessous servent seulement quand un texte manque ou est trop court.
 */
class SeoBuilder
{
  public const NAME = 'Caroline Marcellier';

  private const HOME_TITLE = 'Caroline Marcellier, artiste – peinture, gravure, calligraphie';
  private const HOME_DESCRIPTION = 'Caroline Marcellier, artiste dans la Loire (42) : peinture, gravure et calligraphie, ateliers participatifs, peinture sur vitrine, gravure sur verre.';
  private const SIGNATURE = 'par Caroline Marcellier, artiste dans la Loire (42)';

  // Ce que fait Caroline, en mots que les gens tapent dans Google.
  private const KNOWS_ABOUT = [
    'peinture',
    'gravure',
    'calligraphie',
    'ateliers participatifs',
    'peinture sur vitrine',
    'gravure sur verre',
    'prestations pédagogiques',
  ];

  // Google coupe l'extrait vers 155-160 caractères et le titre vers 60-65.
  private const DESCRIPTION_MAX = 155;
  private const DESCRIPTION_MIN = 50;
  private const TITLE_MAX = 65;

  private const ROBOTS_INDEX = 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';
  private const ROBOTS_NOINDEX = 'noindex, follow';

  private const DEFAULT_IMAGE_PATH = '/asset/images/auberge.jpeg';
  private const DEFAULT_IMAGE_ALT = 'Caroline Marcellier, artiste';

  private const MAX_LIST_ITEMS = 100;
  private const MAX_IMAGES = 20;

  /**
   * @param list<string> $sameAs Adresses des profils de Caroline sur d'autres sites
   */
  public function __construct(
    private readonly SiteUrls $urls,
    #[Autowire('%app.seo.same_as%')]
    private readonly array $sameAs = [],
  ) {
  }

  public function home(): SeoPage
  {
    $url = $this->urls->homeUrl();

    return new SeoPage(
      title: self::HOME_TITLE,
      description: self::HOME_DESCRIPTION,
      canonical: $url,
      image: $this->defaultImage(),
      imageAlt: self::DEFAULT_IMAGE_ALT,
      robots: self::ROBOTS_INDEX,
      graph: [
        $this->person(),
        [
          '@type' => 'WebSite',
          '@id' => $url . '#site',
          'url' => $url,
          'name' => self::NAME,
          'inLanguage' => 'fr-FR',
          'publisher' => ['@id' => $this->personId()],
        ],
        [
          '@type' => 'WebPage',
          '@id' => $url . '#page',
          'url' => $url,
          'name' => self::HOME_TITLE,
          'description' => self::HOME_DESCRIPTION,
          'inLanguage' => 'fr-FR',
          'isPartOf' => ['@id' => $url . '#site'],
          'about' => ['@id' => $this->personId()],
        ],
      ],
    );
  }

  public function contact(): SeoPage
  {
    $description = 'Contactez Caroline Marcellier, artiste dans la Loire (42), pour une commande, un atelier participatif, une peinture sur vitrine ou une prestation pédagogique.';
    $url = $this->urls->absolute('/contact');

    return new SeoPage(
      title: 'Contact | ' . self::NAME,
      description: $description,
      canonical: $url,
      image: $this->defaultImage(),
      imageAlt: self::DEFAULT_IMAGE_ALT,
      robots: self::ROBOTS_INDEX,
      graph: $this->pageGraph('ContactPage', $url, 'Contact', $description, [['Contact', $url]]),
    );
  }

  /**
   * Les mentions légales doivent exister, mais n'ont aucune raison d'apparaître dans Google.
   */
  public function legal(): SeoPage
  {
    return new SeoPage(
      title: 'Mentions légales | ' . self::NAME,
      description: 'Mentions légales du site de Caroline Marcellier.',
      canonical: $this->urls->absolute('/legal'),
      image: $this->defaultImage(),
      imageAlt: self::DEFAULT_IMAGE_ALT,
      robots: self::ROBOTS_NOINDEX,
    );
  }

  /**
   * @param list<Article> $articles Articles publiés de la catégorie, avec leurs photos (galerie seulement)
   */
  public function category(Categorie $categorie, array $articles = []): SeoPage
  {
    $titre = trim((string) $categorie->getTitre());
    $url = $this->urls->absolute($this->urls->categoryPath($categorie));
    $crumbs = [[$titre, $url]];

    $source = match (true) {
      $categorie->isTexte() => $categorie->getContenu(),
      $categorie->isVitrine() => $this->vitrineText($categorie),
      default => $categorie->getDescription(),
    };

    $description = $this->plainText($source);
    if (mb_strlen($description) >= self::DESCRIPTION_MIN) {
      $description = $this->excerpt($source);
    } else {
      $description = $this->excerpt($this->categoryFallback($categorie, $articles));
    }

    [$image, $imageAlt] = $this->categoryImage($categorie, $articles);

    $extra = [];
    if (!$categorie->isTexte() && !$categorie->isVitrine() && [] !== $articles) {
      $items = [];
      foreach (array_slice($articles, 0, self::MAX_LIST_ITEMS) as $index => $article) {
        $items[] = [
          '@type' => 'ListItem',
          'position' => $index + 1,
          'url' => $this->urls->absolute($this->urls->articlePath($article)),
          'name' => (string) $article->getTitre(),
        ];
      }

      $extra['mainEntity'] = ['@type' => 'ItemList', 'itemListElement' => $items];
    }

    $type = $categorie->isTexte() || $categorie->isVitrine() ? 'WebPage' : 'CollectionPage';

    return new SeoPage(
      title: $this->title($titre),
      description: $description,
      canonical: $url,
      image: $image,
      imageAlt: $imageAlt,
      robots: $this->categoryHasContent($categorie, count($articles)) ? self::ROBOTS_INDEX : self::ROBOTS_NOINDEX,
      graph: $this->pageGraph($type, $url, $titre, $description, $crumbs, $extra),
    );
  }

  public function article(Article $article): SeoPage
  {
    $titre = trim((string) $article->getTitre());
    $categorie = $article->getCategorie();
    $categorieTitre = null === $categorie ? null : trim((string) $categorie->getTitre());
    $url = $this->urls->absolute($this->urls->articlePath($article));

    $source = $article->getDescription();
    if (mb_strlen($this->plainText($source)) >= self::DESCRIPTION_MIN) {
      $description = $this->excerpt($source);
    } else {
      $description = $this->excerpt(sprintf(
        '%s%s, %s.',
        $titre,
        $categorieTitre ? ' – ' . $categorieTitre : '',
        self::SIGNATURE,
      ));
    }

    $medias = $this->usableMedias($article);
    $first = $medias[0] ?? null;
    $image = null === $first ? $this->defaultImage() : $this->urls->upload('attachments', (string) $first->getImageName());
    $imageAlt = null === $first ? self::DEFAULT_IMAGE_ALT : $this->mediaCaption($first, $titre);

    $crumbs = [];
    if (null !== $categorie && '' !== $categorieTitre) {
      $crumbs[] = [$categorieTitre, $this->urls->absolute($this->urls->categoryPath($categorie))];
    }
    $crumbs[] = [$titre, $url];

    $imageObjects = [];
    foreach (array_slice($medias, 0, self::MAX_IMAGES) as $media) {
      $imageObjects[] = $this->imageObject($media, $titre);
    }

    $extra = [];
    if ([] !== $imageObjects) {
      $extra['primaryImageOfPage'] = $imageObjects[0];
      $extra['image'] = $imageObjects;
    }
    if ($article->getcreeLe()) {
      $extra['datePublished'] = $article->getcreeLe()->format(\DATE_ATOM);
    }
    $modifie = $article->getModifieLe() ?? $article->getcreeLe();
    if ($modifie) {
      $extra['dateModified'] = $modifie->format(\DATE_ATOM);
    }
    if (null !== $categorie && '' !== $categorieTitre) {
      $extra['isPartOf'] = [
        '@type' => 'CollectionPage',
        '@id' => $this->urls->absolute($this->urls->categoryPath($categorie)) . '#page',
        'name' => $categorieTitre,
      ];
    }

    return new SeoPage(
      title: $this->title($titre, $categorieTitre),
      description: $description,
      canonical: $url,
      image: $image,
      imageAlt: $imageAlt,
      robots: self::ROBOTS_INDEX,
      graph: $this->pageGraph([] === $imageObjects ? 'WebPage' : 'ImageGallery', $url, $titre, $description, $crumbs, $extra),
    );
  }

  /**
   * Une catégorie vide (galerie sans article publié, page de texte sans texte, vitrine sans bloc)
   * n'a rien à montrer à Google : on la laisse hors des résultats et hors du sitemap.
   */
  public function categoryHasContent(Categorie $categorie, int $publishedArticles): bool
  {
    return match (true) {
      $categorie->isTexte() => '' !== $this->plainText($categorie->getContenu()),
      $categorie->isVitrine() => !$categorie->getBlocs()->isEmpty(),
      default => $publishedArticles > 0,
    };
  }

  /**
   * Le texte d'un morceau de HTML (éditeur de l'admin), sans balises ni espaces en double.
   */
  public function plainText(?string $html): string
  {
    if (null === $html || '' === trim($html)) {
      return '';
    }

    // Sans cette espace, « <p>un</p><p>deux</p> » deviendrait « undeux »
    $html = preg_replace('~<(?:br|/p|/div|/li|/h[1-6]|/blockquote|/tr)\b[^>]*>~i', ' ', $html) ?? $html;
    $text = html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

    return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
  }

  /**
   * Un extrait de la bonne longueur pour Google, coupé entre deux mots.
   */
  public function excerpt(?string $html, int $max = self::DESCRIPTION_MAX): string
  {
    $text = $this->plainText($html);

    // Un texte collé depuis un Mac écrit « à » en deux morceaux (la lettre « a » puis un accent
    // combinant) : on le recompose en un seul caractère, pour que la coupe ne tombe jamais entre les deux.
    if (class_exists(\Normalizer::class)) {
      $text = \Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text;
    }

    if (mb_strlen($text) <= $max) {
      return $text;
    }

    $cut = mb_substr($text, 0, $max - 1);
    $space = mb_strrpos($cut, ' ');
    if (false !== $space && $space > $max * 0.6) {
      $cut = mb_substr($cut, 0, $space);
    }

    // Surtout PAS rtrim($cut, " ,;:.-–—") : rtrim lit sa liste OCTET par OCTET. Les octets de « – » et
    // « — » (E2 80 93 / E2 80 94) contiennent « 80 », qui termine aussi l'accent combinant « ̀ » (CC 80) et
    // « À » (C3 80) : le dernier octet partait, laissant un caractère coupé en deux (UTF-8 invalide),
    // ce qui faisait échouer l'encodage JSON de la page (erreur 500). L'option /u travaille par caractère.
    return (preg_replace('/[ ,;:.\-–—]+$/u', '', $cut) ?? $cut) . '…';
  }

  /**
   * « Page | Caroline Marcellier », avec la rubrique quand elle tient dans la largeur d'un
   * résultat Google. Les mots de la page passent en premier : ce sont ceux qu'on cherche.
   */
  private function title(string $page, ?string $section = null): string
  {
    if ('' === $page) {
      return self::NAME;
    }

    // Un titre qui contient déjà son nom n'a pas besoin qu'on le répète
    if (false !== mb_stripos($page, 'marcellier')) {
      return $page;
    }

    if (null !== $section && '' !== $section && false === mb_stripos($page, $section)) {
      $withSection = sprintf('%s – %s | %s', $page, $section, self::NAME);
      if (mb_strlen($withSection) <= self::TITLE_MAX) {
        return $withSection;
      }
    }

    return $page . ' | ' . self::NAME;
  }

  /**
   * @param list<Article> $articles
   */
  private function categoryFallback(Categorie $categorie, array $articles): string
  {
    $titre = trim((string) $categorie->getTitre());

    if (!$categorie->isTexte() && !$categorie->isVitrine() && [] !== $articles) {
      $titres = array_filter(array_map(
        static fn (Article $article): string => trim((string) $article->getTitre()),
        array_slice($articles, 0, 3),
      ));

      if ([] !== $titres) {
        return sprintf('Galerie « %s » %s : %s.', $titre, self::SIGNATURE, implode(', ', $titres));
      }
    }

    return sprintf('« %s » : une page %s. Peinture, gravure et calligraphie.', $titre, self::SIGNATURE);
  }

  /**
   * @param list<Article> $articles
   *
   * @return array{0: string, 1: string} Adresse complète de l'image et sa description
   */
  private function categoryImage(Categorie $categorie, array $articles): array
  {
    if ($categorie->isVitrine()) {
      foreach ($categorie->getBlocs() as $bloc) {
        if (Bloc::TYPE_IMAGE === $bloc->getType() && $bloc->getFichierName()) {
          return [
            $this->urls->upload('vitrine', $bloc->getFichierName()),
            trim((string) $bloc->getTitre()) ?: self::DEFAULT_IMAGE_ALT,
          ];
        }
      }
    }

    if (!$categorie->isTexte() && !$categorie->isVitrine()) {
      foreach ($articles as $article) {
        $media = $this->usableMedias($article)[0] ?? null;
        if (null !== $media) {
          return [
            $this->urls->upload('attachments', (string) $media->getImageName()),
            $this->mediaCaption($media, (string) $article->getTitre()),
          ];
        }
      }
    }

    return [$this->defaultImage(), self::DEFAULT_IMAGE_ALT];
  }

  private function vitrineText(Categorie $categorie): string
  {
    $textes = [];
    foreach ($categorie->getBlocs() as $bloc) {
      if (Bloc::TYPE_TEXTE === $bloc->getType() && $bloc->getContenu()) {
        $textes[] = $bloc->getContenu();
      }
    }

    return implode(' ', $textes);
  }

  /**
   * @return list<Media> Les photos de l'article qui ont bien un fichier, dans l'ordre de la galerie
   */
  private function usableMedias(Article $article): array
  {
    return array_values(array_filter(
      $article->getMedia()->toArray(),
      static fn (Media $media): bool => null !== $media->getImageName() && '' !== $media->getImageName(),
    ));
  }

  private function mediaCaption(Media $media, string $fallback): string
  {
    return trim((string) $media->getLegende()) ?: $fallback;
  }

  private function defaultImage(): string
  {
    return $this->urls->absolute(self::DEFAULT_IMAGE_PATH);
  }

  private function personId(): string
  {
    return $this->urls->homeUrl() . '#artiste';
  }

  /**
   * @return array<string, mixed>
   */
  private function person(): array
  {
    $person = [
      '@type' => 'Person',
      '@id' => $this->personId(),
      'name' => self::NAME,
      'url' => $this->urls->homeUrl(),
      'jobTitle' => 'Artiste',
      'description' => 'Artiste : peinture, gravure et calligraphie, ateliers participatifs, peinture sur vitrine, gravure sur verre, prestations pédagogiques.',
      'knowsAbout' => self::KNOWS_ABOUT,
      // La commune seulement : l'adresse précise n'a rien à faire dans les données lues par les robots
      'address' => [
        '@type' => 'PostalAddress',
        'addressLocality' => 'Chambles',
        'postalCode' => '42170',
        'addressRegion' => 'Auvergne-Rhône-Alpes',
        'addressCountry' => 'FR',
      ],
    ];

    if ([] !== $this->sameAs) {
      $person['sameAs'] = array_values($this->sameAs);
    }

    return $person;
  }

  /**
   * @return array<string, mixed>
   */
  private function imageObject(Media $media, string $fallbackName): array
  {
    $caption = $this->mediaCaption($media, $fallbackName);

    // Google affiche l'auteur et le copyright d'une image dans ses résultats « Images »
    // quand la page les déclare ainsi : c'est la signature de Caroline sur chaque œuvre.
    return [
      '@type' => 'ImageObject',
      'contentUrl' => $this->urls->upload('attachments', (string) $media->getImageName()),
      'name' => $caption,
      'caption' => $caption,
      'creator' => ['@type' => 'Person', 'name' => self::NAME],
      'creditText' => self::NAME,
      'copyrightNotice' => '© ' . self::NAME,
    ];
  }

  /**
   * @param list<array{0: string, 1: string}> $crumbs Fil d'Ariane après l'accueil : [nom, adresse complète]
   * @param array<string, mixed>              $extra
   *
   * @return list<array<string, mixed>>
   */
  private function pageGraph(string $type, string $url, string $name, string $description, array $crumbs, array $extra = []): array
  {
    $items = [['@type' => 'ListItem', 'position' => 1, 'name' => 'Accueil', 'item' => $this->urls->homeUrl()]];
    foreach ($crumbs as $index => [$crumbName, $crumbUrl]) {
      $items[] = ['@type' => 'ListItem', 'position' => $index + 2, 'name' => $crumbName, 'item' => $crumbUrl];
    }

    return [
      $this->person(),
      [
        '@type' => $type,
        '@id' => $url . '#page',
        'url' => $url,
        'name' => $name,
        'description' => $description,
        'inLanguage' => 'fr-FR',
        'author' => ['@id' => $this->personId()],
        'breadcrumb' => ['@id' => $url . '#fil'],
      ] + $extra,
      [
        '@type' => 'BreadcrumbList',
        '@id' => $url . '#fil',
        'itemListElement' => $items,
      ],
    ];
  }
}
