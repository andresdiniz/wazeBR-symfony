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

    public function countByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.partner = :p')
            ->setParameter('p', $partner)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Alertas das últimas 24h baseado em createdAt (quando foi inserido no banco).
     * Usa pubMillis para quem preferir o timestamp do Waze:
     *   ->andWhere('a.pubMillis >= :since')
     *   ->setParameter('since', (new \DateTimeImmutable('-24 hours'))->getTimestamp() * 1000)
     */
    public function countLast24hByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.partner = :p')
            ->setParameter('p', $partner)
            ->andWhere('a.createdAt >= :since')
            ->setParameter('since', new \DateTimeImmutable('-24 hours'))
            ->getQuery()->getSingleScalarResult();
    }

    public function findRecentByPartner(Partner $partner, int $limit = 10): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.partner = :p')
            ->setParameter('p', $partner)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    public function findActiveByPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.partner = :p')
            ->setParameter('p', $partner)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(500)
            ->getQuery()->getResult();
    }

    /** Contagem por tipo de alerta: [['type' => 'ACCIDENT', 'total' => 42], ...] */
    public function countGroupByType(Partner $partner): array
    {
        return $this->createQueryBuilder('a')
            ->select('a.type, COUNT(a.id) AS total')
            ->where('a.partner = :p')
            ->setParameter('p', $partner)
            ->groupBy('a.type')
            ->orderBy('total', 'DESC')
            ->getQuery()->getArrayResult();
    }

    /** Top N subtipos: [['subtype' => 'HAZARD_ON_ROAD', 'total' => 15], ...] */
    public function countGroupBySubtype(Partner $partner, int $limit = 8): array
    {
        return $this->createQueryBuilder('a')
            ->select('a.subtype, COUNT(a.id) AS total')
            ->where('a.partner = :p')
            ->setParameter('p', $partner)
            ->andWhere('a.subtype IS NOT NULL')
            ->andWhere('a.subtype != :empty')
            ->setParameter('empty', '')
            ->groupBy('a.subtype')
            ->orderBy('total', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getArrayResult();
    }

    /**
     * Alertas por hora nas últimas 24h usando pubMillis (timestamp Waze em ms).
     * Retorna array[0..23] => count, indexado pela hora UTC.
     * O template JS reordena para a hora local correta.
     */
    public function countPerHourLast24h(Partner $partner): array
    {
        $sinceMs = (new \DateTimeImmutable('-24 hours'))->getTimestamp() * 1000;

        // Usa Native SQL para extrair a hora do pubMillis (ms -> s -> hora)
        $conn = $this->getEntityManager()->getConnection();
        $sql  = '
            SELECT HOUR(FROM_UNIXTIME(pub_millis / 1000)) AS hr,
                   COUNT(*) AS total
            FROM waze_alerts
            WHERE partner_id = :pid
              AND pub_millis >= :since
            GROUP BY hr
        ';
        $rows = $conn->executeQuery($sql, [
            'pid'   => $partner->getId(),
            'since' => $sinceMs,
        ])->fetchAllAssociative();

        $map = array_fill(0, 24, 0);
        foreach ($rows as $row) {
            $map[(int) $row['hr']] = (int) $row['total'];
        }
        return $map;
    }
}
