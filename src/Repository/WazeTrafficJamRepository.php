<?php

namespace App\Repository;

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

    /**
     * Retorna agregacao de jams por hora (ultimas 24h)
     */
    public function getJamsPerHourLast24h(): array
    {
        $now = time() * 1000;
        $lastDay = $now - 24 * 3600 * 1000;

        $qb = $this->createQueryBuilder('j')
            ->select("CONCAT(DATE_FORMAT(FROM_UNIXTIME(j.pubMillis/1000), '%H'), 'h') AS hour_label, COUNT(j.id) AS total")
            ->where('j.pubMillis >= :lastDay')
            ->setParameter('lastDay', $lastDay)
            ->groupBy('hour_label')
            ->orderBy('hour_label', 'ASC');

        return $qb->getQuery()->getArrayResult();
    }
}
