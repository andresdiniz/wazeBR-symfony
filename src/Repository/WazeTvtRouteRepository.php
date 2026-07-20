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

    // ── KPIs ──────────────────────────────────────────────────────────────────

    /**
     * Velocidade média (km/h) das rotas principais do snapshot mais recente.
     * Retorna 0.0 quando não há snapshot.
     */
    public function avgSpeedByPartner(Partner $partner): float
    {
        $latestId = $this->latestSnapshotId($partner);
        if (!$latestId) { return 0.0; }

        $val = $this->createQueryBuilder('r')
            ->select('AVG(r.speed)')
            ->where('r.snapshot = :snap')
            ->andWhere('r.isSubRoute = false')
            ->andWhere('r.speed IS NOT NULL')
            ->setParameter('snap', $latestId)
            ->getQuery()->getSingleScalarResult();

        return round((float)($val ?? 0), 1);
    }

    /**
     * Tempo de viagem médio (segundos) das rotas principais do snapshot mais recente.
     * Retorna 0.0 quando não há snapshot.
     */
    public function avgTravelTimeByPartner(Partner $partner): float
    {
        $latestId = $this->latestSnapshotId($partner);
        if (!$latestId) { return 0.0; }

        $val = $this->createQueryBuilder('r')
            ->select('AVG(r.travelTime)')
            ->where('r.snapshot = :snap')
            ->andWhere('r.isSubRoute = false')
            ->andWhere('r.travelTime IS NOT NULL')
            ->setParameter('snap', $latestId)
            ->getQuery()->getSingleScalarResult();

        return round((float)($val ?? 0));
    }

    /**
     * Contagem de rotas por jamLevel no snapshot mais recente.
     * Retorna [['jam_level' => 3, 'total' => 4], ...]
     */
    public function countGroupByJamLevel(Partner $partner): array
    {
        $latestId = $this->latestSnapshotId($partner);
        if (!$latestId) { return []; }

        $rows = $this->createQueryBuilder('r')
            ->select('r.jamLevel AS jam_level, COUNT(r.id) AS total')
            ->where('r.snapshot = :snap')
            ->andWhere('r.isSubRoute = false')
            ->setParameter('snap', $latestId)
            ->groupBy('r.jamLevel')
            ->orderBy('r.jamLevel', 'DESC')
            ->getQuery()->getArrayResult();

        return array_map(static fn(array $r) => [
            'jam_level' => (int) $r['jam_level'],
            'total'     => (int) $r['total'],
        ], $rows);
    }

    // ── Listagens ─────────────────────────────────────────────────────────────

    /** Rotas do snapshot mais recente, filtráveis por jamLevel */
    public function findTvtByPartner(Partner $partner, ?int $jamLevel = null): array
    {
        $latestId = $this->latestSnapshotId($partner);
        if (!$latestId) { return []; }

        $qb = $this->createQueryBuilder('r')
            ->where('r.snapshot = :snapId')
            ->andWhere('r.isSubRoute = false')
            ->setParameter('snapId', $latestId)
            ->orderBy('r.jamLevel', 'DESC')
            ->addOrderBy('r.name', 'ASC');

        if ($jamLevel !== null) {
            $qb->andWhere('r.jamLevel = :jl')->setParameter('jl', $jamLevel);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Série histórica de uma rota TVT específica (por wazeRouteId),
     * do mais recente para o mais antigo, limitada a $limit registros.
     */
    public function findHistoryByWazeId(Partner $partner, string $wazeRouteId, int $limit = 100): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.snapshot', 's')
            ->where('s.partner = :partner')
            ->andWhere('r.wazeRouteId = :wid')
            ->andWhere('r.isSubRoute = false')
            ->setParameter('partner', $partner)
            ->setParameter('wid', $wazeRouteId)
            ->orderBy('s.collectedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

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

    // ── Helper privado ────────────────────────────────────────────────────────

    private function latestSnapshotId(Partner $partner): ?int
    {
        $id = $this->getEntityManager()->createQueryBuilder()
            ->select('MAX(s.id)')
            ->from(WazeTvtSnapshot::class, 's')
            ->where('s.partner = :partner')
            ->setParameter('partner', $partner)
            ->getQuery()->getSingleScalarResult();

        return $id ? (int) $id : null;
    }
}
