<?php

namespace App\Repository;

use App\Entity\WazeFeed;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WazeFeed>
 */
class WazeFeedRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeFeed::class);
    }

    /**
     * @return WazeFeed[]
     */
    public function findActiveFeedsByType(string $feedType): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.type = :type')
            ->andWhere('f.active = :active')
            ->setParameter('type', $feedType)
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return WazeFeed[]
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.active = :active')
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();
    }
}
