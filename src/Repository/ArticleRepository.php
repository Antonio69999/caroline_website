<?php

namespace App\Repository;

use App\Entity\Article;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Article>
 *
 * @method Article|null find($id, $lockMode = null, $lockVersion = null)
 * @method Article|null findOneBy(array $criteria, array $orderBy = null)
 * @method Article[]    findAll()
 * @method Article[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Article::class);
    }

    public function findWithPosition(int $categoryId)
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.media', 'm')
            ->addSelect('m')
            ->where('a.categorie = :categoryId')
            ->andWhere('a.publie = true')
            ->setParameter('categoryId', $categoryId)
            ->orderBy('a.position', 'ASC')
            ->addOrderBy('m.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Articles publiés, dans l'ordre du site (catégorie puis position), pour
     * le sélecteur de liens de l'éditeur de texte de l'admin : un brouillon
     * n'est pas proposé, son lien mènerait à une page vide.
     *
     * @return Article[]
     */
    public function findPublishedForLinks(): array
    {
        return $this->createQueryBuilder('a')
            ->innerJoin('a.categorie', 'c')
            ->addSelect('c')
            ->where('a.publie = true')
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('a.position', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
