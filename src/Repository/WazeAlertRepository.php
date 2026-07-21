<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\WazeAlert;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WazeAlert>
 */
class WazeAlertRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeAlert::class);
    }

    // ── Contagens básicas ─────────────────────────────────────────────────────

    public function countByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.partner = :partner')
            ->setParameter('partner', $partner)
            ->getQuery()->getSingleScalarResult();
    }

    /** Alertas publicados nas últimas $hours horas */
    public function countLastHoursByPartner(Partner $partner, int $hours = 1): int
    {
        $since = (new \DateTimeImmutable("-{$hours} hours"))->getTimestamp() * 1000;

        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.partner = :partner')
            ->andWhere('a.pubMillis >= :since')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();
    }

    /** Alertas publicados nos últimos $days dias */
    public function countLast7dByPartner(Partner $partner, int $days = 7): int
    {
        $since = (new \DateTimeImmutable("-{$days} days"))->getTimestamp() * 1000;

        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.partner = :partner')
            ->andWhere('a.pubMillis >= :since')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();
    }

    // ── KPIs de distribuição ──────────────────────────────────────────────────

    /**
     * Contagem por type nas últimas $hours horas.
     * Retorna [['type'=>'ACCIDENT','total'=>12], ...]
     */
    public function countGroupByType(Partner $partner, int $hours = 24): array
    {
        $since = (new \DateTimeImmutable("-{$hours} hours"))->getTimestamp() * 1000;

        $rows = $this->createQueryBuilder('a')
            ->select('a.type AS type, COUNT(a.id) AS total')
            ->where('a.partner = :partner')
            ->andWhere('a.pubMillis >= :since')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->groupBy('a.type')
            ->orderBy('total', 'DESC')
            ->getQuery()->getArrayResult();

        return array_map(static fn($r) => ['type' => $r['type'], 'total' => (int)$r['total']], $rows);
    }

    /**
     * Contagem por subtype nas últimas $hours horas.
     * Retorna [['subtype'=>'ACCIDENT_MINOR','total'=>7], ...]
     */
    public function countGroupBySubtype(Partner $partner, int $limit = 10, int $hours = 24): array
    {
        $since = (new \DateTimeImmutable("-{$hours} hours"))->getTimestamp() * 1000;

        $rows = $this->createQueryBuilder('a')
            ->select('a.subtype AS subtype, COUNT(a.id) AS total')
            ->where('a.partner = :partner')
            ->andWhere('a.pubMillis >= :since')
            ->andWhere('a.subtype IS NOT NULL')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->groupBy('a.subtype')
            ->orderBy('total', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getArrayResult();

        return array_map(static fn($r) => ['subtype' => $r['subtype'], 'total' => (int)$r['total']], $rows);
    }

    /**
     * Tipo dominante nas últimas 24h.
     * Retorna ['type' => 'HAZARD', 'total' => 34] ou null.
     */
    public function dominantTypeToday(Partner $partner): ?array
    {
        $rows = $this->countGroupByType($partner, 24);
        return $rows[0] ?? null;
    }

    /**
     * Top $limit ruas com mais alertas nos últimos $days dias.
     * Retorna [['street'=>'Av. Brasil','total'=>8], ...]
     */
    public function topStreetsByAlert(Partner $partner, int $days = 7, int $limit = 10): array
    {
        $since = (new \DateTimeImmutable("-{$days} days"))->getTimestamp() * 1000;

        $rows = $this->createQueryBuilder('a')
            ->select('a.street AS street, COUNT(a.id) AS total')
            ->where('a.partner = :partner')
            ->andWhere('a.pubMillis >= :since')
            ->andWhere('a.street IS NOT NULL')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->groupBy('a.street')
            ->orderBy('total', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getArrayResult();

        return array_map(static fn($r) => ['street' => $r['street'], 'total' => (int)$r['total']], $rows);
    }

    /**
     * Contagem por cidade nos últimos $days dias, limitado a $limit resultados.
     * Retorna [['city'=>'Juiz de Fora','total'=>22], ...]
     */
    public function countGroupByCity(Partner $partner, int $limit = 10, int $days = 7): array
    {
        $since = (new \DateTimeImmutable("-{$days} days"))->getTimestamp() * 1000;

        $rows = $this->createQueryBuilder('a')
            ->select('a.city AS city, COUNT(a.id) AS total')
            ->where('a.partner = :partner')
            ->andWhere('a.pubMillis >= :since')
            ->andWhere('a.city IS NOT NULL')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->groupBy('a.city')
            ->orderBy('total', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getArrayResult();

        return array_map(static fn($r) => ['city' => $r['city'], 'total' => (int)$r['total']], $rows);
    }

    /**
     * Distribuição de confidence nas últimas 24h.
     * Retorna [['confidence'=>8,'total'=>14], ...]
     */
    public function countByConfidence(Partner $partner, int $hours = 24): array
    {
        $since = (new \DateTimeImmutable("-{$hours} hours"))->getTimestamp() * 1000;

        $rows = $this->createQueryBuilder('a')
            ->select('a.confidence AS confidence, COUNT(a.id) AS total')
            ->where('a.partner = :partner')
            ->andWhere('a.pubMillis >= :since')
            ->andWhere('a.confidence IS NOT NULL')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->groupBy('a.confidence')
            ->orderBy('a.confidence', 'DESC')
            ->getQuery()->getArrayResult();

        return array_map(static fn($r) => ['confidence' => (int)$r['confidence'], 'total' => (int)$r['total']], $rows);
    }

    /**
     * Série temporal: contagem por hora nas últimas 24h.
     * Retorna [['hour'=>'2026-07-21 08','total'=>5], ...]
     */
    public function countPerHourLast24h(Partner $partner): array
    {
        $since = (new \DateTimeImmutable('-24 hours'))->getTimestamp() * 1000;

        $conn = $this->getEntityManager()->getConnection();
        $sql = "
            SELECT DATE_FORMAT(FROM_UNIXTIME(pub_millis / 1000), '%Y-%m-%d %H') AS hour_label,
                   COUNT(*) AS total
            FROM waze_alerts
            WHERE partner_id = :partner
              AND pub_millis >= :since
            GROUP BY hour_label
            ORDER BY hour_label ASC
        ";
        $result = $conn->prepare($sql)->executeQuery([
            'partner' => $partner->getId(),
            'since'   => $since,
        ]);
        return $result->fetchAllAssociative();
    }

    /**
     * Alertas ativos (publicados nas últimas $hours horas).
     */
    public function findActiveByPartner(Partner $partner, int $hours = 1): array
    {
        $since = (new \DateTimeImmutable("-{$hours} hours"))->getTimestamp() * 1000;

        return $this->createQueryBuilder('a')
            ->where('a.partner = :partner')
            ->andWhere('a.pubMillis >= :since')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->orderBy('a.pubMillis', 'DESC')
            ->getQuery()->getResult();
    }

    /**
     * Qualidade média dos relatos (média de confidence + reliability + reportRating)
     * por type nas últimas 24h.
     */
    public function avgQualityByType(Partner $partner, int $hours = 24): array
    {
        $since = (new \DateTimeImmutable("-{$hours} hours"))->getTimestamp() * 1000;

        $rows = $this->createQueryBuilder('a')
            ->select(
                'a.type AS type,'
                . 'AVG(a.confidence) AS avg_confidence,'
                . 'AVG(a.reliability) AS avg_reliability,'
                . 'AVG(a.reportRating) AS avg_rating'
            )
            ->where('a.partner = :partner')
            ->andWhere('a.pubMillis >= :since')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->groupBy('a.type')
            ->orderBy('avg_confidence', 'DESC')
            ->getQuery()->getArrayResult();

        return array_map(static fn($r) => [
            'type'            => $r['type'],
            'avg_confidence'  => round((float)($r['avg_confidence'] ?? 0), 1),
            'avg_reliability' => round((float)($r['avg_reliability'] ?? 0), 1),
            'avg_rating'      => round((float)($r['avg_rating'] ?? 0), 1),
        ], $rows);
    }

    /**
     * Top $limit alertas com maior engajamento (thumbsUp + comentários)
     * nos últimos $days dias.
     */
    public function topEngagedAlerts(Partner $partner, int $days = 7, int $limit = 10): array
    {
        $since = (new \DateTimeImmutable("-{$days} days"))->getTimestamp() * 1000;

        return $this->createQueryBuilder('a')
            ->where('a.partner = :partner')
            ->andWhere('a.pubMillis >= :since')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->orderBy('a.nThumbsUp + a.nComments', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * % de alertas associados a um jam (jamUuid preenchido) nas últimas 24h.
     */
    public function percentLinkedToJam(Partner $partner, int $hours = 24): float
    {
        $since = (new \DateTimeImmutable("-{$hours} hours"))->getTimestamp() * 1000;

        $total = (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.partner = :partner')
            ->andWhere('a.pubMillis >= :since')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();

        if ($total === 0) { return 0.0; }

        $withJam = (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.partner = :partner')
            ->andWhere('a.pubMillis >= :since')
            ->andWhere('a.jamUuid IS NOT NULL')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();

        return round($withJam / $total * 100, 1);
    }

    /**
     * % de alertas em vias expressas (roadType IN (3,4,5)) nas últimas 24h.
     */
    public function percentOnHighways(Partner $partner, int $hours = 24): float
    {
        $since = (new \DateTimeImmutable("-{$hours} hours"))->getTimestamp() * 1000;

        $total = (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.partner = :partner')
            ->andWhere('a.pubMillis >= :since')
            ->andWhere('a.roadType IS NOT NULL')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();

        if ($total === 0) { return 0.0; }

        $highway = (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.partner = :partner')
            ->andWhere('a.pubMillis >= :since')
            ->andWhere('a.roadType IN (3,4,5)')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();

        return round($highway / $total * 100, 1);
    }

    /**
     * @deprecated Use countPerHourLast24h()
     */
    public function hourlySeriesLast24h(Partner $partner): array
    {
        return $this->countPerHourLast24h($partner);
    }

    // ── Listagens ─────────────────────────────────────────────────────────────

    public function findRecentByPartner(Partner $partner, int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('a.pubMillis', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    public function findByPartnerAndType(Partner $partner, string $type, int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.partner = :partner')
            ->andWhere('a.type = :type')
            ->setParameter('partner', $partner)
            ->setParameter('type', $type)
            ->orderBy('a.pubMillis', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }
}
