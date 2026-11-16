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

    /**
     * Les articles publiés d'une catégorie, dans l'ordre de sa page : par année, la plus
     * récente d'abord (MySQL range les articles sans année après, en tri décroissant),
     * puis dans l'ordre choisi à la main dans l'admin pour une même année.
     *
     * @return Article[]
     */
    public function findWithPosition(int $categoryId): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.media', 'm')
            ->addSelect('m')
            ->where('a.categorie = :categoryId')
            ->andWhere('a.publie = true')
            ->setParameter('categoryId', $categoryId)
            ->orderBy('a.annee', 'DESC')
            ->addOrderBy('a.position', 'ASC')
            ->addOrderBy('m.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Tous les articles publiés avec leur catégorie et leurs photos, dans l'ordre du
     * site, en une seule requête : sert au sitemap, qui liste chaque page et chaque image.
     *
     * @return Article[]
     */
    public function findPublishedForSitemap(): array
    {
        return $this->createQueryBuilder('a')
            ->innerJoin('a.categorie', 'c')
            ->addSelect('c')
            ->leftJoin('a.media', 'm')
            ->addSelect('m')
            ->where('a.publie = true')
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('a.annee', 'DESC')
            ->addOrderBy('a.position', 'ASC')
            ->addOrderBy('m.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Articles publiés, dans l'ordre du site (catégorie, année, position), pour
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
            ->addOrderBy('a.annee', 'DESC')
            ->addOrderBy('a.position', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
