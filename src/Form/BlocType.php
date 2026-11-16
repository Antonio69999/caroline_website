<?php

namespace App\Form;

use App\Entity\Bloc;
use App\Service\UploadLimits;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Vich\UploaderBundle\Form\Type\VichFileType;
use Vich\UploaderBundle\Templating\Helper\UploaderHelper;

/**
 * Formulaire d'un bloc de vitrine, répété dans la page d'édition de la catégorie.
 * Tous les champs sont affichés ; c'est admin_vitrine_blocs.js qui montre ceux
 * utiles au type choisi (le contrôle de ce qui est obligatoire est dans Bloc::validate()).
 * Le type, lui, est fixé à la création du bloc par les boutons « Ajouter » du même script :
 * les boutons radio restent dans le formulaire (c'est ce qui est envoyé) mais sont masqués.
 */
class BlocType extends AbstractType
{
  public function __construct(
    private readonly UploaderHelper $uploaderHelper,
    private readonly Packages $packages,
  ) {}

  public function buildForm(FormBuilderInterface $builder, array $options): void
  {
    $builder
      ->add('type', ChoiceType::class, [
        'label' => 'Type de bloc',
        'choices' => Bloc::TYPES,
        'expanded' => true,
      ])
      ->add('contenu', TextareaType::class, [
        'label' => 'Texte',
        'required' => false,
        // Même éditeur que la page de texte (CV) : admin_category_rich_text.js le détecte.
        'attr' => [
          'rows' => 8,
          'data-rich-category-editor' => '1',
          'data-linkable-articles' => $options['linkable_articles'],
        ],
      ])
      ->add('titre', TextType::class, [
        'label' => 'Titre',
        'required' => false,
        'attr' => ['maxlength' => 255],
      ])
      // Des boutons plutôt qu'une liste déroulante : les trois tailles se comparent d'un coup d'œil.
      ->add('largeur', ChoiceType::class, [
        'label' => 'Taille sur la page',
        'choices' => Bloc::LARGEURS,
        'expanded' => true,
        'help' => 'Sur téléphone, le bloc prend toujours toute la largeur.',
      ])
      // Renumérotée par le JS à chaque déplacement ; rien à saisir ici.
      ->add('position', HiddenType::class, ['empty_data' => '0']);

    $builder->get('position')->addModelTransformer(new CallbackTransformer(
      fn (?int $position) => (string) $position,
      fn (?string $position) => (int) $position,
    ));

    // Le champ fichier explique ce qui est déjà en place et ce que le serveur accepte.
    $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
      $bloc = $event->getData();
      $aide = sprintf('Taille maximale acceptée par le serveur : %s.', UploadLimits::format(UploadLimits::perFile()));
      $attr = ['data-max-bytes' => (string) UploadLimits::perFile()];

      if ($bloc instanceof Bloc && null !== $bloc->getFichierName()) {
        $aide = sprintf('Fichier actuel : %s. Choisis un autre fichier pour le remplacer. %s', $bloc->getFichierName(), $aide);

        // Pour que le JS puisse montrer le fichier déjà enregistré (miniature, lecteur…)
        $uri = $this->uploaderHelper->asset($bloc, 'fichierFile');
        if (null !== $uri) {
          $attr['data-preview-url'] = $this->packages->getUrl($uri);
        }
      }

      $event->getForm()->add('fichierFile', VichFileType::class, [
        'label' => 'Fichier',
        'required' => false,
        'allow_delete' => false,
        'download_uri' => false,
        'help' => $aide,
        'attr' => $attr,
      ]);
    });
  }

  public function configureOptions(OptionsResolver $resolver): void
  {
    $resolver->setDefaults([
      'data_class' => Bloc::class,
      // Articles que l'éditeur de texte peut citer (JSON, voir CategorieCrudController).
      'linkable_articles' => '[]',
    ]);
    $resolver->setAllowedTypes('linkable_articles', 'string');
  }
}
