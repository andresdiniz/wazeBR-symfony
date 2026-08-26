<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\WazeTvtRoute;
use App\Entity\WazeTvtSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WazeTvtRoute>
 */
class WazeTvtRouteRepository extends ServiceEntityRepository
{
    private const TZ = 'America/Sao_Paulo';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeTvtRoute::class);
    }

    // ─── Contagens ─────────────────────────────────────────────────────────────

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

    public function countInPeriod(Partner $partner, \DateTimeInterface $from, \DateTimeInterface $to): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(DISTINCT r.wazeRouteId)')
            ->join('r.snapshot', 's')
            ->where('s.partner = :partner')
            ->andWhere('r.isSubRoute = false')
            ->andWhere('s.collectedAt BETWEEN :from AND :to')
            ->setParameter('partner', $partner)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();
    }

    // ─── KPIs ──────────────────────────────────────────────────────────────────

    public function avgSpeedByPartner(Partner $partner): float
    {
        $latestId = $this->latestSnapshotId($partner);
        if (!$latestId) {
            return 0.0;
        }

        $rows = $this->createQueryBuilder('r')
            ->select('r.length AS length_m, r.time AS time_s')
            ->where('r.snapshot = :snap')
            ->andWhere('r.isSubRoute = false')
            ->andWhere('r.length IS NOT NULL')
            ->andWhere('r.time IS NOT NULL')
            ->andWhere('r.time > 0')
            ->setParameter('snap', $latestId)
            ->getQuery()
            ->getArrayResult();

        if (empty($rows)) {
            return 0.0;
        }

        $totalSpeed = 0.0;
        $count = 0;
        foreach ($rows as $row) {
            $speedKmh = ((float)$row['length_m'] / (float)$row['time_s']) * 3.6;
            $totalSpeed += $speedKmh;
            $count++;
        }

        return $count > 0 ? round($totalSpeed / $count, 1) : 0.0;
    }

    public function avgTravelTimeByPartner(Partner $partner): float
    {
        $latestId = $this->latestSnapshotId($partner);
        if (!$latestId) {
            return 0.0;
        }

        $val = $this->createQueryBuilder('r')
            ->select('AVG(r.time)')
            ->where('r.snapshot = :snap')
            ->andWhere('r.isSubRoute = false')
            ->andWhere('r.time IS NOT NULL')
            ->setParameter('snap', $latestId)
            ->getQuery()
            ->getSingleScalarResult();

        return round((float)($val ?? 0));
    }

    public function avgDelayByPartner(Partner $partner): float
    {
        $latestId = $this->latestSnapshotId($partner);
        if (!$latestId) {
            return 0.0;
        }

        $val = $this->createQueryBuilder('r')
            ->select('AVG(r.time - r.historicTime)')
            ->where('r.snapshot = :snap')
            ->andWhere('r.isSubRoute = false')
            ->andWhere('r.time IS NOT NULL')
            ->andWhere('r.historicTime IS NOT NULL')
            ->andWhere('r.historicTime > 0')
            ->setParameter('snap', $latestId)
            ->getQuery()
            ->getSingleScalarResult();

        return round((float)($val ?? 0));
    }

    public function countGroupByJamLevel(Partner $partner): array
    {
        $latestId = $this->latestSnapshotId($partner);
        if (!$latestId) {
            return [];
        }

        $rows = $this->createQueryBuilder('r')
            ->select('r.jamLevel AS jam_level, COUNT(r.id) AS total')
            ->where('r.snapshot = :snap')
            ->andWhere('r.isSubRoute = false')
            ->setParameter('snap', $latestId)
            ->groupBy('r.jamLevel')
            ->orderBy('r.jamLevel', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn(array $r) => [
            'jam_level' => (int) $r['jam_level'],
            'total'     => (int) $r['total'],
        ], $rows);
    }

    // ─── Listagens ─────────────────────────────────────────────────────────────

    public function findTvtByPartner(Partner $partner, ?int $jamLevel = null): array
    {
        $latestId = $this->latestSnapshotId($partner);
        if (!$latestId) {
            return [];
        }

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

    public function findHistoryByWazeIdFiltered(Partner $partner, string $wazeRouteId, array $filters = [], int $limit = 300): array
    {
        $qb = $this->createQueryBuilder('r')
            ->join('r.snapshot', 's')
            ->where('s.partner = :partner')
            ->andWhere('r.wazeRouteId = :wid')
            ->andWhere('r.isSubRoute = false')
            ->setParameter('partner', $partner)
            ->setParameter('wid', $wazeRouteId)
            ->orderBy('s.collectedAt', 'DESC')
            ->setMaxResults(max(1, $limit));

        $this->applyDateAndJamFilters($qb, $filters);

        return $qb->getQuery()->getResult();
    }

    public function countHistoryFiltered(Partner $partner, string $wazeRouteId, array $filters = []): int
    {
        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->join('r.snapshot', 's')
            ->where('s.partner = :partner')
            ->andWhere('r.wazeRouteId = :wid')
            ->andWhere('r.isSubRoute = false')
            ->setParameter('partner', $partner)
            ->setParameter('wid', $wazeRouteId);

        $this->applyDateAndJamFilters($qb, $filters);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function countByJamLevelForRoute(Partner $partner, string $wazeRouteId, array $filters = []): array
    {
        $qb = $this->createQueryBuilder('r')
            ->select('r.jamLevel AS jam_level, COUNT(r.id) AS total')
            ->join('r.snapshot', 's')
            ->where('s.partner = :partner')
            ->andWhere('r.wazeRouteId = :wid')
            ->andWhere('r.isSubRoute = false')
            ->setParameter('partner', $partner)
            ->setParameter('wid', $wazeRouteId)
            ->groupBy('r.jamLevel');

        $this->applyDateAndJamFilters($qb, $filters, includeMinJam: false);

        $rows = $qb->getQuery()->getArrayResult();

        $byLevel = array_fill(0, 6, 0);
        foreach ($rows as $r) {
            $lv = (int) $r['jam_level'];
            if ($lv >= 0 && $lv <= 5) {
                $byLevel[$lv] = (int) $r['total'];
            }
        }

        return $byLevel;
    }

    public function weekdayHourProfile(Partner $partner, string $wazeRouteId, array $filters = []): array
    {
        $where  = [
            's.partner_id = :partnerId',
            'r.waze_route_id = :wazeRouteId',
            'r.is_sub_route = 0',
            'r.time IS NOT NULL',
            'r.historic_time IS NOT NULL',
        ];
        $params = ['partnerId' => $partner->getId(), 'wazeRouteId' => $wazeRouteId];

        [$from, $to] = self::dateBoundsFromFilters($filters);
        if ($from) {
            $where[] = 's.collected_at >= :dtFrom';
            $params['dtFrom'] = $from->format('Y-m-d H:i:s');
        }
        if ($to) {
            $where[] = 's.collected_at <= :dtTo';
            $params['dtTo'] = $to->format('Y-m-d H:i:s');
        }
        if (!empty($filters['minJam'])) {
            $where[] = 'r.jam_level >= :minJam';
            $params['minJam'] = (int) $filters['minJam'];
        }

        $whereSql = implode(' AND ', $where);

        $sql = <<<SQL
            SELECT
                DAYOFWEEK(CONVERT_TZ(s.collected_at, '+00:00', '-03:00')) AS wd,
                FLOOR(HOUR(CONVERT_TZ(s.collected_at, '+00:00', '-03:00')) / 2) * 2 AS bucket,
                AVG(r.time)                   AS avg_time,
                AVG(r.historic_time)          AS avg_hist,
                AVG(r.time - r.historic_time) AS avg_delay,
                AVG(r.jam_level)              AS avg_jam,
                COUNT(*)                      AS total
            FROM waze_tvt_routes r
            INNER JOIN waze_tvt_snapshots s ON s.id = r.snapshot_id
            WHERE {$whereSql}
            GROUP BY wd, bucket
        SQL;

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, $params);

        return array_map(static fn(array $r) => [
            'wd'       => (int) $r['wd'],
            'bucket'   => (int) $r['bucket'],
            'avgTime'  => (float) $r['avg_time'],
            'avgHist'  => (float) $r['avg_hist'],
            'avgDelay' => (float) $r['avg_delay'],
            'avgJam'   => (float) $r['avg_jam'],
            'total'    => (int) $r['total'],
        ], $rows);
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
        $limit = max(1, min($limit, 100));

        $snapshot = $this->getEntityManager()
            ->getRepository(WazeTvtSnapshot::class)
            ->createQueryBuilder('s')
            ->where('s.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('s.collectedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($snapshot === null) {
            return [];
        }

        return $this->createQueryBuilder('r')
            ->where('r.snapshot = :snapshot')
            ->andWhere('r.isSubRoute = false')
            ->setParameter('snapshot', $snapshot)
            ->orderBy('r.jamLevel', 'DESC')
            ->addOrderBy('r.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findLatestByWazeId(Partner $partner, string $wazeRouteId): ?WazeTvtRoute
    {
        $rows = $this->createQueryBuilder('r')
            ->join('r.snapshot', 's')
            ->where('s.partner = :partner')
            ->andWhere('r.wazeRouteId = :wid')
            ->andWhere('r.isSubRoute = false')
            ->setParameter('partner', $partner)
            ->setParameter('wid', $wazeRouteId)
            ->orderBy('s.collectedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        return $rows[0] ?? null;
    }

    // ─── CORRIGIDO: findOneByPartner ─────────────────────────────────────────

    /**
     * Busca uma rota pelo ID e parceiro.
     * Como a entidade WazeTvtRoute NÃO tem campo 'partner' diretamente,
     * usamos o relacionamento via snapshot: r.snapshot.partner.
     *
     * Este método é usado em RouteAdminController::show() e toggle().
     */
    public function findOneByPartner(int $id, Partner $partner): ?WazeTvtRoute
    {
        return $this->createQueryBuilder('r')
            ->join('r.snapshot', 's')
            ->where('r.id = :id')
            ->andWhere('s.partner = :partner')
            ->setParameter('id', $id)
            ->setParameter('partner', $partner)
            ->getQuery()
            ->getOneOrNullResult();
    }

    // ─── Helpers privados ─────────────────────────────────────────────────────

    private function latestSnapshotId(Partner $partner): ?int
    {
        $id = $this->getEntityManager()->createQueryBuilder()
            ->select('MAX(s.id)')
            ->from(WazeTvtSnapshot::class, 's')
            ->where('s.partner = :partner')
            ->setParameter('partner', $partner)
            ->getQuery()
            ->getSingleScalarResult();

        return $id ? (int) $id : null;
    }

    private function applyDateAndJamFilters(\Doctrine\ORM\QueryBuilder $qb, array $filters, bool $includeMinJam = true): void
    {
        [$from, $to] = self::dateBoundsFromFilters($filters);

        if ($from) {
            $qb->andWhere('s.collectedAt >= :dtFrom')
               ->setParameter('dtFrom', $from, Types::DATETIME_IMMUTABLE);
        }
        if ($to) {
            $qb->andWhere('s.collectedAt <= :dtTo')
               ->setParameter('dtTo', $to, Types::DATETIME_IMMUTABLE);
        }
        if ($includeMinJam && !empty($filters['minJam'])) {
            $qb->andWhere('r.jamLevel >= :minJam')
               ->setParameter('minJam', (int) $filters['minJam']);
        }
    }

    private static function dateBoundsFromFilters(array $filters): array
    {
        $tz = new \DateTimeZone(self::TZ);
        $from = null;
        $to   = null;

        if (!empty($filters['dateFrom'])) {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $filters['dateFrom'], $tz);
            if ($d) {
                $from = new \DateTimeImmutable('@' . $d->getTimestamp());
            }
        }
        if (!empty($filters['dateTo'])) {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $filters['dateTo'], $tz);
            if ($d) {
                $to = new \DateTimeImmutable('@' . $d->setTime(23, 59, 59)->getTimestamp());
            }
        }

        return [$from, $to];
    }
}
