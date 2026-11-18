<?php

namespace App\Controller\Admin;

use App\Entity\Carotte;
use App\Repository\ArticleRepository;
use App\Repository\CategorieRepository;
use App\Seo\SiteUrls;
use App\Service\UploadLimits;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Vich\UploaderBundle\Form\Type\VichFileType;
use Vich\UploaderBundle\Templating\Helper\UploaderHelper;

/**
 * « Carottes cachées » dans l'admin : Caroline y place elle-même chaque carotte sur la page de son
 * choix (la liste déroulante propose ses vraies catégories et ses vrais articles, lus en base),
 * avec l'image qu'elle révèle et la façon dont elle se montre.
 * Voir App\Entity\Carotte pour le sens des réglages, et admin_carottes.js pour l'aperçu.
 */
class CarotteCrudController extends AbstractCrudController
{
  /** @var array<string, array<string, string>>|null les pages proposées (titre du groupe => libellé => valeur), calculées une fois par requête */
  private ?array $choixDePages = null;

  public function __construct(
    private readonly CategorieRepository $categories,
    private readonly ArticleRepository $articles,
    private readonly SiteUrls $urls,
    private readonly UploaderHelper $uploaderHelper,
    private readonly Packages $packages,
  ) {}

  public static function getEntityFqcn(): string
  {
    return Carotte::class;
  }

  public function configureCrud(Crud $crud): Crud
  {
    return $crud
      ->setEntityLabelInSingular('Carotte cachée')
      ->setEntityLabelInPlural('Carottes cachées')
      ->setPageTitle('index', '🥕 Carottes cachées')
      ->setPageTitle('new', '🥕 Nouvelle carotte cachée')
      ->setPageTitle('edit', '🥕 Modifier cette carotte')
      ->setHelp('index', 'Chaque carotte se cache sur une page du site et montre une image quand on la trouve. Le bouton « Voir sur le site » ouvre la page avec la carotte, tout de suite, pour vérifier le rendu.')
      ->setDefaultSort(['id' => 'DESC'])
      ->setSearchFields(['nom']);
  }

  public function configureActions(Actions $actions): Actions
  {
    // Ouvre la page visée avec ?carotte=N : la carotte s'affiche tout de suite (réservé aux administrateurs)
    $voir = Action::new('voir', 'Voir sur le site', 'fa fa-eye')
      ->linkToUrl(fn (Carotte $carotte) => $this->urlDeVoir($carotte) ?? '#')
      ->displayIf(fn (Carotte $carotte) => null !== $this->urlDeVoir($carotte))
      ->setHtmlAttributes(['target' => '_blank', 'rel' => 'noopener']);

    return $actions
      ->add(Crud::PAGE_INDEX, $voir)
      ->add(Crud::PAGE_EDIT, $voir)
      ->update(Crud::PAGE_INDEX, Action::NEW, fn (Action $action) => $action->setLabel('Ajouter une carotte'))
      ->disable(Action::DETAIL);
  }

  public function configureAssets(Assets $assets): Assets
  {
    return $assets
      ->addCssFile('asset/css/admin_carottes.css')
      // Même réduction / conversion des photos que pour les articles et l'accueil
      ->addJsFile('asset/js/admin_image_resize.js')
      ->addJsFile('asset/js/admin_carottes.js');
  }

  public function configureFields(string $pageName): iterable
  {
    /** @var Carotte|null $carotte */
    $carotte = $this->getContext()?->getEntity()?->getInstance();

    yield FormField::addFieldset('La carotte', 'fa fa-carrot');

    yield TextField::new('nom', 'Nom')
      ->setRequired(true)
      ->setHelp('Pour t\'y retrouver, par exemple « Pas de… ». Les visiteurs ne le voient jamais.');

    yield BooleanField::new('actif', 'Active')
      ->setHelp('Décoche pour ranger la carotte de côté sans la supprimer : elle disparaît du site.');

    yield FormField::addFieldset('Où la cacher ?', 'fa fa-location-dot');

    yield TextField::new('cible', 'Page')
      ->setFormType(ChoiceType::class)
      ->setFormTypeOptions([
        'choices' => $this->choixDePages($carotte),
        'placeholder' => false,
        'required' => true,
        'attr' => ['data-ea-widget' => 'ea-autocomplete'],
      ])
      ->formatValue(fn ($valeur) => $this->libelleDePage((string) $valeur))
      ->setHelp('Tape quelques lettres pour retrouver une page. « Partout » montre la carotte sur toutes les pages du site. Un article n\'apparaît ici que s\'il existe.');

    yield FormField::addFieldset('L\'image qu\'elle révèle', 'fa fa-image');

    yield Field::new('imageFile', 'Image')
      ->onlyOnForms()
      ->setFormType(VichFileType::class)
      ->setFormTypeOptions([
        'required' => false,
        'allow_delete' => false,
        'download_uri' => false,
        'attr' => ['data-max-bytes' => (string) UploadLimits::perFile(), 'data-carotte-image' => '1'] + $this->apercuDe($carotte),
        'help' => sprintf('JPG, PNG ou WebP, réduite automatiquement avant l\'envoi. Taille maximale acceptée par le serveur : %s.', UploadLimits::format(UploadLimits::perFile())),
      ]);

    yield TextField::new('imageName', 'Image')
      ->onlyOnIndex()
      ->renderAsHtml()
      ->formatValue(fn (mixed $_, Carotte $c) => $this->vignette($c));

    yield TextField::new('description', 'Description de l\'image')
      ->onlyOnForms()
      ->setRequired(false)
      ->setHelp('Facultatif. Dis en une phrase ce que montre l\'image : c\'est ce que liront les personnes qui ne peuvent pas la voir.');

    yield FormField::addFieldset('Comment elle se montre', 'fa fa-wand-magic-sparkles');

    yield ChoiceField::new('modeReglage', 'Réglages')
      ->onlyOnForms()
      ->setChoices(Carotte::MODES)
      ->renderExpanded()
      ->setRequired(true)
      ->setHelp('« Au hasard » ignore les réglages ci-dessous : à chaque visite, la carotte change de taille, de transparence et de place, et l\'image aussi.');

    yield TextField::new('modeReglage', 'Réglages')
      ->onlyOnIndex()
      ->formatValue(fn ($valeur) => Carotte::MODE_HASARD === $valeur ? '🎲 Au hasard' : 'Choisis');

    yield ChoiceField::new('carotteTaille', 'Taille de la carotte')
      ->onlyOnForms()
      ->setChoices(Carotte::TAILLES_CAROTTE)
      ->renderExpanded()
      ->setRequired(true)
      ->setCssClass('carotte-reglage');

    yield IntegerField::new('carotteOpacite', 'Transparence (%)')
      ->onlyOnForms()
      ->setFormTypeOption('attr', ['min' => Carotte::OPACITE_MIN, 'max' => Carotte::OPACITE_MAX, 'step' => 5])
      ->setHelp('100 = bien visible. Plus le chiffre est petit, plus la carotte est transparente : 10 la rend presque invisible.')
      ->setCssClass('carotte-reglage');

    yield ChoiceField::new('carottePlace', 'Place sur la page')
      ->onlyOnForms()
      ->setChoices(Carotte::PLACES_CAROTTE)
      ->setFormTypeOption('placeholder', false) // pas d'option vide : une place est toujours choisie
      ->setRequired(true)
      ->setHelp('Sur la page, pas sur l\'écran : « tout en bas » oblige à descendre jusqu\'au bout pour la trouver.')
      ->setCssClass('carotte-reglage');

    yield IntegerField::new('delai', 'Apparaît après (secondes)')
      ->onlyOnForms()
      ->setFormTypeOption('attr', ['min' => 0, 'max' => Carotte::DELAI_MAX])
      ->setHelp('0 = tout de suite. Sinon la carotte arrive après ce temps passé sur la page.');

    yield FormField::addFieldset('Comment l\'image s\'affiche', 'fa fa-expand');

    yield ChoiceField::new('imageTaille', 'Taille de l\'image')
      ->onlyOnForms()
      ->setChoices(Carotte::TAILLES_IMAGE)
      ->renderExpanded()
      ->setRequired(true)
      ->setCssClass('carotte-reglage');

    yield ChoiceField::new('imagePlace', 'Place de l\'image')
      ->onlyOnForms()
      ->setChoices(Carotte::PLACES_IMAGE)
      ->setFormTypeOption('placeholder', false)
      ->setRequired(true)
      ->setCssClass('carotte-reglage');
  }

  public function persistEntity(EntityManagerInterface $em, $entityInstance): void
  {
    if ($entityInstance instanceof Carotte) {
      $entityInstance->setCreeLe(new \DateTimeImmutable());
      $entityInstance->setModifieLe(new \DateTimeImmutable());
    }

    parent::persistEntity($em, $entityInstance);
  }

  public function updateEntity(EntityManagerInterface $em, $entityInstance): void
  {
    if ($entityInstance instanceof Carotte) {
      $entityInstance->setModifieLe(new \DateTimeImmutable());
    }

    parent::updateEntity($em, $entityInstance);
  }

  /**
   * Les pages où l'on peut cacher une carotte, en groupes : le site entier, les catégories, puis les
   * articles rangés par catégorie. Si la page déjà choisie n'existe plus, elle reste proposée (signalée)
   * pour que le formulaire s'ouvre quand même.
   *
   * @return array<string, array<string, string>>
   */
  private function choixDePages(?Carotte $carotte = null): array
  {
    if (null === $this->choixDePages) {
      $groupes = [
        'Sur le site' => [
          'Partout (n\'importe quelle page du site)' => Carotte::CIBLE_PARTOUT,
          'La page d\'accueil' => Carotte::CIBLE_ACCUEIL,
        ],
      ];

      foreach ($this->categories->findAllOrdered() as $categorie) {
        $groupes['Catégories'] = ($groupes['Catégories'] ?? []) + [
          $this->libelleUnique($groupes['Catégories'] ?? [], (string) $categorie->getTitre(), (int) $categorie->getId()) => Carotte::cibleDe(Carotte::CIBLE_CATEGORIE, (int) $categorie->getId()),
        ];
      }

      $articles = $this->articles->createQueryBuilder('a')
        ->addSelect('c')
        ->leftJoin('a.categorie', 'c')
        ->addOrderBy('c.position', 'ASC')
        ->addOrderBy('a.position', 'ASC')
        ->addOrderBy('a.id', 'ASC')
        ->getQuery()
        ->getResult();

      foreach ($articles as $article) {
        $groupe = $article->getCategorie()?->getTitre() ? 'Articles : ' . $article->getCategorie()->getTitre() : 'Articles sans catégorie';
        $libelle = (string) $article->getTitre() . ($article->getAnnee() ? ', ' . $article->getAnnee() : '') . ($article->isPublie() ? '' : ' (brouillon : invisible tant qu\'il n\'est pas publié)');
        $groupes[$groupe] = ($groupes[$groupe] ?? []) + [
          $this->libelleUnique($groupes[$groupe] ?? [], $libelle, (int) $article->getId()) => Carotte::cibleDe(Carotte::CIBLE_ARTICLE, (int) $article->getId()),
        ];
      }

      $this->choixDePages = $groupes;
    }

    $choix = $this->choixDePages;

    if (null !== $carotte && !in_array($carotte->getCible(), array_merge(...array_values($choix)), true)) {
      $choix['À corriger'] = ['⚠ Cette page n\'existe plus : choisis-en une autre' => $carotte->getCible()];
    }

    return $choix;
  }

  // Deux pages peuvent porter le même titre : dans une liste déroulante, chaque libellé doit être unique.
  /** @param array<string, string> $groupe */
  private function libelleUnique(array $groupe, string $libelle, int $id): string
  {
    $libelle = '' === trim($libelle) ? 'Sans titre' : $libelle;

    return isset($groupe[$libelle]) ? sprintf('%s (n° %d)', $libelle, $id) : $libelle;
  }

  // Le nom de la page visée, pour la liste des carottes
  private function libelleDePage(string $cible): string
  {
    foreach ($this->choixDePages() as $groupe => $pages) {
      $libelle = array_search($cible, $pages, true);
      if (false !== $libelle) {
        return str_starts_with($groupe, 'Articles') ? 'Article : ' . $libelle : ('Catégories' === $groupe ? 'Catégorie : ' . $libelle : $libelle);
      }
    }

    return '⚠ Page supprimée';
  }

  // L'adresse de la page visée avec ?carotte=N, ou null si elle n'existe plus ou n'est pas visible (brouillon)
  private function urlDeVoir(Carotte $carotte): ?string
  {
    $apercu = ['carotte' => $carotte->getId()];

    $chemin = match ($carotte->cibleType()) {
      Carotte::CIBLE_PARTOUT, Carotte::CIBLE_ACCUEIL => '/',
      Carotte::CIBLE_CATEGORIE => ($categorie = $this->categories->find($carotte->cibleId())) ? $this->urls->categoryPath($categorie) : null,
      Carotte::CIBLE_ARTICLE => ($article = $this->articles->find($carotte->cibleId())) && $article->isPublie() ? $this->urls->articlePath($article) : null,
      default => null,
    };

    return null === $chemin || null === $carotte->getId() ? null : $chemin . '?' . http_build_query($apercu);
  }

  // Petite image de la liste
  private function vignette(Carotte $carotte): string
  {
    $uri = $carotte->hasImage() ? $this->uploaderHelper->asset($carotte, 'imageFile') : null;

    return null === $uri ? '' : sprintf('<img src="%s" alt="" style="height:48px;width:auto;max-width:96px;object-fit:cover;border-radius:4px">', htmlspecialchars($this->packages->getUrl($uri), \ENT_QUOTES));
  }

  /**
   * Attributs qui permettent au script de montrer l'image déjà enregistrée et un exemple de carotte.
   *
   * @return array<string, string>
   */
  private function apercuDe(?Carotte $carotte): array
  {
    $attributs = ['data-krot' => $this->packages->getUrl('asset/krot.gif', 'static_assets')];

    if (null !== $carotte && $carotte->hasImage() && null !== ($uri = $this->uploaderHelper->asset($carotte, 'imageFile'))) {
      $attributs['data-preview-url'] = $this->packages->getUrl($uri);
    }

    return $attributs;
  }
}
