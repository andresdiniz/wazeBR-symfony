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

    // ── Contagens básicas ─────────────────────────────────────────────────────

    public function countByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.partner = :partner')
            ->setParameter('partner', $partner)
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

    // ── KPIs de impacto ───────────────────────────────────────────────────────

    /**
     * Extensão total de congestionamento ativo agora (metros → km).
     * "Ativo" = jams coletados nas últimas $minutes minutos.
     */
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

    /**
     * Pior jam ativo: o com maior delay.
     * Retorna a entity WazeTrafficJam ou null.
     */
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

    /**
     * Velocidade média (km/h) de todos os jams ativos agora.
     * Termômetro de fluidez urbana.
     */
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

    /**
     * Atraso médio (segundos) dos jams ativos agora.
     */
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

    // ── KPIs de distribuição ──────────────────────────────────────────────────

    /**
     * Distribuição por level (0-5) nos últimos $days dias.
     * Retorna [['level'=>3,'total'=>7], ...]
     */
    public function levelBreakdownByPartner(Partner $partner, int $days = 7): array
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

    /**
     * Contagem por cidade nos últimos $days dias.
     */
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
     * Top $limit ruas com mais jams recorrentes nos últimos $days dias.
     */
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

    /**
     * Série temporal: atraso médio agrupado por hora nas últimas 24h (SQL nativo).
     * Retorna [['hour_label'=>'2026-07-20 08','avg_delay'=>120], ...]
     */
    public function hourlyDelaySeries(Partner $partner): array
    {
        $since = (new \DateTimeImmutable('-24 hours'))->getTimestamp() * 1000;

        $conn = $this->getEntityManager()->getConnection();
        $sql = '
            SELECT DATE_FORMAT(FROM_UNIXTIME(pub_millis / 1000), \'%Y-%m-%d %H\') AS hour_label,
                   ROUND(AVG(delay)) AS avg_delay,
                   ROUND(AVG(speed_kmh), 1) AS avg_speed
            FROM waze_traffic_jams
            WHERE partner_id = :partner
              AND pub_millis >= :since
              AND delay IS NOT NULL
            GROUP BY hour_label
            ORDER BY hour_label ASC
        ';
        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery(['partner' => $partner->getId(), 'since' => $since]);
        return $result->fetchAllAssociative();
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
