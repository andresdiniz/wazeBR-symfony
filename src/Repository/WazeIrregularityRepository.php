<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\WazeIrregularity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WazeIrregularity>
 *
 * Campos reais da entidade WazeIrregularity:
 *   id, wazeId, partner, sourceLink, name, fromName, toName,
 *   length, time, historicTime, jamLevel, avgSpeed, historicSpeed,
 *   bbox, line, isActive, collectedAt,
 *   leadAlert* (id, type, subType, position, numComments, city,
 *               externalImageId, numThumbsUp, street, numNotThereReports)
 */
class WazeIrregularityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeIrregularity::class);
    }

    // ── Contagens ─────────────────────────────────────────────────────────────

    public function countByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.partner = :partner')
            ->setParameter('partner', $partner)
            ->getQuery()->getSingleScalarResult();
    }

    public function countActiveByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.partner = :partner')
            ->andWhere('i.isActive = true')
            ->setParameter('partner', $partner)
            ->getQuery()->getSingleScalarResult();
    }

    // ── KPIs ──────────────────────────────────────────────────────────────────

    /**
     * Irregularidades "piorando" = ativas onde tempo atual > tempo histórico
     * (a via está mais lenta que o histórico), ordenadas pelo maior atraso relativo.
     */
    public function findWorseningByPartner(Partner $partner, int $limit = 20): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.partner = :partner')
            ->andWhere('i.isActive = true')
            ->andWhere('i.time IS NOT NULL')
            ->andWhere('i.historicTime IS NOT NULL')
            ->andWhere('i.time > i.historicTime')
            ->setParameter('partner', $partner)
            ->orderBy('i.jamLevel', 'DESC')
            ->addOrderBy('i.time', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * Ranking de piora de velocidade por via (fromName/toName).
     * Calcula (historicTime - time) como delta de atraso.
     * Retorna [['name'=>'...','from_name'=>'...','to_name'=>'...','delay_s'=>120,'jam_level'=>3], ...]
     */
    public function speedLossRankingByStreet(Partner $partner, int $hours = 24, int $limit = 10): array
    {
        $conn  = $this->getEntityManager()->getConnection();
        $since = (new \DateTimeImmutable("-{$hours} hours"))->format('Y-m-d H:i:s');

        $sql = '
            SELECT
                name,
                from_name,
                to_name,
                ROUND(AVG(time - historic_time), 0)          AS avg_delay_s,
                ROUND(AVG(avg_speed), 1)                     AS avg_speed,
                ROUND(AVG(historic_speed), 1)                AS avg_historic_speed,
                ROUND(AVG(jam_level), 1)                     AS avg_jam_level,
                COUNT(*)                                     AS occurrences
            FROM waze_irregularities
            WHERE partner_id = :partner
              AND collected_at >= :since
              AND time > historic_time
              AND name IS NOT NULL
            GROUP BY name, from_name, to_name
            ORDER BY avg_delay_s DESC
            LIMIT :lim
        ';

        return $this->getEntityManager()->getConnection()
            ->executeQuery($sql, [
                'partner' => $partner->getId(),
                'since'   => $since,
                'lim'     => $limit,
            ])->fetchAllAssociative();
    }

    /**
     * Atraso acumulado total (segundos) por via nas últimas $hours horas.
     * Retorna [['name'=>'...','total_delay_s'=>840,'occurrences'=>6], ...]
     */
    public function accumulatedDelayByStreet(Partner $partner, int $hours = 24, int $limit = 10): array
    {
        $conn  = $this->getEntityManager()->getConnection();
        $since = (new \DateTimeImmutable("-{$hours} hours"))->format('Y-m-d H:i:s');

        $sql = '
            SELECT
                name,
                from_name,
                to_name,
                SUM(time - COALESCE(historic_time, 0)) AS total_delay_s,
                COUNT(*)                               AS occurrences
            FROM waze_irregularities
            WHERE partner_id = :partner
              AND collected_at >= :since
              AND name IS NOT NULL
              AND historic_time IS NOT NULL
            GROUP BY name, from_name, to_name
            ORDER BY total_delay_s DESC
            LIMIT :lim
        ';

        return $this->getEntityManager()->getConnection()
            ->executeQuery($sql, [
                'partner' => $partner->getId(),
                'since'   => $since,
                'lim'     => $limit,
            ])->fetchAllAssociative();
    }

    /**
     * Distribuição por jamLevel (0=livre .. 4=parado).
     * Retorna [['jam_level'=>3,'total'=>12,'avg_time'=>180], ...]
     */
    public function breakdownByJamLevel(Partner $partner, int $days = 7): array
    {
        $conn  = $this->getEntityManager()->getConnection();
        $since = (new \DateTimeImmutable("-{$days} days"))->format('Y-m-d H:i:s');

        $sql = '
            SELECT
                jam_level,
                COUNT(*)           AS total,
                ROUND(AVG(time),0) AS avg_time_s
            FROM waze_irregularities
            WHERE partner_id = :partner
              AND collected_at >= :since
            GROUP BY jam_level
            ORDER BY jam_level ASC
        ';

        return $this->getEntityManager()->getConnection()
            ->executeQuery($sql, [
                'partner' => $partner->getId(),
                'since'   => $since,
            ])->fetchAllAssociative();
    }

    // ── Listagens ─────────────────────────────────────────────────────────────

    /**
     * Irregularidades ativas mais recentes.
     */
    public function findRecentByPartner(Partner $partner, int $limit = 50): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('i.collectedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    public function findActiveByPartner(Partner $partner, int $limit = 50): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.partner = :partner')
            ->andWhere('i.isActive = true')
            ->setParameter('partner', $partner)
            ->orderBy('i.jamLevel', 'DESC')
            ->addOrderBy('i.collectedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }
}
