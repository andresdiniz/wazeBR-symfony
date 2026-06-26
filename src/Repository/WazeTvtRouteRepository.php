<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
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
     * Rotas principais do snapshot mais recente do parceiro.
     */
    public function findRecentByPartner(Partner $partner, int $limit = 20): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.snapshot', 's')
            ->join('s.monitoredLink', 'ml')
            ->where('ml.partner = :partner')
            ->andWhere('ml.isActive = true')
            ->andWhere('r.isSubRoute = false')
            ->setParameter('partner', $partner)
            ->orderBy('s.collectedAt', 'DESC')
            ->addOrderBy('r.jamLevel', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Conta rotas principais (não subRoutes) dos links ativos do parceiro.
     */
    public function countByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(DISTINCT r.routeId)')
            ->join('r.snapshot', 's')
            ->join('s.monitoredLink', 'ml')
            ->where('ml.partner = :partner')
            ->andWhere('ml.isActive = true')
            ->andWhere('r.isSubRoute = false')
            ->setParameter('partner', $partner)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Rotas de um snapshot específico, apenas rotas principais.
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
     * Rotas com congestionamento pesado (jamLevel >= minLevel).
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
