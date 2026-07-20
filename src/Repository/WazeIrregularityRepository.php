<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\WazeIrregularity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WazeIrregularity>
 */
class WazeIrregularityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeIrregularity::class);
    }

    // ── Contagens básicas ─────────────────────────────────────────────────────

    public function countByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.partner = :partner')
            ->setParameter('partner', $partner)
            ->getQuery()->getSingleScalarResult();
    }

    // ── KPIs ──────────────────────────────────────────────────────────────────

    /**
     * Irregularidades piorando agora (trend = worsening).
     * Retorna lista de entities ordenadas por severidade.
     */
    public function findWorseningByPartner(Partner $partner, int $limit = 20): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.partner = :partner')
            ->andWhere('i.trend = :trend')
            ->setParameter('partner', $partner)
            ->setParameter('trend', 'worsening')
            ->orderBy('i.severity', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * Índice de degradação por rua: (regularSpeed - speed) / regularSpeed * 100.
     * Top $limit vias com maior perda de velocidade nas últimas $hours horas.
     * Retorna [['street'=>'...','loss_pct'=>38.5,'severity'=>7], ...]
     */
    public function speedLossRankingByStreet(Partner $partner, int $hours = 24, int $limit = 10): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $since = (new \DateTimeImmutable("-{$hours} hours"))->format('Y-m-d H:i:s');

        $sql = '
            SELECT street,
                   ROUND(AVG((regular_speed - speed) / NULLIF(regular_speed, 0) * 100), 1) AS loss_pct,
                   ROUND(AVG(severity), 1) AS avg_severity
            FROM waze_irregularities
            WHERE partner_id = :partner
              AND detection_date >= :since
              AND street IS NOT NULL
              AND regular_speed > 0
            GROUP BY street
            ORDER BY loss_pct DESC
            LIMIT :lim
        ';
        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery([
            'partner' => $partner->getId(),
            'since'   => $since,
            'lim'     => $limit,
        ]);
        return $result->fetchAllAssociative();
    }

    /**
     * Atraso acumulado (segundos) por rua nas últimas $hours horas.
     * delay = seconds - regularSeconds por registro.
     * Retorna [['street'=>'...','total_delay_s'=>840,'occurrences'=>6], ...]
     */
    public function accumulatedDelayByStreet(Partner $partner, int $hours = 24, int $limit = 10): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $since = (new \DateTimeImmutable("-{$hours} hours"))->format('Y-m-d H:i:s');

        $sql = '
            SELECT street,
                   SUM(seconds - regular_seconds) AS total_delay_s,
                   COUNT(*) AS occurrences
            FROM waze_irregularities
            WHERE partner_id = :partner
              AND detection_date >= :since
              AND street IS NOT NULL
              AND regular_seconds IS NOT NULL
            GROUP BY street
            ORDER BY total_delay_s DESC
            LIMIT :lim
        ';
        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery([
            'partner' => $partner->getId(),
            'since'   => $since,
            'lim'     => $limit,
        ]);
        return $result->fetchAllAssociative();
    }

    /**
     * Severidade média por cidade.
     * Retorna [['city'=>'...','avg_severity'=>6.2], ...]
     */
    public function avgSeverityByCity(Partner $partner, int $days = 7): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $since = (new \DateTimeImmutable("-{$days} days"))->format('Y-m-d H:i:s');

        $sql = '
            SELECT city,
                   ROUND(AVG(severity), 1) AS avg_severity,
                   COUNT(*) AS total
            FROM waze_irregularities
            WHERE partner_id = :partner
              AND detection_date >= :since
              AND city IS NOT NULL
            GROUP BY city
            ORDER BY avg_severity DESC
        ';
        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery([
            'partner' => $partner->getId(),
            'since'   => $since,
        ]);
        return $result->fetchAllAssociative();
    }

    /**
     * Distribuição por tipo de via (highway true/false).
     * Retorna [['highway'=>true,'total'=>12,'avg_severity'=>7], ...]
     */
    public function breakdownByHighway(Partner $partner, int $days = 7): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $since = (new \DateTimeImmutable("-{$days} days"))->format('Y-m-d H:i:s');

        $sql = '
            SELECT highway,
                   COUNT(*) AS total,
                   ROUND(AVG(severity), 1) AS avg_severity
            FROM waze_irregularities
            WHERE partner_id = :partner
              AND detection_date >= :since
            GROUP BY highway
        ';
        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery([
            'partner' => $partner->getId(),
            'since'   => $since,
        ]);
        return $result->fetchAllAssociative();
    }

    // ── Listagens ─────────────────────────────────────────────────────────────

    public function findRecentByPartner(Partner $partner, int $limit = 50): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('i.detectionDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }
}
