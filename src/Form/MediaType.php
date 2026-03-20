<?php

namespace App\Form;

use App\Entity\Media;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Vich\UploaderBundle\Form\Type\VichImageType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class MediaType extends AbstractType
{
  public function buildForm(FormBuilderInterface $builder, array $options): void
  {
    $builder
      ->add('imageFile', VichImageType::class, [
        'required' => false,
        'allow_delete' => true,
        'download_uri' => false,
        'label' => 'Image',
        'attr' => ['accept' => 'image/*']
      ])
      ->add('legende', TextType::class, [
        'required' => false,
        'label' => 'Légende / Texte alternatif'
      ]);
  }

  public function configureOptions(OptionsResolver $resolver): void
  {
    $resolver->setDefaults([
      'data_class' => Media::class,
    ]);
  }
}
