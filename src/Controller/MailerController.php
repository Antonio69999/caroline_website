<?php

namespace App\Controller;

use App\Form\ContactType;
use App\Repository\CategorieRepository;
use App\Service\Contact\SpamHeuristics;
use App\Service\Contact\TurnstileVerifier;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

class MailerController extends AbstractController
{
  private const SESSION_RENDERED_AT_KEY = 'contact_form_rendered_at';

  public function __construct(
    private readonly TurnstileVerifier $turnstileVerifier,
    private readonly SpamHeuristics $spamHeuristics,
    private readonly RateLimiterFactoryInterface $contactFormLimiter,
    private readonly LoggerInterface $logger,
    #[Autowire(env: 'TURNSTILE_SITE_KEY')]
    private readonly string $turnstileSiteKey,
  ) {
  }

  #[Route('/contact', name: 'contact', methods: ['GET', 'POST'])]
  public function sendMail(Request $request, MailerInterface $mailer, CategorieRepository $cr): Response
  {
    $categories = $cr->findAll();
    $session = $request->getSession();

    $form = $this->createForm(ContactType::class);
    $form->handleRequest($request);

    if (!$request->isMethod('POST')) {
      // Records when the form was first displayed to this visitor, used
      // server-side to reject near-instant (bot-speed) submissions.
      $session->set(self::SESSION_RENDERED_AT_KEY, time());
    }

    if ($form->isSubmitted()) {
      $limiter = $this->contactFormLimiter->create($request->getClientIp() ?? 'unknown');

      if (!$limiter->consume(1)->isAccepted()) {
        $this->logger->notice('contact.rejected', ['reason' => 'rate_limit']);
        $this->addFlash('error', 'Trop de tentatives. Merci de réessayer dans quelques minutes.');

        return $this->render('mailer/index.html.twig', [
          'form' => $form->createView(),
          'categories' => $categories,
          'turnstileSiteKey' => $this->turnstileSiteKey,
        ], new Response(null, Response::HTTP_TOO_MANY_REQUESTS));
      }

      $honeypot = $request->request->get('website');
      // A real <input type="text"> never submits as an array; a non-string
      // value here is itself a sign of a crafted (non-browser) submission.
      $honeypotFilled = is_array($honeypot) ? true : $this->spamHeuristics->isHoneypotFilled($honeypot);
      $renderedAt = $session->get(self::SESSION_RENDERED_AT_KEY);
      $session->remove(self::SESSION_RENDERED_AT_KEY);

      if (
        $honeypotFilled
        || $this->spamHeuristics->isSubmittedTooFast($renderedAt)
      ) {
        // High-confidence bot signal: pretend success so the bot has no
        // useful feedback, without ever sending an email.
        $this->logger->notice('contact.rejected', ['reason' => 'bot_signal']);
        $this->addFlash('success', 'Votre message a été envoyé ! Merci de m\'avoir contacté : )');

        return $this->redirectToRoute('contact');
      }

      if ($form->isValid()) {
        $contactFormData = $form->getData();

        if ($this->spamHeuristics->hasTooManyLinks($contactFormData['message'])) {
          $this->logger->notice('contact.rejected', ['reason' => 'too_many_links']);
          $form->addError(new FormError('Votre message contient trop de liens. Merci de le simplifier avant de le renvoyer.'));
        } else {
          $turnstileToken = $request->request->get('cf-turnstile-response');

          if (!$this->turnstileVerifier->verify(is_string($turnstileToken) ? $turnstileToken : null, $request->getClientIp())) {
            $this->logger->notice('contact.rejected', ['reason' => 'turnstile']);
            $this->addFlash('error', 'Nous n\'avons pas pu confirmer que vous n\'êtes pas un robot. Merci de réessayer.');
          } else {
            $email = (new Email())
              ->from('contact@carottecake.com') // your authenticated email address
              ->replyTo($this->stripHeaderInjection($contactFormData['email'])) // user's email address
              ->to('contact@carottecake.com')
              ->subject($this->stripHeaderInjection($contactFormData['sujet']))
              ->text($contactFormData['message']);
            $mailer->send($email);

            $this->addFlash('success', 'Votre message a été envoyé ! Merci de m\'avoir contacté : )');

            return $this->redirectToRoute('contact');
          }
        }
      }
    }

    return $this->render('mailer/index.html.twig', [
      'form' => $form->createView(),
      'categories' => $categories,
      'turnstileSiteKey' => $this->turnstileSiteKey,
    ]);
  }

  /**
   * Defense-in-depth: form constraints already reject CR/LF, but this
   * guarantees no stray control character can ever reach a mail header.
   */
  private function stripHeaderInjection(string $value): string
  {
    return trim(str_replace(["\r", "\n"], '', $value));
  }
}
