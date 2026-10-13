<?php

namespace App\Form;

use App\Entity\Bloc;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Vich\UploaderBundle\Form\Type\VichFileType;

/**
 * Formulaire d'un bloc de vitrine, répété dans la page d'édition de la catégorie.
 * Tous les champs sont affichés ; c'est admin_vitrine_blocs.js qui montre ceux
 * utiles au type choisi (le contrôle de ce qui est obligatoire est dans Bloc::validate()).
 */
class BlocType extends AbstractType
{
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
      ->add('largeur', ChoiceType::class, [
        'label' => 'Largeur d\'affichage',
        'choices' => Bloc::LARGEURS,
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
      $aide = sprintf('Taille maximale acceptée par le serveur : %s.', self::tailleMaxServeur());

      if ($bloc instanceof Bloc && null !== $bloc->getFichierName()) {
        $aide = sprintf('Fichier actuel : %s. Choisis un autre fichier pour le remplacer. %s', $bloc->getFichierName(), $aide);
      }

      $event->getForm()->add('fichierFile', VichFileType::class, [
        'label' => 'Fichier',
        'required' => false,
        'allow_delete' => false,
        'download_uri' => false,
        'help' => $aide,
        'attr' => ['data-max-bytes' => (string) UploadedFile::getMaxFilesize()],
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

  private static function tailleMaxServeur(): string
  {
    $octets = UploadedFile::getMaxFilesize();

    return sprintf('%s Mo', rtrim(rtrim(number_format($octets / 1024 / 1024, 1, ',', ''), '0'), ','));
  }
}
