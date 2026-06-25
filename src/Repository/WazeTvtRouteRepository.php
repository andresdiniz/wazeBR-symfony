<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WazeTvtRoute;
use App\Entity\WazeTvtSnapshot;
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

    /**
     * Rotas de um snapshot específico, apenas rotas principais (não subRoutes).
     */
    public function findMainRoutesBySnapshot(WazeTvtSnapshot $snapshot): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.snapshot = :snap')
            ->andWhere('r.isSubRoute = false')
            ->setParameter('snap', $snapshot)
            ->orderBy('r.jamLevel', 'DESC')
            ->addOrderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Rotas com congestionamento pesado (jamLevel >= 3) no snapshot mais recente de cada link.
     */
    public function findHeavyJamRoutes(int $minLevel = 3): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.snapshot', 's')
            ->where('r.jamLevel >= :level')
            ->andWhere('r.isSubRoute = false')
            ->setParameter('level', $minLevel)
            ->orderBy('r.jamLevel', 'DESC')
            ->addOrderBy('s.collectedAt', 'DESC')
            ->setMaxResults(100)
            ->getQuery()
            ->getResult();
    }
}
