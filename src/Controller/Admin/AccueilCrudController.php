<?php

namespace App\Controller\Admin;

use App\Entity\Accueil;
use App\Repository\AccueilRepository;
use App\Service\UploadLimits;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ColorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\Asset\Packages;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Vich\UploaderBundle\Form\Type\VichFileType;
use Vich\UploaderBundle\Templating\Helper\UploaderHelper;

/**
 * « Page d'accueil » dans l'admin : choisir la photo de l'accueil et la cadrer, sur ordinateur
 * et sur téléphone. Il n'y a qu'une page à régler : le menu mène directement à son formulaire
 * (admin_accueil), sans liste ni bouton « Créer » / « Supprimer ».
 * Voir App\Entity\Accueil pour ce que veulent dire les réglages, et admin_accueil.js pour
 * l'aperçu et le choix du point à garder.
 */
class AccueilCrudController extends AbstractCrudController
{
  public function __construct(
    private readonly AccueilRepository $accueilRepository,
    private readonly AdminUrlGenerator $adminUrlGenerator,
    private readonly UploaderHelper $uploaderHelper,
    private readonly Packages $packages,
  ) {}

  public static function getEntityFqcn(): string
  {
    return Accueil::class;
  }

  // Cible du menu : ouvre le formulaire de l'unique ligne (créée vide au premier passage).
  #[Route('/admin/accueil', name: 'admin_accueil', methods: ['GET'])]
  public function open(): Response
  {
    return $this->redirect($this->editUrl($this->accueilRepository->findOrCreate()));
  }

  private function editUrl(Accueil $accueil): string
  {
    return $this->adminUrlGenerator
      ->setDashboard(DashboardController::class)
      ->setController(self::class)
      ->setAction(Action::EDIT)
      ->setEntityId($accueil->getId())
      ->generateUrl();
  }

  public function configureCrud(Crud $crud): Crud
  {
    return $crud
      ->setEntityLabelInSingular('Page d\'accueil')
      ->setPageTitle('edit', '🏠 Page d\'accueil')
      // Pas de liste, donc pas de recherche (elle mènerait à la liste désactivée)
      ->setSearchFields(null);
  }

  public function configureActions(Actions $actions): Actions
  {
    return $actions
      // Une seule page, toujours là : on ne la crée ni ne la supprime, et il n'y a pas de liste
      ->update(Crud::PAGE_EDIT, Action::SAVE_AND_CONTINUE, fn (Action $action) => $action->setLabel('Enregistrer'))
      ->remove(Crud::PAGE_EDIT, Action::SAVE_AND_RETURN)
      ->disable(Action::NEW, Action::INDEX, Action::DELETE, Action::DETAIL, Action::BATCH_DELETE);
  }

  public function configureAssets(Assets $assets): Assets
  {
    return $assets
      ->addCssFile('asset/css/admin_accueil.css')
      // Même réduction / conversion des photos que pour les articles et la vitrine
      ->addJsFile('asset/js/admin_image_resize.js')
      ->addJsFile('asset/js/admin_accueil.js');
  }

  public function configureFields(string $pageName): iterable
  {
    /** @var Accueil|null $accueil */
    $accueil = $this->getContext()?->getEntity()?->getInstance();
    $limites = [
      'data-max-bytes' => (string) UploadLimits::perFile(),
    ];

    yield FormField::addFieldset('Photo de l\'accueil', 'fa fa-image')
      ->setHelp('La photo qui s\'affiche en grand sur la page d\'accueil. Elle est réduite automatiquement avant l\'envoi. Tant que tu n\'en choisis pas, le site garde son accueil d\'origine.');

    yield Field::new('imageFile', 'Photo')
      ->onlyOnForms()
      ->setFormType(VichFileType::class)
      ->setFormTypeOptions([
        'required' => false,
        'allow_delete' => true,
        'delete_label' => 'Retirer cette photo et revenir à l\'accueil d\'origine',
        'download_uri' => false,
        'attr' => $limites + ['data-accueil-photo' => 'principale'] + $this->previewAttr($accueil, 'imageFile', $accueil?->getImageName()),
        'help' => sprintf('JPG, PNG ou WebP. Taille maximale acceptée par le serveur : %s.', UploadLimits::format(UploadLimits::perFile())),
      ]);

    yield TextField::new('description', 'Description de la photo')
      ->onlyOnForms()
      ->setRequired(false)
      ->setHelp('Facultatif. Dis en une phrase ce que montre la photo : c\'est ce que liront les personnes qui ne peuvent pas la voir, et Google s\'en sert pour comprendre l\'image.');

    yield FormField::addFieldset('Sur ordinateur', 'fa fa-desktop');

    yield ChoiceField::new('bureauMode', 'Affichage')
      ->onlyOnForms()
      ->setChoices(Accueil::MODES)
      ->renderExpanded()
      ->setRequired(true);

    yield IntegerField::new('bureauZoom', 'Zoom (%)')
      ->onlyOnForms()
      ->setFormTypeOption('attr', ['min' => Accueil::ZOOM_MIN, 'max' => Accueil::ZOOM_MAX, 'step' => 5])
      ->setHelp('100 % = pas de zoom. Utile seulement en plein écran.');

    yield IntegerField::new('bureauX', 'Partie à garder : gauche ↔ droite (%)')
      ->onlyOnForms()
      ->setFormTypeOption('attr', ['min' => 0, 'max' => 100]);

    yield IntegerField::new('bureauY', 'Partie à garder : haut ↔ bas (%)')
      ->onlyOnForms()
      ->setFormTypeOption('attr', ['min' => 0, 'max' => 100]);

    yield FormField::addFieldset('Sur téléphone', 'fa fa-mobile-screen')
      ->setHelp('Un téléphone est vertical : une photo panoramique y est souvent mieux cadrée autrement, voire remplacée par une autre photo.');

    yield Field::new('imageMobileFile', 'Autre photo pour téléphone')
      ->onlyOnForms()
      ->setFormType(VichFileType::class)
      ->setFormTypeOptions([
        'required' => false,
        'allow_delete' => true,
        'delete_label' => 'Retirer cette photo (le téléphone utilisera la photo principale)',
        'download_uri' => false,
        'attr' => $limites + ['data-accueil-photo' => 'mobile'] + $this->previewAttr($accueil, 'imageMobileFile', $accueil?->getImageMobileName()),
        'help' => 'Facultatif. Sans elle, le téléphone montre la photo principale avec le cadrage ci-dessous.',
      ]);

    yield ChoiceField::new('mobileMode', 'Affichage')
      ->onlyOnForms()
      ->setChoices(Accueil::MODES)
      ->renderExpanded()
      ->setRequired(true);

    yield IntegerField::new('mobileZoom', 'Zoom (%)')
      ->onlyOnForms()
      ->setFormTypeOption('attr', ['min' => Accueil::ZOOM_MIN, 'max' => Accueil::ZOOM_MAX, 'step' => 5])
      ->setHelp('100 % = pas de zoom. Utile seulement en plein écran.');

    yield IntegerField::new('mobileX', 'Partie à garder : gauche ↔ droite (%)')
      ->onlyOnForms()
      ->setFormTypeOption('attr', ['min' => 0, 'max' => 100]);

    yield IntegerField::new('mobileY', 'Partie à garder : haut ↔ bas (%)')
      ->onlyOnForms()
      ->setFormTypeOption('attr', ['min' => 0, 'max' => 100]);

    yield FormField::addFieldset('Marges', 'fa fa-fill-drip');

    yield ColorField::new('couleurFond', 'Couleur autour de la photo')
      ->onlyOnForms()
      ->setHelp('Visible seulement en « photo entière », quand la photo ne remplit pas tout l\'écran.');
  }

  /**
   * Adresse de la photo déjà enregistrée, pour que le script en montre l'aperçu (comme pour les blocs d'une vitrine).
   *
   * @return array<string, string>
   */
  private function previewAttr(?Accueil $accueil, string $champ, ?string $nom): array
  {
    if (null === $accueil || null === $nom || '' === $nom) {
      return [];
    }

    $uri = $this->uploaderHelper->asset($accueil, $champ);

    return null === $uri ? [] : ['data-preview-url' => $this->packages->getUrl($uri)];
  }

  public function updateEntity(EntityManagerInterface $em, $entityInstance): void
  {
    if ($entityInstance instanceof Accueil) {
      $entityInstance->setModifieLe(new \DateTimeImmutable());
    }

    parent::updateEntity($em, $entityInstance);
  }

  // Après l'enregistrement, on reste sur le formulaire (il n'y a pas de liste où retourner).
  protected function getRedirectResponseAfterSave(AdminContext $context, string $action): RedirectResponse
  {
    $this->addFlash('success', 'La page d\'accueil est enregistrée. Ouvre le site pour la voir.');

    return $this->redirect($this->editUrl($context->getEntity()->getInstance()));
  }
}
