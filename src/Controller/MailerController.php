<?php

namespace App\Controller;

use App\Form\ContactType;
use App\Repository\CategorieRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Annotation\Route;

class MailerController extends AbstractController
{
    #[Route('/contact', name: 'contact')]
    public function sendMail(Request $request, MailerInterface $mailer, CategorieRepository $cr)
    {

        $categories = $cr->findAll();

        $form = $this->createForm(ContactType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $contactFormData = $form->getData();

            $email = (new Email())
                ->from($contactFormData['email']) // your authenticated email address
                ->replyTo($contactFormData['email']) // user's email address
                ->to('contact@carottecake.com')
                ->subject($contactFormData['sujet'])
                ->text($contactFormData['message']);
            $mailer->send($email);

            $this->addFlash('success', 'Votre message a été envoyé! Merci de m\'avoir contacté : )');

            return $this->redirectToRoute('contact');
        }

        return $this->render('mailer/index.html.twig', [
            'form' => $form->createView(),
            'categories' => $categories,
        ]);
    }
}
