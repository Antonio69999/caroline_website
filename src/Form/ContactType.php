<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

class ContactType extends AbstractType
{
  public function buildForm(FormBuilderInterface $builder, array $options): void
  {
    $builder
      ->add('sujet', TextType::class, [
        'constraints' => [
          new Assert\NotBlank(message: 'Merci d\'indiquer votre nom.'),
          new Assert\Length(min: 2, max: 150),
          new Assert\Regex(pattern: '/[\r\n]/', match: false, message: 'Caractère non autorisé.'),
        ],
      ])
      ->add('email', EmailType::class, [
        'constraints' => [
          new Assert\NotBlank(message: 'Merci d\'indiquer votre email.'),
          new Assert\Email(message: 'Cette adresse email n\'est pas valide.'),
          new Assert\Length(max: 180),
          new Assert\Regex(pattern: '/[\r\n]/', match: false, message: 'Cette adresse email n\'est pas valide.'),
        ],
      ])
      ->add('message', TextareaType::class, [
        'constraints' => [
          new Assert\NotBlank(message: 'Merci de rédiger un message.'),
          new Assert\Length(min: 10, max: 5000),
        ],
      ])
      // Honeypot: left empty by real visitors (hidden via CSS/aria-hidden in
      // the template), filled in by naive bots that auto-fill every field.
      ->add('website', TextType::class, [
        'mapped' => false,
        'required' => false,
        'label' => false,
        'attr' => [
          'tabindex' => '-1',
          'autocomplete' => 'off',
        ],
      ]);
    // ->add('send', SubmitType::class, ['label' => 'Envoyé']);
  }
}
