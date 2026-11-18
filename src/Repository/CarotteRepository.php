<?php

namespace App\Repository;

use App\Entity\Carotte;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Carotte>
 */
class CarotteRepository extends ServiceEntityRepository
{
  public function __construct(ManagerRegistry $registry)
  {
    parent::__construct($registry, Carotte::class);
  }

  /**
   * Les carottes à montrer sur une page : celles qui sont actives et visent une des cibles données
   * (ex. ['partout', 'article:12']). Lecture seule : c'est ce que le site public appelle.
   *
   * $forceeId = une carotte à montrer même si elle est rangée de côté (l'aperçu de l'admin) ;
   * elle doit quand même viser cette page.
   *
   * @param list<string> $cibles
   *
   * @return list<Carotte>
   */
  public function findPourPage(array $cibles, ?int $forceeId = null): array
  {
    $qb = $this->createQueryBuilder('c')
      ->andWhere('c.cible IN (:cibles)')
      ->setParameter('cibles', $cibles)
      ->orderBy('c.id', 'ASC');

    if (null !== $forceeId) {
      $qb->andWhere('c.actif = true OR c.id = :forcee')->setParameter('forcee', $forceeId);
    } else {
      $qb->andWhere('c.actif = true');
    }

    return $qb->getQuery()->getResult();
  }
}
