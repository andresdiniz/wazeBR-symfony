<?php

namespace App\Repository;

use App\Entity\WazeTvtRoute;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WazeTvtRoute>
 */
class WazeTvtRouteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeTvtRoute::class);
    }

    public function findActiveByPartner(int $partnerId): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.partner = :partner')
            ->andWhere('r.isActive = true')
            ->setParameter('partner', $partnerId)
            ->orderBy('r.lastSeenAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
