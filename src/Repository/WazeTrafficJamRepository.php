<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\WazeTrafficJam;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WazeTrafficJam>
 */
class WazeTrafficJamRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeTrafficJam::class);
    }

    // ── Contagens básicas ────────────────────────────────────────────────────

    public function countByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.partner = :partner')
            ->setParameter('partner', $partner)
            ->getQuery()->getSingleScalarResult();
    }

    public function countLast24hByPartner(Partner $partner): int
    {
        $since = (new \DateTimeImmutable('-24 hours'))->getTimestamp() * 1000;

        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.partner = :partner')
            ->andWhere('j.pubMillis >= :since')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();
    }

    public function countLast7dByPartner(Partner $partner, int $days = 7): int
    {
        $since = (new \DateTimeImmutable("-{$days} days"))->getTimestamp() * 1000;

        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.partner = :partner')
            ->andWhere('j.pubMillis >= :since')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();
    }

    // ── KPIs de velocidade / atraso / extensão (histórico total) ─────────────

    public function avgSpeedKmhByPartner(Partner $partner): float
    {
        $val = $this->createQueryBuilder('j')
            ->select('AVG(j.speedKmh)')
            ->where('j.partner = :partner')
            ->andWhere('j.speedKmh IS NOT NULL')
            ->setParameter('partner', $partner)
            ->getQuery()->getSingleScalarResult();

        return round((float)($val ?? 0), 1);
    }

    public function avgDelaySecsByPartner(Partner $partner): float
    {
        $val = $this->createQueryBuilder('j')
            ->select('AVG(j.delay)')
            ->where('j.partner = :partner')
            ->andWhere('j.delay IS NOT NULL')
            ->setParameter('partner', $partner)
            ->getQuery()->getSingleScalarResult();

        return round((float)($val ?? 0));
    }

    public function totalLengthMByPartner(Partner $partner): float
    {
        $val = $this->createQueryBuilder('j')
            ->select('SUM(j.length)')
            ->where('j.partner = :partner')
            ->setParameter('partner', $partner)
            ->getQuery()->getSingleScalarResult();

        return (float)($val ?? 0);
    }

    // ── KPIs ao vivo ─────────────────────────────────────────────────────────

    public function avgStats(Partner $partner, int $hours = 3): array
    {
        $since = (new \DateTimeImmutable("-{$hours} hours"))->getTimestamp() * 1000;

        $row = $this->createQueryBuilder('j')
            ->select(
                'AVG(j.speedKmh) AS avgSpeed,'
                . 'AVG(j.delay) AS avgDelay,'
                . 'SUM(j.length) AS totalLength'
            )
            ->where('j.partner = :partner')
            ->andWhere('j.pubMillis >= :since')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->getQuery()->getOneOrNullResult();

        return [
            'avgSpeed'    => round((float)($row['avgSpeed']    ?? 0), 1),
            'avgDelay'    => round((float)($row['avgDelay']    ?? 0)),
            'totalLength' => (float)($row['totalLength'] ?? 0),
        ];
    }

    public function findLiveByPartner(Partner $partner, int $hours = 3): array
    {
        $since = (new \DateTimeImmutable("-{$hours} hours"))->getTimestamp() * 1000;

        return $this->createQueryBuilder('j')
            ->where('j.partner = :partner')
            ->andWhere('j.pubMillis >= :since')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->orderBy('j.delay', 'DESC')
            ->getQuery()->getResult();
    }

    // ── KPIs de impacto ───────────────────────────────────────────────────────

    public function totalActiveLengthKm(Partner $partner, int $minutes = 10): float
    {
        $since = (new \DateTimeImmutable("-{$minutes} minutes"))->getTimestamp() * 1000;

        $val = $this->createQueryBuilder('j')
            ->select('SUM(j.length)')
            ->where('j.partner = :partner')
            ->andWhere('j.pubMillis >= :since')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();

        return round((float)($val ?? 0) / 1000, 2);
    }

    public function worstActiveJamByPartner(Partner $partner, int $minutes = 10): ?WazeTrafficJam
    {
        $since = (new \DateTimeImmutable("-{$minutes} minutes"))->getTimestamp() * 1000;

        return $this->createQueryBuilder('j')
            ->where('j.partner = :partner')
            ->andWhere('j.pubMillis >= :since')
            ->andWhere('j.delay IS NOT NULL')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->orderBy('j.delay', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    public function avgActiveSpeedKmh(Partner $partner, int $minutes = 10): float
    {
        $since = (new \DateTimeImmutable("-{$minutes} minutes"))->getTimestamp() * 1000;

        $val = $this->createQueryBuilder('j')
            ->select('AVG(j.speedKmh)')
            ->where('j.partner = :partner')
            ->andWhere('j.pubMillis >= :since')
            ->andWhere('j.speedKmh IS NOT NULL')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();

        return round((float)($val ?? 0), 1);
    }

    public function avgActiveDelay(Partner $partner, int $minutes = 10): float
    {
        $since = (new \DateTimeImmutable("-{$minutes} minutes"))->getTimestamp() * 1000;

        $val = $this->createQueryBuilder('j')
            ->select('AVG(j.delay)')
            ->where('j.partner = :partner')
            ->andWhere('j.pubMillis >= :since')
            ->andWhere('j.delay IS NOT NULL')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();

        return round((float)($val ?? 0));
    }

    // ── KPIs de distribuição ───────────────────────────────────────────────────

    public function countGroupByLevel(Partner $partner, int $days = 7): array
    {
        $since = (new \DateTimeImmutable("-{$days} days"))->getTimestamp() * 1000;

        $rows = $this->createQueryBuilder('j')
            ->select('j.level AS level, COUNT(j.id) AS total')
            ->where('j.partner = :partner')
            ->andWhere('j.pubMillis >= :since')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->groupBy('j.level')
            ->orderBy('j.level', 'DESC')
            ->getQuery()->getArrayResult();

        return array_map(static fn($r) => ['level' => (int)$r['level'], 'total' => (int)$r['total']], $rows);
    }

    public function levelBreakdownByPartner(Partner $partner, int $days = 7): array
    {
        return $this->countGroupByLevel($partner, $days);
    }

    public function countGroupByCity(Partner $partner, int $days = 7): array
    {
        $since = (new \DateTimeImmutable("-{$days} days"))->getTimestamp() * 1000;

        $rows = $this->createQueryBuilder('j')
            ->select('j.city AS city, COUNT(j.id) AS total')
            ->where('j.partner = :partner')
            ->andWhere('j.pubMillis >= :since')
            ->andWhere('j.city IS NOT NULL')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->groupBy('j.city')
            ->orderBy('total', 'DESC')
            ->getQuery()->getArrayResult();

        return array_map(static fn($r) => ['city' => $r['city'], 'total' => (int)$r['total']], $rows);
    }

    /**
     * Série temporal: contagem por hora nas últimas 24h.
     * Usa conn->executeQuery() — correto para DBAL moderno.
     */
    public function countPerHourLast24h(Partner $partner): array
    {
        $since = (new \DateTimeImmutable('-24 hours'))->getTimestamp() * 1000;

        $sql = "
            SELECT DATE_FORMAT(FROM_UNIXTIME(pub_millis / 1000), '%Y-%m-%d %H') AS hour_label,
                   COUNT(*) AS total
            FROM waze_traffic_jams
            WHERE partner_id = :partner
              AND pub_millis >= :since
            GROUP BY hour_label
            ORDER BY hour_label ASC
        ";

        return $this->getEntityManager()
            ->getConnection()
            ->executeQuery($sql, ['partner' => $partner->getId(), 'since' => $since])
            ->fetchAllAssociative();
    }

    /**
     * Série temporal: atraso médio por hora nas últimas 24h.
     * Usa conn->executeQuery() — correto para DBAL moderno.
     */
    public function hourlyDelaySeries(Partner $partner): array
    {
        $since = (new \DateTimeImmutable('-24 hours'))->getTimestamp() * 1000;

        $sql = "
            SELECT DATE_FORMAT(FROM_UNIXTIME(pub_millis / 1000), '%Y-%m-%d %H') AS hour_label,
                   ROUND(AVG(delay)) AS avg_delay,
                   ROUND(AVG(speed_kmh), 1) AS avg_speed
            FROM waze_traffic_jams
            WHERE partner_id = :partner
              AND pub_millis >= :since
              AND delay IS NOT NULL
            GROUP BY hour_label
            ORDER BY hour_label ASC
        ";

        return $this->getEntityManager()
            ->getConnection()
            ->executeQuery($sql, ['partner' => $partner->getId(), 'since' => $since])
            ->fetchAllAssociative();
    }

    public function topStreetsByJam(Partner $partner, int $days = 30, int $limit = 10): array
    {
        $since = (new \DateTimeImmutable("-{$days} days"))->getTimestamp() * 1000;

        $rows = $this->createQueryBuilder('j')
            ->select('j.street AS street, COUNT(j.id) AS total, AVG(j.delay) AS avg_delay')
            ->where('j.partner = :partner')
            ->andWhere('j.pubMillis >= :since')
            ->andWhere('j.street IS NOT NULL')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->groupBy('j.street')
            ->orderBy('total', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getArrayResult();

        return array_map(static fn($r) => [
            'street'    => $r['street'],
            'total'     => (int)$r['total'],
            'avg_delay' => round((float)($r['avg_delay'] ?? 0)),
        ], $rows);
    }

    // ── Listagens ─────────────────────────────────────────────────────────────

    public function findActiveByPartner(Partner $partner, int $minutes = 10): array
    {
        $since = (new \DateTimeImmutable("-{$minutes} minutes"))->getTimestamp() * 1000;

        return $this->createQueryBuilder('j')
            ->where('j.partner = :partner')
            ->andWhere('j.pubMillis >= :since')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->orderBy('j.delay', 'DESC')
            ->getQuery()->getResult();
    }

    public function findRecentByPartner(Partner $partner, int $limit = 50): array
    {
        return $this->createQueryBuilder('j')
            ->where('j.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('j.pubMillis', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }
}
