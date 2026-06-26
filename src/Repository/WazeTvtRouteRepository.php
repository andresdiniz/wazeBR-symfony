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
     * Conta wazeRouteIds únicos das rotas principais do parceiro.
     */
    public function countByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(DISTINCT r.wazeRouteId)')
            ->join('r.snapshot', 's')
            ->where('s.partner = :partner')
            ->andWhere('r.isSubRoute = false')
            ->setParameter('partner', $partner)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Rotas principais do snapshot mais recente do parceiro.
     * Filtra opcionalmente por jamLevel exato.
     */
    public function findTvtByPartner(Partner $partner, ?int $jamLevel = null): array
    {
        // Busca o snapshot mais recente do parceiro
        $latestSnapshotId = $this->getEntityManager()->createQueryBuilder()
            ->select('MAX(ls.id)')
            ->from(WazeTvtSnapshot::class, 'ls')
            ->where('ls.partner = :partner')
            ->getQuery()
            ->setParameter('partner', $partner)
            ->getSingleScalarResult();

        if (!$latestSnapshotId) {
            return [];
        }

        $qb = $this->createQueryBuilder('r')
            ->where('r.snapshot = :snapId')
            ->andWhere('r.isSubRoute = false')
            ->setParameter('snapId', $latestSnapshotId)
            ->orderBy('r.jamLevel', 'DESC')
            ->addOrderBy('r.name', 'ASC');

        if ($jamLevel !== null) {
            $qb->andWhere('r.jamLevel = :jl')->setParameter('jl', $jamLevel);
        }

        return $qb->getQuery()->getResult();
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

    /**
     * Rotas principais dos snapshots mais recentes do parceiro (múltiplos snapshots).
     */
    public function findRecentByPartner(Partner $partner, int $limit = 20): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.snapshot', 's')
            ->where('s.partner = :partner')
            ->andWhere('r.isSubRoute = false')
            ->setParameter('partner', $partner)
            ->orderBy('s.collectedAt', 'DESC')
            ->addOrderBy('r.jamLevel', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
