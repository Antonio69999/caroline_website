<?php

namespace App\Repository;

use App\Entity\Accueil;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Accueil>
 */
class AccueilRepository extends ServiceEntityRepository
{
  public function __construct(ManagerRegistry $registry)
  {
    parent::__construct($registry, Accueil::class);
  }

  /**
   * Les réglages de l'accueil, ou null si Caroline n'a jamais ouvert « Page d'accueil » dans l'admin.
   * Lecture seule : c'est ce que le site public appelle (jamais d'écriture sur une simple visite).
   */
  public function findCurrent(): ?Accueil
  {
    return $this->findOneBy([], ['id' => 'ASC']);
  }

  /**
   * Comme findCurrent(), mais crée la ligne (vide, donc sans effet sur le site) si elle manque.
   * Réservé à l'admin.
   */
  public function findOrCreate(): Accueil
  {
    $accueil = $this->findCurrent();

    if (null === $accueil) {
      $accueil = new Accueil();
      $this->getEntityManager()->persist($accueil);
      $this->getEntityManager()->flush();
    }

    return $accueil;
  }
}
