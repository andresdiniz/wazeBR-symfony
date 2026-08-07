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

    public function getJamsPerHourLast24h(): array
    {
        $now = time() * 1000;
        $lastDay = $now - 24 * 3600 * 1000;

        $conn = $this->getEntityManager()->getConnection();

        $sql = <<<SQL
            SELECT
                CONCAT(DATE_FORMAT(FROM_UNIXTIME(j.pub_millis / 1000), '%H'), 'h') AS hour_label,
                COUNT(j.id) AS total
            FROM waze_traffic_jams j
            WHERE j.pub_millis >= :lastDay
            GROUP BY hour_label
            ORDER BY hour_label ASC
        SQL;

        return $conn->fetchAllAssociative($sql, [
            'lastDay' => $lastDay,
        ]);
    }
}
