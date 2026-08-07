<?php

namespace App\Repository;

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

    /**
     * Retorna agregacao de alertas por hora (ultimas 24h)
     * Formato: [ ['hour_label' => '10h', 'total' => 3], ... ]
     */
    public function getAlertsPerHourLast24h(): array
    {
        $now = time() * 1000;
        $lastDay = $now - 24 * 3600 * 1000;

        $qb = $this->createQueryBuilder('a')
            ->select("CONCAT(DATE_FORMAT(FROM_UNIXTIME(a.pubMillis/1000), '%H'), 'h') AS hour_label, COUNT(a.id) AS total")
            ->where('a.pubMillis >= :lastDay')
            ->setParameter('lastDay', $lastDay)
            ->groupBy('hour_label')
            ->orderBy('hour_label', 'ASC');

        return $qb->getQuery()->getArrayResult();
    }
}
