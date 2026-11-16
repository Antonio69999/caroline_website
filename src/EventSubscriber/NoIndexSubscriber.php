<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Empêche Google de référencer l'administration et la connexion.
 *
 * On passe par un en-tête de réponse (et non par robots.txt) pour deux raisons :
 * robots.txt est public, donc y écrire l'adresse de l'admin la dévoilerait ; et un en-tête
 * couvre toutes les pages d'EasyAdmin sans toucher à leurs gabarits.
 */
class NoIndexSubscriber implements EventSubscriberInterface
{
  private const PRIVATE_PREFIXES = ['/mon_petit_site', '/admin', '/login', '/logout'];

  public static function getSubscribedEvents(): array
  {
    return [KernelEvents::RESPONSE => 'onResponse'];
  }

  public function onResponse(ResponseEvent $event): void
  {
    if (!$event->isMainRequest()) {
      return;
    }

    $path = $event->getRequest()->getPathInfo();

    foreach (self::PRIVATE_PREFIXES as $prefix) {
      if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
        $event->getResponse()->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return;
      }
    }
  }
}
