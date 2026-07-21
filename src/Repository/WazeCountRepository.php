<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\WazeCount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WazeCount>
 */
class WazeCountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeCount::class);
    }

    public function findLatest(Partner $partner): ?WazeCount
    {
        return $this->createQueryBuilder('c')
            ->where('c.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('c.collectedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findRecent(Partner $partner, int $limit = 24): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('c.collectedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * Pico do dia — máximo de cada nível de wazers desde meia-noite.
     * Colunas reais: wazers_level_0..4, wazers_total.
     * Retorna array com max_level0..4 e max_total.
     */
    public function peakOfDay(Partner $partner): array
    {
        $since = (new \DateTimeImmutable('today'))->format('Y-m-d H:i:s');

        $sql = '
            SELECT
                MAX(wazers_level_0) AS max_level0,
                MAX(wazers_level_1) AS max_level1,
                MAX(wazers_level_2) AS max_level2,
                MAX(wazers_level_3) AS max_level3,
                MAX(wazers_level_4) AS max_level4,
                MAX(wazers_total)   AS max_total
            FROM waze_counts
            WHERE partner_id = :partner
              AND collected_at >= :since
        ';

        $row = $this->getEntityManager()
            ->getConnection()
            ->executeQuery($sql, ['partner' => $partner->getId(), 'since' => $since])
            ->fetchAssociative();

        return [
            'max_level0' => $row['max_level0'] !== null ? (float) $row['max_level0'] : null,
            'max_level1' => $row['max_level1'] !== null ? (float) $row['max_level1'] : null,
            'max_level2' => $row['max_level2'] !== null ? (float) $row['max_level2'] : null,
            'max_level3' => $row['max_level3'] !== null ? (float) $row['max_level3'] : null,
            'max_level4' => $row['max_level4'] !== null ? (float) $row['max_level4'] : null,
            'max_total'  => $row['max_total']  !== null ? (float) $row['max_total']  : null,
        ];
    }

    public function findSameTimeLastWeek(Partner $partner): ?WazeCount
    {
        $target     = new \DateTimeImmutable('-7 days');
        $windowFrom = (clone $target)->modify('-30 minutes');
        $windowTo   = (clone $target)->modify('+30 minutes');

        return $this->createQueryBuilder('c')
            ->where('c.partner = :partner')
            ->andWhere('c.collectedAt BETWEEN :from AND :to')
            ->setParameter('partner', $partner)
            ->setParameter('from', $windowFrom)
            ->setParameter('to', $windowTo)
            ->orderBy('c.collectedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }
}
