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

    public function countByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.partner = :p')
            ->setParameter('p', $partner)
            ->getQuery()->getSingleScalarResult();
    }

    public function countLast24hByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.partner = :p')
            ->setParameter('p', $partner)
            ->andWhere('j.createdAt >= :since')
            ->setParameter('since', new \DateTimeImmutable('-24 hours'))
            ->getQuery()->getSingleScalarResult();
    }

    public function findRecentByPartner(Partner $partner, int $limit = 5): array
    {
        return $this->createQueryBuilder('j')
            ->where('j.partner = :p')
            ->setParameter('p', $partner)
            ->orderBy('j.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    public function findActiveByPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('j')
            ->where('j.partner = :p')
            ->setParameter('p', $partner)
            ->orderBy('j.createdAt', 'DESC')
            ->setMaxResults(500)
            ->getQuery()->getResult();
    }

    /** Velocidade média (km/h) dos jams do parceiro */
    public function avgSpeedKmhByPartner(Partner $partner): float
    {
        $result = $this->createQueryBuilder('j')
            ->select('AVG(j.speedKmh)')
            ->where('j.partner = :p')
            ->setParameter('p', $partner)
            ->andWhere('j.speedKmh IS NOT NULL')
            ->getQuery()->getSingleScalarResult();

        return round((float) ($result ?? 0.0), 1);
    }

    /** Atraso médio em segundos */
    public function avgDelaySecsByPartner(Partner $partner): float
    {
        $result = $this->createQueryBuilder('j')
            ->select('AVG(j.delay)')
            ->where('j.partner = :p')
            ->setParameter('p', $partner)
            ->andWhere('j.delay IS NOT NULL')
            ->getQuery()->getSingleScalarResult();

        return round((float) ($result ?? 0.0), 0);
    }

    /** Somatório do comprimento total dos jams em metros */
    public function totalLengthMByPartner(Partner $partner): int
    {
        $result = $this->createQueryBuilder('j')
            ->select('SUM(j.length)')
            ->where('j.partner = :p')
            ->setParameter('p', $partner)
            ->andWhere('j.length IS NOT NULL')
            ->getQuery()->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    /** Contagem por nível de jam: [['level' => 3, 'total' => 12], ...] */
    public function countGroupByLevel(Partner $partner): array
    {
        return $this->createQueryBuilder('j')
            ->select('j.level, COUNT(j.id) AS total')
            ->where('j.partner = :p')
            ->setParameter('p', $partner)
            ->andWhere('j.level IS NOT NULL')
            ->groupBy('j.level')
            ->orderBy('j.level', 'ASC')
            ->getQuery()->getArrayResult();
    }

    /**
     * Jams por hora nas últimas 24h usando pubMillis (timestamp Waze em ms).
     * Retorna array[0..23] => count, indexado pela hora UTC.
     */
    public function countPerHourLast24h(Partner $partner): array
    {
        $sinceMs = (new \DateTimeImmutable('-24 hours'))->getTimestamp() * 1000;

        $conn = $this->getEntityManager()->getConnection();
        $sql  = '
            SELECT HOUR(FROM_UNIXTIME(pub_millis / 1000)) AS hr,
                   COUNT(*) AS total
            FROM waze_traffic_jams
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
